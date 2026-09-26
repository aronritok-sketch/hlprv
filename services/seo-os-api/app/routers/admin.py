"""Super admin: rendszerállapot és élesítési ellenőrzőlista, hibás feladatok újraindítása, demó projekt."""

from datetime import date, timedelta

from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session

from ..auth import CurrentUser, need
from ..db import get_db
from ..jobs import runner
from ..models import Client, Job, Project, ProjectMember, StatusHistory
from ..services import demo, system
from ..services.activity import log
from ..services.projects import sync_intake, upsell_date
from .research import job_payload

router = APIRouter(prefix="/admin", tags=["admin"])


@router.get("/status")
def get_status(db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))):
    return system.status(db)


@router.post("/jobs/{job_id}/retry")
def retry_job(job_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))):
    job = db.get(Job, job_id)
    if job is None:
        raise HTTPException(404, "Nem található.")
    if job.status != "failed":
        raise HTTPException(409, "Csak hibás feladat indítható újra.")
    job.status, job.error, job.progress, job.message, job.attempts = "queued", "", 0, "", 0
    db.commit()
    log(db, job.project_id, user.id, "job", job.id, "retry", job.type)
    db.commit()
    return job_payload(job)


@router.post("/demo", status_code=201)
def create_demo(db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))):
    """Demó projekt kitalált adatokkal – API-kulcs nélkül is végigkattintható minden fül."""
    client = Client(name="DEMÓ ügyfél")
    db.add(client)
    start = (date.today().replace(day=28) + timedelta(days=4)).replace(day=1)  # jövő hónap eleje
    project = Project(client=client, owner_id=user.id or None, start_date=start, **demo.PROJECT)
    db.add(project)
    sync_intake(project)
    project.upsell_reminder_at = upsell_date(project)
    db.flush()
    if user.id:
        project.members.append(ProjectMember(user_id=user.id, project_role="owner"))
    db.add(StatusHistory(project_id=project.id, from_status=None, to_status=project.status, user_id=user.id or None))
    log(db, project.id, user.id, "project", project.id, "create", "Demó projekt")
    db.commit()
    job = runner.enqueue(db, "demo_project", project.id, {}, user.id)
    return {"project_id": project.id, "job": job_payload(job)}
