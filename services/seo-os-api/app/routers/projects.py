"""Projektek, bekérés, versenytársak, kiinduló kulcsszavak, státuszváltás."""

from typing import Optional

from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy import or_, select
from sqlalchemy.orm import Session

from ..auth import CurrentUser, current_user, need
from ..db import get_db, utcnow
from ..domain import INTAKE_ITEMS, STATUSES
from ..models import ActivityLog, Project, ProjectMember, StatusHistory, User
from ..schemas.core import (
    CompetitorIn,
    IntakePatch,
    MemberIn,
    ProjectCreate,
    ProjectOut,
    ProjectPatch,
    ProjectSummary,
    SeedIn,
    TransitionIn,
)
from ..services import workflow
from ..services.activity import changes, log
from ..services.projects import get_project, replace_competitors, replace_seeds, resolve_client, sync_intake, upsell_date

router = APIRouter(prefix="/projects", tags=["projects"])


def project_payload(db: Session, project: Project, user: CurrentUser) -> dict:
    data = ProjectOut.model_validate(project).model_dump(mode="json")
    data["allowed_transitions"] = workflow.allowed_targets(project, user)
    data["members"] = [
        {"user_id": m.user_id, "project_role": m.project_role, "name": m.user.display_name, "role": m.user.role}
        for m in project.members
    ]
    return data


@router.get("")
def list_projects(
    status: Optional[str] = None,
    owner: Optional[int] = None,
    q: Optional[str] = None,
    archived: bool = False,
    db: Session = Depends(get_db),
    user: CurrentUser = Depends(need("project.view")),
):
    stmt = select(Project).order_by(Project.updated_at.desc())
    stmt = stmt.where(Project.archived_at.is_not(None) if archived else Project.archived_at.is_(None))
    if status:
        stmt = stmt.where(Project.status == status)
    if owner:
        stmt = stmt.where(Project.owner_id == owner)
    if q:
        like = f"%{q.strip()}%"
        stmt = stmt.where(or_(Project.name.ilike(like), Project.domain.ilike(like)))
    rows = db.scalars(stmt).unique().all()
    return [ProjectSummary.model_validate(p).model_dump(mode="json") for p in rows]


@router.post("", status_code=201)
def create_project(body: ProjectCreate, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.create"))):
    client = resolve_client(db, body.client_id, body.client)
    data = body.model_dump(exclude={"client_id", "client", "competitors", "seed_keywords"}, exclude_none=True)
    if data.get("business_services") is not None:
        data["business_services"] = [s for s in data["business_services"]]
    project = Project(client=client, **data)
    if project.owner_id is None and user.id:
        project.owner_id = user.id
    db.add(project)
    replace_competitors(project, body.competitors)
    replace_seeds(project, body.seed_keywords)
    sync_intake(project)
    project.upsell_reminder_at = upsell_date(project)
    db.flush()
    if user.id:
        project.members.append(ProjectMember(user_id=user.id, project_role="owner"))
    db.add(StatusHistory(project_id=project.id, from_status=None, to_status=project.status, user_id=user.id or None))
    log(db, project.id, user.id, "project", project.id, "create", f"Projekt létrehozva: {project.name}")
    db.commit()
    db.refresh(project)
    return project_payload(db, project, user)


@router.get("/{project_id}")
def read_project(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    return project_payload(db, get_project(db, project_id), user)


@router.patch("/{project_id}")
def update_project(
    project_id: int, body: ProjectPatch, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.edit"))
):
    project = get_project(db, project_id)
    data = body.model_dump(exclude_unset=True)
    if "client_id" in data:
        if data["client_id"] is None:
            raise HTTPException(422, "Ügyfél megadása kötelező.")
        resolve_client(db, data["client_id"], None)
    if data.get("owner_id") is not None and db.get(User, data["owner_id"]) is None:
        raise HTTPException(422, "Ismeretlen felelős.")
    for key in ("name", "domain"):
        if key in data and data[key] is None:
            raise HTTPException(422, "Kötelező mező.")
    diff = changes(project, data)
    if {"scope", "start_date", "strategy_months"} & set(diff):
        sync_intake(project)
        project.upsell_reminder_at = upsell_date(project)
    if diff:
        log(db, project.id, user.id, "project", project.id, "update", "Projektadatok módosítva", diff)
    db.commit()
    db.refresh(project)
    return project_payload(db, project, user)


@router.post("/{project_id}/archive")
def archive_project(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.edit"))):
    project = get_project(db, project_id)
    project.archived_at = None if project.archived_at else utcnow()
    log(db, project.id, user.id, "project", project.id, "archive" if project.archived_at else "unarchive")
    db.commit()
    return {"archived": project.archived_at is not None}


@router.put("/{project_id}/competitors")
def put_competitors(
    project_id: int, body: list[CompetitorIn], db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.edit"))
):
    project = get_project(db, project_id)
    before = sorted(c.domain for c in project.competitors)
    replace_competitors(project, body)
    after = sorted(c.domain for c in project.competitors)
    if before != after:
        log(db, project.id, user.id, "project", project.id, "competitors", "Versenytársak módosítva", {"competitors": [before, after]})
    db.commit()
    db.refresh(project)
    return project_payload(db, project, user)["competitors"]


@router.put("/{project_id}/seed-keywords")
def put_seeds(
    project_id: int, body: list[SeedIn], db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.edit"))
):
    project = get_project(db, project_id)
    replace_seeds(project, body)
    log(db, project.id, user.id, "project", project.id, "seed_keywords", f"Kiinduló kulcsszavak: {len(body)} db")
    db.commit()
    db.refresh(project)
    return project_payload(db, project, user)["seed_keywords"]


@router.patch("/{project_id}/intake/{key}")
def patch_intake(
    project_id: int,
    key: str,
    body: IntakePatch,
    db: Session = Depends(get_db),
    user: CurrentUser = Depends(need("project.edit")),
):
    project = get_project(db, project_id)
    item = next((i for i in project.intake_items if i.key == key), None)
    if item is None or key not in INTAKE_ITEMS:
        raise HTTPException(404, "Ismeretlen bekérési tétel.")
    diff = changes(item, body.model_dump(exclude_unset=True, exclude_none=True))
    if diff:
        item.updated_by = user.id or None
        log(db, project.id, user.id, "intake", item.id, "update", INTAKE_ITEMS[key][0], diff)
    db.commit()
    return {"key": item.key, "status": item.status, "note": item.note}


@router.put("/{project_id}/members")
def put_members(
    project_id: int, body: list[MemberIn], db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.edit"))
):
    project = get_project(db, project_id)
    ids = {m.user_id for m in body}
    known = set(db.scalars(select(User.id).where(User.id.in_(ids))).all()) if ids else set()
    if ids - known:
        raise HTTPException(422, "Ismeretlen felhasználó.")
    project.members = [ProjectMember(user_id=m.user_id, project_role=m.project_role) for m in body]
    log(db, project.id, user.id, "project", project.id, "members", "Csapat módosítva")
    db.commit()
    db.refresh(project)
    return project_payload(db, project, user)["members"]


@router.post("/{project_id}/transition")
def post_transition(
    project_id: int, body: TransitionIn, db: Session = Depends(get_db), user: CurrentUser = Depends(current_user)
):
    project = get_project(db, project_id)
    workflow.transition(db, project, body.to, user, body.note, body.force)
    db.commit()
    db.refresh(project)
    return project_payload(db, project, user)


@router.get("/{project_id}/gates")
def get_gates(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    """A következő státusz feltételei (a felület a gomb mellé írja ki, mi hiányzik)."""
    project = get_project(db, project_id)
    return {to: workflow.check_gates(db, project, to) for to in workflow.allowed_targets(project, user)}


@router.get("/{project_id}/history")
def get_history(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    get_project(db, project_id)
    statuses = db.scalars(
        select(StatusHistory).where(StatusHistory.project_id == project_id).order_by(StatusHistory.at.desc())
    ).all()
    activity = db.scalars(
        select(ActivityLog).where(ActivityLog.project_id == project_id).order_by(ActivityLog.at.desc()).limit(200)
    ).all()
    return {
        "statuses": [
            {
                "from": s.from_status,
                "to": s.to_status,
                "to_label": STATUSES.get(s.to_status, s.to_status),
                "note": s.note,
                "at": s.at.isoformat(),
                "user": s.user.display_name if s.user else "",
            }
            for s in statuses
        ],
        "activity": [
            {
                "entity": a.entity,
                "action": a.action,
                "summary": a.summary,
                "diff": a.diff,
                "at": a.at.isoformat(),
                "user": a.user.display_name if a.user else "",
            }
            for a in activity
        ],
    }
