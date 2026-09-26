"""Háttérfeladatok: PostgreSQL alapú sor (SELECT … FOR UPDATE SKIP LOCKED), Redis nélkül.

A feladattípusokat a @handler("típus") dekorátor regisztrálja. A worker:  python -m app.jobs.worker
Tesztben (SEO_OS_JOBS_INLINE=1) a feladat a kérésen belül, azonnal lefut.
"""

import logging
import traceback
from collections.abc import Callable
from datetime import timedelta
from typing import Any

from sqlalchemy import select, text
from sqlalchemy.orm import Session

from ..config import get_settings
from ..db import session_factory, utcnow
from ..models import Job

log = logging.getLogger("seo_os.jobs")

HANDLERS: dict[str, Callable[[Session, Job, "Progress"], dict[str, Any] | None]] = {}


class JobError(Exception):
    """Felhasználónak szóló hibaüzenet (a nyomkövetés nélkül jelenik meg)."""


class Progress:
    def __init__(self, db: Session, job: Job):
        self.db, self.job = db, job

    def __call__(self, fraction: float, message: str = "") -> None:
        # Külön kapcsolaton, hogy a felület a futás közben is lássa. A fő munkamenet a feladat sorát
        # futás közben nem írja (különben a két kapcsolat egymásra várna).
        with session_factory()() as s:
            s.execute(
                text("UPDATE seo.jobs SET progress=:p, message=:m WHERE id=:id"),
                {"p": max(0.0, min(1.0, fraction)), "m": message, "id": self.job.id},
            )
            s.commit()


def handler(name: str):
    def deco(fn):
        HANDLERS[name] = fn
        return fn

    return deco


def enqueue(db: Session, type_: str, project_id: int | None, payload: dict, user_id: int | None) -> Job:
    if type_ not in HANDLERS:
        raise ValueError(f"Ismeretlen feladattípus: {type_}")
    job = Job(type=type_, project_id=project_id, payload=payload, created_by=user_id or None)
    db.add(job)
    db.commit()
    if get_settings().jobs_inline:
        run(job.id)
        db.refresh(job)
    return job


def run(job_id: int) -> None:
    """Egy feladat futtatása saját munkamenetben."""
    with session_factory()() as db:
        job = db.get(Job, job_id)
        if job is None or job.status not in ("queued", "running"):
            return
        job.status, job.started_at, job.attempts = "running", utcnow(), job.attempts + 1
        db.commit()
        try:
            result = HANDLERS[job.type](db, job, Progress(db, job)) or {}
            db.commit()
            job.status, job.result, job.progress = "done", result, 1
        except JobError as e:
            db.rollback()
            job = db.get(Job, job_id)
            job.status, job.error = "failed", str(e)
        except Exception as e:  # noqa: BLE001 – a feladat hibája nem döntheti le a workert
            db.rollback()
            log.error("job %s failed: %s", job_id, traceback.format_exc())
            job = db.get(Job, job_id)
            job.status, job.error = "failed", f"Váratlan hiba: {type(e).__name__}: {e}"[:2000]
        job.finished_at = utcnow()
        db.commit()


def claim_next() -> int | None:
    with session_factory()() as db:
        row = db.execute(
            text(
                "SELECT id FROM seo.jobs WHERE status='queued' ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 1"
            )
        ).first()
        if row is None:
            return None
        db.execute(text("UPDATE seo.jobs SET status='running', started_at=now() WHERE id=:id"), {"id": row[0]})
        db.commit()
        return row[0]


def requeue_stale(minutes: int = 30) -> int:
    """Worker-újraindítás után: a régóta „futó” feladatok vissza a sorba (legfeljebb 3 próbálkozás)."""
    with session_factory()() as db:
        stale = db.scalars(
            select(Job).where(Job.status == "running", Job.started_at < utcnow() - timedelta(minutes=minutes))
        ).all()
        for job in stale:
            if job.attempts >= 3:
                job.status, job.error, job.finished_at = "failed", "A feladat többször megszakadt.", utcnow()
            else:
                job.status = "queued"
        db.commit()
        return len(stale)
