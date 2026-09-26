"""Együttműködés: értesítések, @említések, jóváhagyási folyamat (belső és ügyfél), napi emlékeztetők.

Az értesítés a felületen jelenik meg (csengő), e-mailben pedig a WordPress küldi ki: a bővítmény ütemezett
feladata elkéri a még ki nem küldött értesítéseket (outbox), és wp_mail()-lel – a CRM levélsablonjával – továbbítja.
"""

import re
import secrets
from datetime import date, timedelta
from typing import Any, Iterable, Optional

from sqlalchemy import select
from sqlalchemy.orm import Session

from ..db import utcnow
from ..models import (
    DOC_TYPES,
    AuditFinding,
    Comment,
    Document,
    Notification,
    Page,
    ProductionTask,
    Project,
    RoadmapItem,
    User,
    Wireframe,
)
from ..permissions import has


# ── Tárgy (mire vonatkozik) ──────────────────────────────


def subject_info(db: Session, subject_type: str, subject_id: int) -> Optional[dict[str, Any]]:
    """{project_id, title, link, people: [user_id]} – vagy None, ha nem létezik."""
    if subject_type == "project":
        p = db.get(Project, subject_id)
        return p and {"project_id": p.id, "title": p.name, "link": f"#/projects/{p.id}", "people": [p.owner_id]}
    if subject_type == "document":
        d = db.get(Document, subject_id)
        return d and {"project_id": d.project_id, "title": f"{DOC_TYPES[d.doc_type][0]} v{d.version}",
                      "link": f"#/projects/{d.project_id}?tab=documents&doc={d.id}", "people": [d.created_by], "object": d}
    if subject_type == "wireframe":
        w = db.get(Wireframe, subject_id)
        if not w:
            return None
        page = db.get(Page, w.page_id)
        return {"project_id": w.project_id, "title": f"Wireframe: {page.url if page else w.label}",
                "link": f"#/projects/{w.project_id}?tab=wireframes&wf={w.id}", "people": [w.approved_by], "object": w}
    if subject_type == "page":
        pg = db.get(Page, subject_id)
        return pg and {"project_id": pg.project_id, "title": f"Oldal: {pg.url}", "link": f"#/projects/{pg.project_id}?tab=structure&page={pg.id}", "people": []}
    if subject_type == "roadmap_item":
        r = db.get(RoadmapItem, subject_id)
        return r and {"project_id": r.project_id, "title": r.title, "link": f"#/projects/{r.project_id}?tab=strategy", "people": [r.assignee_id]}
    if subject_type == "finding":
        f = db.get(AuditFinding, subject_id)
        return f and {"project_id": f.project_id, "title": f"Audit: {f.issue_key}", "link": f"#/projects/{f.project_id}?tab=audit&finding={f.id}", "people": []}
    if subject_type == "task":
        t = db.get(ProductionTask, subject_id)
        return t and {"project_id": t.project_id, "title": t.title, "link": f"#/projects/{t.project_id}?tab=documents&sub=tasks", "people": [t.assignee_id]}
    return None


# ── Értesítések ──────────────────────────────────────────


def notify(db: Session, user_ids: Iterable[Optional[int]], kind: str, title: str, *, body: str = "", link: str = "",
           project_id: Optional[int] = None, exclude: Optional[int] = None, data: Optional[dict] = None) -> int:
    ids = {u for u in user_ids if u and u != exclude}
    if not ids:
        return 0
    active = set(db.scalars(select(User.id).where(User.id.in_(ids), User.is_active.is_(True))).all())
    for uid in active:
        db.add(Notification(user_id=uid, project_id=project_id, kind=kind, title=title[:512], body=body, link=link, data=data or {}))
    db.flush()
    return len(active)


def users_with(db: Session, cap: str) -> list[int]:
    return [u.id for u in db.scalars(select(User).where(User.is_active.is_(True))).all() if has(u.role, cap)]


def mentioned(db: Session, body: str) -> list[int]:
    """@Teljes Név vagy @keresztnév – a felhasználók megjelenített neve alapján."""
    if "@" not in body:
        return []
    low = body.lower()
    out = []
    for u in db.scalars(select(User).where(User.is_active.is_(True))).all():
        name = (u.display_name or "").strip().lower()
        first = name.split(" ")[0] if name else ""
        if name and (f"@{name}" in low or (first and re.search(rf"@{re.escape(first)}\b", low))):
            out.append(u.id)
    return out


def comment_notify(db: Session, c: Comment, info: dict, author_id: Optional[int]) -> None:
    ment = mentioned(db, c.body)
    c.mentions = ment
    who = c.author_name or "Valaki"
    notify(db, ment, "mention", f"{who} megemlített: {info['title']}", body=c.body[:500], link=info["link"], project_id=c.project_id, exclude=author_id)
    earlier = db.scalars(select(Comment.author_id).where(Comment.subject_type == c.subject_type, Comment.subject_id == c.subject_id, Comment.id != c.id)).all()
    others = (set(info.get("people") or []) | set(earlier)) - set(ment)
    if c.is_client:
        # Ügyfél-megjegyzés: a projekt felelőse és a SEO managerek is kapják.
        p = db.get(Project, c.project_id)
        others |= {p.owner_id} if p else set()
    notify(db, others, "comment", f"{'Ügyfél' if c.is_client else who} megjegyzést írt: {info['title']}", body=c.body[:500],
           link=info["link"], project_id=c.project_id, exclude=author_id)


# ── Jóváhagyás ───────────────────────────────────────────


def new_token() -> str:
    return secrets.token_urlsafe(32)


def apply_decision(db: Session, subject_type: str, obj: Any, stage: str, decision: str, user_id: Optional[int]) -> None:
    """A döntés hatása a tárgyra (dokumentum / wireframe állapota)."""
    if subject_type == "document" and obj is not None:
        if stage == "internal":
            if decision == "approved":
                obj.status, obj.approved_by, obj.approved_at = "approved", user_id, utcnow()
            else:
                obj.status = "draft"
    elif subject_type == "wireframe" and obj is not None and stage == "internal":
        if decision == "approved":
            obj.status, obj.approved_by, obj.approved_at = "approved", user_id, utcnow()
        else:
            obj.status = "draft"


# ── Napi feladat (upsell, lejárt feladatok) ──────────────


def daily(db: Session, today: Optional[date] = None) -> dict[str, int]:
    today = today or date.today()
    out = {"upsell": 0, "overdue": 0}
    admins = users_with(db, "settings.manage")
    for p in db.scalars(select(Project).where(Project.archived_at.is_(None), Project.upsell_reminder_at.is_not(None),
                                              Project.upsell_reminder_at <= today + timedelta(days=14), Project.upsell_notified_at.is_(None))).all():
        notify(db, [p.owner_id, *admins], "upsell", f"Upsell emlékeztető: {p.name}",
               body=f"A(z) {p.domain} stratégiai időszaka a vége felé jár ({p.upsell_reminder_at:%Y.%m.%d.}). Érdemes egyeztetni a folytatásról: új tartalmi kör, linképítés, havi riport.",
               link=f"#/projects/{p.id}", project_id=p.id)
        p.upsell_notified_at = utcnow()
        out["upsell"] += 1
    for t in db.scalars(select(ProductionTask).where(ProductionTask.status != "done", ProductionTask.due_date < today,
                                                     ProductionTask.assignee_id.is_not(None), ProductionTask.overdue_notified_at.is_(None))).all():
        notify(db, [t.assignee_id], "task_overdue", f"Lejárt határidő: {t.title}", body=f"Határidő: {t.due_date:%Y.%m.%d.}",
               link=f"#/projects/{t.project_id}?tab=documents&sub=tasks", project_id=t.project_id)
        t.overdue_notified_at = utcnow()
        out["overdue"] += 1
    db.flush()
    return out
