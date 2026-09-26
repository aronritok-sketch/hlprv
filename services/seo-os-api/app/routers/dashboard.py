"""Vezérlőpult: aktív projektek, rám váró tételek, hiányzó bekérések, emlékeztetők.

A későbbi ütemek a WIDGETS listába írják a saját dobozukat (dokumentumok, jóváhagyások, roadmap).
"""

from collections.abc import Callable
from datetime import date, timedelta

from fastapi import APIRouter, Depends
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from ..auth import CurrentUser, need
from ..db import get_db
from ..domain import INTAKE_ITEMS, STATUS_ORDER
from ..models import IntakeItem, Project

router = APIRouter()

WIDGETS: list[Callable[[Session, CurrentUser], dict]] = []


def widget(fn):
    WIDGETS.append(fn)
    return fn


@router.get("/dashboard")
def dashboard(db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    projects = db.scalars(
        select(Project)
        .where(Project.archived_at.is_(None), Project.status != "completed")
        .order_by(Project.updated_at.desc())
    ).unique().all()
    missing = dict(
        db.execute(
            select(IntakeItem.project_id, func.count())
            .where(IntakeItem.status.in_(["missing", "requested"]))
            .group_by(IntakeItem.project_id)
        ).all()
    )
    missing_items = db.execute(
        select(Project.id, Project.name, IntakeItem.key, IntakeItem.status)
        .join(IntakeItem, IntakeItem.project_id == Project.id)
        .where(Project.archived_at.is_(None), Project.status != "completed", IntakeItem.status.in_(["missing", "requested"]))
        .order_by(Project.name)
    ).all()
    soon = date.today() + timedelta(days=14)
    upsell = [
        {"project_id": p.id, "name": p.name, "date": p.upsell_reminder_at.isoformat()}
        for p in projects
        if p.upsell_reminder_at and p.upsell_reminder_at <= soon
    ]
    out = {
        "projects": [
            {
                "id": p.id,
                "name": p.name,
                "client": p.client.name,
                "domain": p.domain,
                "status": p.status,
                "on_hold": p.on_hold,
                "progress": round(STATUS_ORDER.index(p.status) / (len(STATUS_ORDER) - 1), 3),
                "owner": p.owner.display_name if p.owner else "",
                "missing_intake": missing.get(p.id, 0),
                "updated_at": p.updated_at.isoformat(),
            }
            for p in projects
        ],
        "counts": {s: sum(1 for p in projects if p.status == s) for s in STATUS_ORDER},
        "missing_intake": [
            {"project_id": pid, "project": name, "key": key, "label": INTAKE_ITEMS[key][0], "status": st}
            for pid, name, key, st in missing_items
        ],
        "upsell": upsell,
    }
    for fn in WIDGETS:
        out.update(fn(db, user))
    return out
