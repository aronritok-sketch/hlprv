"""6. ütem végpontjai: megjegyzések, jóváhagyások (belső és ügyfél), ügyfél-jóváhagyó oldal adatai, értesítések,
valamint a WordPress rendszerhívásai (e-mail outbox, napi emlékeztetők)."""

from datetime import timedelta
from typing import Any, Literal, Optional

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from .. import meta
from ..auth import CurrentUser, current_system, need
from ..db import get_db, utcnow
from ..models import (
    APPROVAL_STAGES,
    APPROVAL_STATUSES,
    DOC_TYPES,
    NOTIFICATION_KINDS,
    SUBJECTS,
    Approval,
    Comment,
    Document,
    Notification,
    Project,
    StoredFile,
    User,
)
from ..permissions import can_view_doc, has
from ..services import collab, storage
from ..services.activity import log
from ..services.system import heartbeat
from ..services.files import file_response
from . import dashboard

router = APIRouter(tags=["collab"])

meta.extend("subjects", SUBJECTS)
meta.extend("approval_stages", APPROVAL_STAGES)
meta.extend("approval_statuses", APPROVAL_STATUSES)
meta.extend("notification_kinds", NOTIFICATION_KINDS)

CLIENT_REVIEW_DAYS = 45


def _subject(db: Session, subject_type: str, subject_id: int, user: Optional[CurrentUser] = None) -> dict:
    if subject_type not in SUBJECTS:
        raise HTTPException(422, "Ismeretlen tárgy.")
    info = collab.subject_info(db, subject_type, subject_id)
    if info is None:
        raise HTTPException(404, "Nem található.")
    if user and subject_type == "document" and not can_view_doc(user.role, info["object"].doc_type):
        raise HTTPException(403, "Ezt a dokumentumot nem láthatod.")
    return info


def _names(db: Session) -> dict[int, str]:
    return dict(db.execute(select(User.id, User.display_name)).all())


# ── Megjegyzések ─────────────────────────────────────────


def comment_payload(c: Comment, names: dict[int, str], me: Optional[int] = None) -> dict[str, Any]:
    return {
        "id": c.id, "parent_id": c.parent_id, "subject_type": c.subject_type, "subject_id": c.subject_id,
        "author_id": c.author_id, "author": c.author_name or names.get(c.author_id, ""), "is_client": c.is_client,
        "body": c.body, "resolved": c.resolved, "mentions": c.mentions, "mine": bool(me and c.author_id == me),
        "created_at": c.created_at.isoformat(), "edited_at": c.edited_at.isoformat() if c.edited_at else None,
    }


@router.get("/comments")
def list_comments(subject_type: str, subject_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    _subject(db, subject_type, subject_id, user)
    rows = db.scalars(select(Comment).where(Comment.subject_type == subject_type, Comment.subject_id == subject_id).order_by(Comment.id)).all()
    names = _names(db)
    return [comment_payload(c, names, user.id) for c in rows]


class CommentIn(BaseModel):
    subject_type: str
    subject_id: int
    body: str = Field(min_length=1, max_length=5000)
    parent_id: Optional[int] = None


@router.post("/comments", status_code=201)
def add_comment(body: CommentIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("comments.write"))):
    info = _subject(db, body.subject_type, body.subject_id, user)
    if body.parent_id:
        parent = db.get(Comment, body.parent_id)
        if parent is None or parent.subject_type != body.subject_type or parent.subject_id != body.subject_id:
            raise HTTPException(422, "Hibás válasz-hivatkozás.")
    c = Comment(project_id=info["project_id"], subject_type=body.subject_type, subject_id=body.subject_id, parent_id=body.parent_id,
                author_id=user.id or None, author_name=user.name, body=body.body.strip())
    db.add(c)
    db.flush()
    collab.comment_notify(db, c, info, user.id)
    log(db, info["project_id"], user.id, "comment", c.id, "created", info["title"])
    db.commit()
    return comment_payload(c, _names(db), user.id)


class CommentPatch(BaseModel):
    body: Optional[str] = Field(default=None, min_length=1, max_length=5000)
    resolved: Optional[bool] = None


@router.patch("/comments/{comment_id}")
def patch_comment(comment_id: int, body: CommentPatch, db: Session = Depends(get_db), user: CurrentUser = Depends(need("comments.write"))):
    c = db.get(Comment, comment_id)
    if c is None:
        raise HTTPException(404, "Nem található.")
    if body.body is not None:
        if c.author_id != user.id:
            raise HTTPException(403, "Csak a saját megjegyzésedet szerkesztheted.")
        c.body, c.edited_at = body.body.strip(), utcnow()
    if body.resolved is not None:
        c.resolved, c.resolved_by = body.resolved, (user.id if body.resolved else None)
    db.commit()
    return comment_payload(c, _names(db), user.id)


@router.delete("/comments/{comment_id}", status_code=204)
def delete_comment(comment_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("comments.write"))):
    c = db.get(Comment, comment_id)
    if c is None:
        raise HTTPException(404, "Nem található.")
    if c.author_id != user.id and user.role != "admin":
        raise HTTPException(403, "Csak a saját megjegyzésedet törölheted.")
    db.delete(c)
    db.commit()


# ── Jóváhagyások ─────────────────────────────────────────


def approval_payload(a: Approval, names: dict[int, str], project_name: str = "") -> dict[str, Any]:
    return {
        "id": a.id, "project_id": a.project_id, "project": project_name, "subject_type": a.subject_type, "subject_id": a.subject_id,
        "stage": a.stage, "stage_label": APPROVAL_STAGES[a.stage], "status": a.status, "status_label": APPROVAL_STATUSES[a.status],
        "title": a.title, "message": a.message, "requested_by": names.get(a.requested_by, ""), "requested_from_id": a.requested_from,
        "requested_from": names.get(a.requested_from, ""), "client_email": a.client_email,
        "decided_by": a.decided_by_name or names.get(a.decided_by, ""), "decision_note": a.decision_note,
        "decided_at": a.decided_at.isoformat() if a.decided_at else None, "viewed_at": a.viewed_at.isoformat() if a.viewed_at else None,
        "created_at": a.created_at.isoformat(), "expires_at": a.expires_at.isoformat() if a.expires_at else None,
    }


class ApprovalIn(BaseModel):
    subject_type: Literal["document", "wireframe"]
    subject_id: int
    requested_from: Optional[int] = None
    message: str = ""


@router.post("/approvals", status_code=201)
def request_approval(body: ApprovalIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    """Belső jóváhagyás kérése (dokumentum, wireframe) – a jóváhagyók értesítést kapnak."""
    info = _subject(db, body.subject_type, body.subject_id, user)
    obj = info["object"]
    if not (has(user.role, "documents.generate") or has(user.role, "documents.generate.writer") or has(user.role, "wireframes.edit")):
        raise HTTPException(403, "Jóváhagyást nem kérhetsz.")
    if obj.status == "approved":
        raise HTTPException(409, "Már jóvá van hagyva.")
    if db.scalar(select(Approval).where(Approval.subject_type == body.subject_type, Approval.subject_id == body.subject_id,
                                        Approval.stage == "internal", Approval.status == "pending")):
        raise HTTPException(409, "Már van függő jóváhagyási kérés.")
    if body.requested_from:
        target = db.get(User, body.requested_from)
        if target is None or not has(target.role, "approve.internal"):
            raise HTTPException(422, "A kiválasztott kolléga nem hagyhat jóvá.")
    a = Approval(project_id=info["project_id"], subject_type=body.subject_type, subject_id=body.subject_id, stage="internal",
                 title=info["title"], message=body.message, requested_by=user.id or None, requested_from=body.requested_from)
    db.add(a)
    obj.status = "review"
    db.flush()
    targets = [body.requested_from] if body.requested_from else collab.users_with(db, "approve.internal")
    collab.notify(db, targets, "approval_request", f"Jóváhagyásra vár: {info['title']}", body=body.message or f"{user.name} jóváhagyást kér.",
                  link=info["link"], project_id=info["project_id"], exclude=user.id)
    log(db, info["project_id"], user.id, "approval", a.id, "requested", info["title"])
    db.commit()
    return approval_payload(a, _names(db))


class DecisionIn(BaseModel):
    decision: Literal["approved", "changes_requested"]
    note: str = ""


@router.post("/approvals/{approval_id}/decide")
def decide(approval_id: int, body: DecisionIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("approve.internal"))):
    a = db.get(Approval, approval_id)
    if a is None or a.stage != "internal":
        raise HTTPException(404, "Nem található.")
    if a.status != "pending":
        raise HTTPException(409, "Ebben a kérésben már döntöttek.")
    if body.decision == "changes_requested" and not body.note.strip():
        raise HTTPException(422, "Írd le, mit kell módosítani.")
    info = _subject(db, a.subject_type, a.subject_id, user)
    a.status, a.decided_by, a.decided_at, a.decision_note = body.decision, user.id or None, utcnow(), body.note
    collab.apply_decision(db, a.subject_type, info.get("object"), "internal", body.decision, user.id or None)
    if body.note.strip():
        db.add(Comment(project_id=a.project_id, subject_type=a.subject_type, subject_id=a.subject_id, author_id=user.id or None,
                       author_name=user.name, body=("✓ Jóváhagyva. " if body.decision == "approved" else "↺ Módosítást kér: ") + body.note.strip()))
    collab.notify(db, [a.requested_by], "approval_decision",
                  f"{'Jóváhagyva' if body.decision == 'approved' else 'Módosítást kértek'}: {a.title}", body=body.note, link=info["link"],
                  project_id=a.project_id, exclude=user.id)
    log(db, a.project_id, user.id, "approval", a.id, body.decision, a.title)
    db.commit()
    return approval_payload(a, _names(db))


@router.delete("/approvals/{approval_id}", status_code=204)
def cancel_approval(approval_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    a = db.get(Approval, approval_id)
    if a is None:
        raise HTTPException(404, "Nem található.")
    if a.requested_by != user.id and not has(user.role, "approve.internal"):
        raise HTTPException(403, "Nem vonhatod vissza.")
    if a.status == "pending":
        a.status = "cancelled"
        info = collab.subject_info(db, a.subject_type, a.subject_id)
        obj = info and info.get("object")
        if obj is not None and a.stage == "internal" and obj.status == "review":
            obj.status = "draft"
    db.commit()


@router.get("/approvals")
def list_approvals(subject_type: Optional[str] = None, subject_id: Optional[int] = None, project_id: Optional[int] = None,
                   db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    q = select(Approval, Project.name).join(Project, Project.id == Approval.project_id)
    if subject_type and subject_id:
        q = q.where(Approval.subject_type == subject_type, Approval.subject_id == subject_id)
    if project_id:
        q = q.where(Approval.project_id == project_id)
    rows = db.execute(q.order_by(Approval.id.desc()).limit(200)).all()
    names = _names(db)
    return [approval_payload(a, names, n) for a, n in rows]


class ClientReviewIn(BaseModel):
    email: str = ""
    message: str = ""
    language: Optional[Literal["hu", "en"]] = None


@router.post("/documents/{doc_id}/client-review", status_code=201)
def client_review(doc_id: int, body: ClientReviewIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("approve.request_client"))):
    """Ügyfél-jóváhagyás: token a jóváhagyó oldalhoz. A levelet / CRM-üzenetet a WordPress küldi (crm/client-review)."""
    d = db.get(Document, doc_id)
    if d is None:
        raise HTTPException(404, "A dokumentum nem található.")
    if d.audience != "client":
        raise HTTPException(409, "Belső dokumentum nem küldhető az ügyfélnek.")
    if d.status not in ("approved", "sent"):
        raise HTTPException(409, "Előbb belső jóváhagyás kell (SEO manager).")
    for old in db.scalars(select(Approval).where(Approval.subject_type == "document", Approval.subject_id == d.id,
                                                 Approval.stage == "client", Approval.status == "pending")).all():
        old.status = "cancelled"
    project = db.get(Project, d.project_id)
    a = Approval(project_id=d.project_id, subject_type="document", subject_id=d.id, stage="client", title=f"{DOC_TYPES[d.doc_type][0]} v{d.version}",
                 message=body.message, requested_by=user.id or None, token=collab.new_token(), client_email=body.email.strip(),
                 language=body.language or d.language, expires_at=utcnow() + timedelta(days=CLIENT_REVIEW_DAYS))
    db.add(a)
    d.status, d.sent_at = "sent", utcnow()
    db.flush()
    log(db, d.project_id, user.id, "approval", a.id, "client_requested", a.title)
    db.commit()
    out = approval_payload(a, _names(db), project.name)
    out.update({"token": a.token, "crm_client_id": project.client.crm_client_id, "client": project.client.name, "domain": project.domain,
                "doc_title": d.title})
    return out


# ── Ügyfél-jóváhagyó oldal (csak a WordPress rendszerhívása) ─


def _review(db: Session, token: str) -> tuple[Approval, Document, Project]:
    a = db.scalar(select(Approval).where(Approval.token == token, Approval.stage == "client"))
    if a is None or a.status == "cancelled":
        raise HTTPException(404, "A link érvénytelen vagy visszavonták.")
    if a.expires_at and a.expires_at < utcnow() and a.status == "pending":
        raise HTTPException(410, "A link lejárt. Kérj újat a HelloProVision csapatától.")
    d = db.get(Document, a.subject_id)
    return a, d, db.get(Project, a.project_id)


@router.get("/review/{token}", dependencies=[Depends(current_system)])
def review_get(token: str, db: Session = Depends(get_db)):
    a, d, p = _review(db, token)
    if a.viewed_at is None:
        a.viewed_at = utcnow()
        db.commit()
    comments = db.scalars(select(Comment).where(Comment.subject_type == "document", Comment.subject_id == d.id, Comment.is_client.is_(True)).order_by(Comment.id)).all()
    return {
        "title": d.title, "type_label": DOC_TYPES[d.doc_type][0], "version": d.version, "language": a.language, "project": p.name,
        "client": p.client.name, "domain": p.domain, "message": a.message, "status": a.status, "decided_at": a.decided_at.isoformat() if a.decided_at else None,
        "decided_by": a.decided_by_name, "decision_note": a.decision_note, "formats": [f.format for f in d.files],
        "sections": [{"title": s["title"]} for s in (d.content or {}).get("sections", [])], "lead": (d.content or {}).get("lead", ""),
        "comments": [{"author": c.author_name, "body": c.body, "created_at": c.created_at.isoformat()} for c in comments],
    }


@router.get("/review/{token}/file/{fmt}", dependencies=[Depends(current_system)])
def review_file(token: str, fmt: str, db: Session = Depends(get_db)):
    a, d, p = _review(db, token)
    f = next((x for x in d.files if x.format == fmt), None)
    if f is None:
        raise HTTPException(404, "Nincs ilyen fájl.")
    stored = db.get(StoredFile, f.file_id)
    return file_response(storage.read(stored), stored.filename, stored.mime)


class ClientDecision(BaseModel):
    decision: Literal["approved", "changes_requested", "comment"]
    name: str = Field(min_length=1, max_length=255)
    note: str = Field(default="", max_length=5000)


@router.post("/review/{token}", dependencies=[Depends(current_system)])
def review_post(token: str, body: ClientDecision, db: Session = Depends(get_db)):
    a, d, p = _review(db, token)
    if body.decision != "comment" and a.status != "pending":
        raise HTTPException(409, "Ebben már döntöttél – köszönjük!")
    if body.decision in ("changes_requested", "comment") and not body.note.strip():
        raise HTTPException(422, "Írd le, mit szeretnél módosítani.")
    info = collab.subject_info(db, "document", d.id)
    if body.note.strip():
        prefix = {"approved": "✓ Jóváhagyva. ", "changes_requested": "↺ Módosítást kér: ", "comment": ""}[body.decision]
        c = Comment(project_id=p.id, subject_type="document", subject_id=d.id, author_name=f"{body.name.strip()} (ügyfél)", is_client=True, body=prefix + body.note.strip())
        db.add(c)
        db.flush()
        if body.decision == "comment":
            collab.comment_notify(db, c, info, None)
    if body.decision != "comment":
        a.status, a.decided_at, a.decided_by_name, a.decision_note = body.decision, utcnow(), f"{body.name.strip()} (ügyfél)", body.note.strip()
        collab.notify(db, [a.requested_by, p.owner_id], "approval_decision",
                      f"Ügyfél {'jóváhagyta' if body.decision == 'approved' else 'módosítást kért'}: {a.title} – {p.name}",
                      body=body.note.strip(), link=info["link"], project_id=p.id)
        log(db, p.id, None, "approval", a.id, "client_" + body.decision, f"{a.title} – {body.name.strip()}")
    db.commit()
    return {"ok": True, "status": a.status}


# ── Értesítések ─────────────────────────────────────────


def notification_payload(n: Notification) -> dict[str, Any]:
    return {"id": n.id, "kind": n.kind, "title": n.title, "body": n.body, "link": n.link, "project_id": n.project_id,
            "read": n.read_at is not None, "created_at": n.created_at.isoformat()}


@router.get("/me/notifications")
def my_notifications(db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    rows = db.scalars(select(Notification).where(Notification.user_id == user.id).order_by(Notification.id.desc()).limit(50)).all()
    unread = db.scalar(select(func.count()).select_from(Notification).where(Notification.user_id == user.id, Notification.read_at.is_(None)))
    return {"unread": unread, "items": [notification_payload(n) for n in rows]}


class ReadIn(BaseModel):
    ids: list[int] = []
    all: bool = False


@router.post("/me/notifications/read")
def mark_read(body: ReadIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    q = select(Notification).where(Notification.user_id == user.id, Notification.read_at.is_(None))
    if not body.all:
        q = q.where(Notification.id.in_(body.ids or [0]))
    now = utcnow()
    n = 0
    for row in db.scalars(q).all():
        row.read_at = now
        n += 1
    db.commit()
    return {"marked": n}


# ── WordPress rendszerhívások ────────────────────────────


@router.get("/system/outbox", dependencies=[Depends(current_system)])
def outbox(db: Session = Depends(get_db)):
    """A még ki nem küldött e-mail értesítések (a WordPress 5 percenként elkéri, és wp_mail()-lel küldi)."""
    heartbeat("wp_cron", {}, db)
    rows = db.execute(
        select(Notification, User, Project.name).join(User, User.id == Notification.user_id).outerjoin(Project, Project.id == Notification.project_id)
        .where(Notification.email_status == "pending").order_by(Notification.id).limit(100)
    ).all()
    out = []
    for n, u, pname in rows:
        if not u.is_active or not u.email or n.read_at is not None:
            n.email_status = "skipped"
            continue
        out.append({"id": n.id, "wp_user_id": u.wp_user_id, "email": u.email, "name": u.display_name, "kind": n.kind,
                    "title": n.title, "body": n.body, "link": n.link, "project": pname or ""})
    db.commit()
    return out


class AckIn(BaseModel):
    sent: list[int] = []
    failed: list[int] = []


@router.post("/system/outbox/ack", dependencies=[Depends(current_system)])
def outbox_ack(body: AckIn, db: Session = Depends(get_db)):
    for ids, status in ((body.sent, "sent"), (body.failed, "failed")):
        for n in db.scalars(select(Notification).where(Notification.id.in_(ids or [0]))).all():
            n.email_status = status
    db.commit()
    return {"ok": True}


@router.post("/system/daily", dependencies=[Depends(current_system)])
def system_daily(db: Session = Depends(get_db)):
    res = collab.daily(db)
    heartbeat("wp_daily", res, db)
    db.commit()
    return res


# ── Vezérlőpult ──────────────────────────────────────────


@dashboard.widget
def approvals_widget(db: Session, user: CurrentUser) -> dict:
    rows = db.execute(select(Approval, Project.name).join(Project, Project.id == Approval.project_id)
                      .where(Approval.status == "pending", Project.archived_at.is_(None)).order_by(Approval.id.desc()).limit(50)).all()
    items = []
    for a, name in rows:
        mine_to_decide = a.stage == "internal" and has(user.role, "approve.internal") and a.requested_from in (None, user.id)
        waiting_on_client = a.stage == "client" and a.requested_by == user.id
        if not (mine_to_decide or waiting_on_client):
            continue
        tab = "documents" if a.subject_type == "document" else "wireframes"
        items.append({"id": a.id, "project_id": a.project_id, "project": name, "tab": tab, "label": a.title,
                      "stage_label": "Rád vár" if mine_to_decide else "Ügyfélnél" + (" · megnyitotta" if a.viewed_at else "")})
    return {"approvals": items[:12]}
