"""Projekt-állapotgép.

Szabályok:
  - előre mindig csak egy lépés (SEO manager, admin);
  - ügyfél-ellenőrzésből vissza SEO-ellenőrzésbe, ha az ügyfél módosítást kér;
  - a content manager csak a gyártást zárhatja le (production → completed);
  - admin bármelyik státuszba léptethet, de ehhez megjegyzés kell.
A jóváhagyási feltételeket (gates) a későbbi ütemek adatai adják; a check_gates bővíthető.
"""

from collections.abc import Callable

from fastapi import HTTPException
from sqlalchemy.orm import Session

from ..auth import CurrentUser
from ..domain import STATUS_ORDER, STATUSES
from ..models import Project, StatusHistory
from .activity import log

# Feltétel-ellenőrzők: (db, project, cél státusz) → hibaüzenetek listája.
GATES: list[Callable[[Session, Project, str], list[str]]] = []


def gate(fn):
    GATES.append(fn)
    return fn


def allowed_targets(project: Project, user: CurrentUser) -> list[str]:
    current = project.status
    idx = STATUS_ORDER.index(current)
    targets: list[str] = []
    if user.role == "admin":
        return [s for s in STATUS_ORDER if s != current]
    if user.can("project.transition"):
        if idx + 1 < len(STATUS_ORDER):
            targets.append(STATUS_ORDER[idx + 1])
        if current == "client_review":
            targets.append("seo_review")
    elif user.role == "content_manager" and current == "production":
        targets.append("completed")
    return targets


def check_gates(db: Session, project: Project, to: str) -> list[str]:
    problems: list[str] = []
    for fn in GATES:
        problems.extend(fn(db, project, to))
    return problems


def transition(db: Session, project: Project, to: str, user: CurrentUser, note: str = "", force: bool = False) -> None:
    if to not in STATUSES:
        raise HTTPException(422, "Ismeretlen státusz.")
    if project.archived_at is not None:
        raise HTTPException(409, "Archivált projekt státusza nem változtatható.")
    if to not in allowed_targets(project, user):
        raise HTTPException(403, "Ez a státuszváltás nem engedélyezett számodra.")
    idx_from, idx_to = STATUS_ORDER.index(project.status), STATUS_ORDER.index(to)
    is_regular = idx_to == idx_from + 1 or (project.status == "client_review" and to == "seo_review")
    if not is_regular and not note.strip():
        raise HTTPException(422, "Rendhagyó státuszváltáshoz megjegyzés kell.")
    problems = check_gates(db, project, to) if idx_to > idx_from else []
    if problems and not (force and user.role == "admin"):
        raise HTTPException(409, {"message": "A projekt még nem léptethető tovább.", "problems": problems})
    old = project.status
    project.status = to
    db.add(StatusHistory(project_id=project.id, from_status=old, to_status=to, user_id=user.id or None, note=note))
    log(db, project.id, user.id, "project", project.id, "status", f"{STATUSES[old]} → {STATUSES[to]}", {"status": [old, to]})
