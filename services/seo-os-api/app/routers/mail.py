"""Levelezés: postafiókok, levelek, küldés, aláírás. A CRM felülete hívja (helloprovision-mail bővítmény)."""

from typing import Literal, Optional
from urllib.parse import unquote

from fastapi import APIRouter, Depends, HTTPException, Request
import re

from pydantic import BaseModel, Field, field_validator
from sqlalchemy import func, or_, select
from sqlalchemy.orm import Session

from ..auth import CurrentUser, current_system, need
from ..db import get_db
from ..jobs import runner
from ..models import MailAccount, MailContact, MailMessage, MailProfile, StoredFile
from ..services import mail, storage
from ..services.files import file_response
from ..services.mail import transport
from ..services.mail.transport import MailError
from .research import job_payload

router = APIRouter(tags=["mail"])
EMAIL = re.compile(r"^[^@\s<>]+@[^@\s<>]+\.[^@\s<>]+$")


def _email(v: Optional[str]) -> Optional[str]:
    if v is None:
        return v
    v = v.strip()
    if not EMAIL.match(v):
        raise ValueError(f"Érvénytelen e-mail cím: {v}")
    return v.lower()
Security = Literal["ssl", "starttls", "none"]


def _is_admin(user: CurrentUser) -> bool:
    return user.can("settings.manage")


def _account(db: Session, account_id: int, user: CurrentUser) -> MailAccount:
    a = db.get(MailAccount, account_id)
    if a is None or not mail.can_access(a, user.wp_user_id, _is_admin(user)):
        raise HTTPException(404, "A postafiók nem található.")
    return a


def _message(db: Session, message_id: int, user: CurrentUser) -> MailMessage:
    m = db.get(MailMessage, message_id)
    if m is None or not mail.can_access(m.account, user.wp_user_id, _is_admin(user)):
        raise HTTPException(404, "A levél nem található.")
    return m


# ── Saját postafiókok és levelek ────────────────────────


@router.get("/mail/me")
def my_mail(all: bool = False, db: Session = Depends(get_db), user: CurrentUser = Depends(need("mail.use"))):
    admin = _is_admin(user)
    accounts = mail.visible_accounts(db, user.wp_user_id, admin, all)
    ids = [a.id for a in accounts]
    unread = dict(db.execute(select(MailMessage.account_id, func.count()).where(
        MailMessage.account_id.in_(ids), MailMessage.folder == "inbox", MailMessage.seen.is_(False)).group_by(MailMessage.account_id)).all()) if ids else {}
    return {
        "accounts": [{**mail.account_payload(a), "unread": unread.get(a.id, 0), "signature": mail.render_signature(db, user.wp_user_id, a)} for a in accounts],
        "is_admin": admin,
    }


@router.get("/mail/messages")
def list_messages(account_id: Optional[int] = None, folder: Literal["inbox", "sent", "all"] = "inbox", unread: bool = False,
                  client_id: Optional[int] = None, q: str = "", page: int = 1, per_page: int = 50,
                  db: Session = Depends(get_db), user: CurrentUser = Depends(need("mail.use"))):
    admin = _is_admin(user)
    if account_id:
        ids = [_account(db, account_id, user).id]
    else:
        ids = [a.id for a in mail.visible_accounts(db, user.wp_user_id, admin, all_accounts=bool(client_id) and admin)]
    if not ids:
        return {"items": [], "total": 0, "page": 1}
    cond = [MailMessage.account_id.in_(ids)]
    if folder != "all":
        cond.append(MailMessage.folder == folder)
    if unread:
        cond.append(MailMessage.seen.is_(False))
    if client_id:
        cond.append(MailMessage.crm_client_id == client_id)
    if q.strip():
        like = f"%{q.strip()}%"
        cond.append(or_(MailMessage.subject.ilike(like), MailMessage.from_email.ilike(like), MailMessage.from_name.ilike(like),
                        MailMessage.body_text.ilike(like)))
    per_page = max(10, min(per_page, 100))
    total = db.scalar(select(func.count()).select_from(MailMessage).where(*cond)) or 0
    rows = db.scalars(select(MailMessage).where(*cond).order_by(MailMessage.date.desc().nulls_last(), MailMessage.id.desc())
                      .offset((max(page, 1) - 1) * per_page).limit(per_page)).all()
    return {"items": [mail.message_summary(m) for m in rows], "total": total, "page": page, "per_page": per_page}


@router.get("/mail/messages/{message_id}")
def get_message(message_id: int, images: bool = False, db: Session = Depends(get_db), user: CurrentUser = Depends(need("mail.use"))):
    m = _message(db, message_id, user)
    mail.mark_seen(db, m, True)
    db.commit()
    return mail.message_detail(db, m, images)


class SeenIn(BaseModel):
    seen: bool = True


@router.post("/mail/messages/{message_id}/seen")
def set_seen(message_id: int, body: SeenIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("mail.use"))):
    m = _message(db, message_id, user)
    mail.mark_seen(db, m, body.seen)
    db.commit()
    return mail.message_summary(m)


class LinkIn(BaseModel):
    crm_client_id: Optional[int] = None
    crm_task_id: Optional[int] = None
    remember: bool = True  # a feladó címét is ehhez az ügyfélhez kötjük a jövőre


@router.post("/mail/messages/{message_id}/link")
def link_message(message_id: int, body: LinkIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("mail.use"))):
    m = _message(db, message_id, user)
    fields = body.model_fields_set
    if "crm_client_id" in fields:
        m.crm_client_id = body.crm_client_id or None
        other = m.from_email if m.folder == "inbox" else ((m.to or [{}])[0].get("email") or "")
        if body.crm_client_id and body.remember and other and other not in mail.own_addresses(db):
            row = db.get(MailContact, other) or MailContact(email=other)
            row.crm_client_id, row.source = body.crm_client_id, "manual"
            row.name = row.name or (m.from_name if m.folder == "inbox" else "")
            db.add(row)
            # A szál többi levele is ide tartozik.
            if m.thread_key:
                for x in db.scalars(select(MailMessage).where(MailMessage.account_id == m.account_id, MailMessage.thread_key == m.thread_key,
                                                              MailMessage.crm_client_id.is_(None))).all():
                    x.crm_client_id = body.crm_client_id
    if "crm_task_id" in fields:
        m.crm_task_id = body.crm_task_id or None
    db.commit()
    return mail.message_summary(m)


@router.get("/mail/messages/{message_id}/attachments/{index}")
def download_attachment(message_id: int, index: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("mail.use"))):
    m = _message(db, message_id, user)
    if index < 0 or index >= len(m.attachments or []):
        raise HTTPException(404, "Nincs ilyen melléklet.")
    att = m.attachments[index]
    f = db.get(StoredFile, att.get("file_id")) if att.get("file_id") else None
    if f is None:
        raise HTTPException(404, "Ez a melléklet túl nagy volt a mentéshez, a levelezőprogramban nyisd meg.")
    return file_response(storage.read(f), att.get("name") or f.filename, att.get("mime") or f.mime)


@router.post("/mail/uploads", status_code=201)
async def upload(request: Request, db: Session = Depends(get_db), user: CurrentUser = Depends(need("mail.use"))):
    data = await request.body()
    if not data:
        raise HTTPException(422, "Üres fájl.")
    if len(data) > mail.MAX_ATTACHMENT:
        raise HTTPException(413, "A melléklet legfeljebb 15 MB lehet.")
    name = unquote(request.headers.get("x-filename", "")) or "melleklet"
    f = storage.save(db, data, name, "mail_upload", None, user.id or None, request.headers.get("content-type", ""))
    db.commit()
    return {"id": f.id, "name": f.filename, "size": f.size, "mime": f.mime}


class Addr(BaseModel):
    email: str
    name: str = ""

    _v = field_validator("email")(classmethod(lambda cls, v: _email(v)))


class SendIn(BaseModel):
    account_id: int
    to: list[Addr] = Field(min_length=1)
    cc: list[Addr] = []
    bcc: list[Addr] = []
    subject: str = Field(default="", max_length=998)
    body_html: str = ""
    reply_to_id: Optional[int] = None
    forward_of_id: Optional[int] = None
    upload_ids: list[int] = []
    crm_client_id: Optional[int] = None


@router.post("/mail/send", status_code=201)
def send_message(body: SendIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("mail.use"))):
    account = _account(db, body.account_id, user)
    reply_to = _message(db, body.reply_to_id, user) if body.reply_to_id else None
    files: list[StoredFile] = []
    for fid in body.upload_ids:
        f = db.get(StoredFile, fid)
        if f is None or f.kind != "mail_upload" or (f.uploaded_by and user.id and f.uploaded_by != user.id):
            raise HTTPException(404, "A csatolmány nem található, töltsd fel újra.")
        files.append(f)
    if body.forward_of_id:
        original = _message(db, body.forward_of_id, user)
        for att in original.attachments or []:
            f = db.get(StoredFile, att.get("file_id")) if att.get("file_id") else None
            if f is not None:
                files.append(f)
    try:
        sent = mail.send(db, account, user.wp_user_id, to=[a.model_dump() for a in body.to], cc=[a.model_dump() for a in body.cc],
                         bcc=[a.model_dump() for a in body.bcc], subject=body.subject, body_html=body.body_html,
                         reply_to=reply_to, attachments=files)
    except MailError as e:
        db.rollback()
        raise HTTPException(422, str(e))
    if body.crm_client_id:
        sent.crm_client_id = body.crm_client_id
    db.commit()
    return mail.message_summary(sent)


class SyncIn(BaseModel):
    account_id: Optional[int] = None


@router.post("/mail/sync")
def sync_now(body: SyncIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("mail.use"))):
    if body.account_id:
        _account(db, body.account_id, user)
    job = runner.enqueue(db, "mail_sync", None, {"account_id": body.account_id} if body.account_id else {}, user.id or None)
    return job_payload(job)


# ── Admin: postafiókok, aláírás ─────────────────────────


class AccountIn(BaseModel):
    email: Optional[str] = None
    display_name: Optional[str] = Field(default=None, max_length=255)
    owner_wp_user_id: Optional[int] = None
    imap_host: Optional[str] = Field(default=None, max_length=255)
    imap_port: Optional[int] = Field(default=None, ge=1, le=65535)
    imap_security: Optional[Security] = None
    smtp_host: Optional[str] = Field(default=None, max_length=255)
    smtp_port: Optional[int] = Field(default=None, ge=1, le=65535)
    smtp_security: Optional[Security] = None
    username: Optional[str] = Field(default=None, max_length=255)
    password: Optional[str] = Field(default=None, max_length=512)
    sent_folder: Optional[str] = Field(default=None, max_length=255)
    is_active: Optional[bool] = None

    _v = field_validator("email")(classmethod(lambda cls, v: _email(v)))

    @field_validator("imap_host", "smtp_host", "username", "sent_folder")
    @classmethod
    def _strip(cls, v):
        return v.strip() if isinstance(v, str) else v


PRESETS = {
    "google": {"imap_host": "imap.gmail.com", "imap_port": 993, "imap_security": "ssl", "smtp_host": "smtp.gmail.com", "smtp_port": 465, "smtp_security": "ssl"},
    "microsoft": {"imap_host": "outlook.office365.com", "imap_port": 993, "imap_security": "ssl", "smtp_host": "smtp.office365.com", "smtp_port": 587, "smtp_security": "starttls"},
}


@router.get("/mail/accounts")
def list_accounts(db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))):
    rows = db.scalars(select(MailAccount).order_by(MailAccount.owner_wp_user_id.is_(None), MailAccount.email)).all()
    return {"items": [mail.account_payload(a, admin=True) for a in rows], "presets": PRESETS}


@router.post("/mail/accounts", status_code=201)
def create_account(body: AccountIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))):
    if not body.email:
        raise HTTPException(422, "Add meg az e-mail címet.")
    if db.scalar(select(MailAccount.id).where(func.lower(MailAccount.email) == body.email.lower())):
        raise HTTPException(409, "Ez a postafiók már fel van véve.")
    a = MailAccount(email=body.email)
    mail.apply_account(a, body.model_dump(exclude_unset=True))
    db.add(a)
    db.commit()
    return mail.account_payload(a, admin=True)


@router.patch("/mail/accounts/{account_id}")
def update_account(account_id: int, body: AccountIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))):
    a = db.get(MailAccount, account_id)
    if a is None:
        raise HTTPException(404, "A postafiók nem található.")
    data = body.model_dump(exclude_unset=True)
    if any(k in data for k in ("imap_host", "username")) and (data.get("imap_host", a.imap_host) != a.imap_host or data.get("username", a.username) != a.username):
        a.sync_state, a.sent_folder = {}, data.get("sent_folder", "") or ""
    mail.apply_account(a, data)
    db.commit()
    return mail.account_payload(a, admin=True)


@router.delete("/mail/accounts/{account_id}", status_code=204)
def delete_account(account_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))):
    a = db.get(MailAccount, account_id)
    if a is None:
        return
    file_ids = [att.get("file_id") for atts in db.scalars(select(MailMessage.attachments).where(MailMessage.account_id == a.id)).all()
                for att in atts or [] if att.get("file_id")]
    db.delete(a)
    db.flush()
    for fid in file_ids:
        f = db.get(StoredFile, fid)
        if f is not None and f.kind == "mail":
            storage.delete(f)
            db.delete(f)
    db.commit()


@router.post("/mail/accounts/{account_id}/test")
def test_account(account_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))):
    a = db.get(MailAccount, account_id)
    if a is None:
        raise HTTPException(404, "A postafiók nem található.")
    try:
        result = transport.test_account(a)
    except MailError as e:
        a.last_error = str(e)
        db.commit()
        return {"ok": False, "error": str(e)}
    if result.get("sent_folder") and not a.sent_folder:
        a.sent_folder = result["sent_folder"]
    a.last_error = ""
    db.commit()
    return {"ok": True, **result}


class SignatureIn(BaseModel):
    template: str = Field(max_length=20000)


@router.get("/mail/signature")
def get_signature(db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))):
    profiles = db.scalars(select(MailProfile)).all()
    return {
        "template": mail.signature_template(db),
        "default": mail.DEFAULT_SIGNATURE,
        "placeholders": ["{name}", "{title}", "{phone}", "{phone_line}", "{email}"],
        "profiles": [{"wp_user_id": p.wp_user_id, "name": p.name, "title": p.title, "phone": p.phone} for p in profiles],
    }


@router.put("/mail/signature")
def put_signature(body: SignatureIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))):
    mail.set_signature_template(db, body.template or mail.DEFAULT_SIGNATURE, user.id or None)
    db.commit()
    return get_signature(db, user)


class ProfileIn(BaseModel):
    wp_user_id: int
    name: str = Field(default="", max_length=255)
    title: str = Field(default="", max_length=255)
    phone: str = Field(default="", max_length=64)


@router.put("/mail/profiles")
def put_profile(body: ProfileIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))):
    p = db.get(MailProfile, body.wp_user_id) or MailProfile(wp_user_id=body.wp_user_id)
    p.name, p.title, p.phone = body.name.strip(), body.title.strip(), body.phone.strip()
    db.add(p)
    db.commit()
    return {"wp_user_id": p.wp_user_id, "name": p.name, "title": p.title, "phone": p.phone}


@router.post("/mail/signature/preview")
def preview_signature(body: SignatureIn, wp_user_id: Optional[int] = None, db: Session = Depends(get_db),
                      user: CurrentUser = Depends(need("settings.manage"))):
    fake = MailAccount(email=user.email or "nev@helloprovision.com", display_name=user.name)
    return {"html": mail.render_signature(db, wp_user_id or user.wp_user_id, fake, template=body.template)}


# ── WordPress: CRM ügyfelek címei ──────────────────────


class ContactIn(BaseModel):
    client_id: int
    name: str = ""
    emails: list[str] = []


class ContactsIn(BaseModel):
    contacts: list[ContactIn]
    full: bool = False


@router.post("/system/mail-contacts", dependencies=[Depends(current_system)])
def sync_contacts(body: ContactsIn, db: Session = Depends(get_db)):
    n = mail.upsert_contacts(db, [c.model_dump() for c in body.contacts], full=body.full)
    # A még ügyfél nélküli levelek újra párosítása.
    own = mail.own_addresses(db)
    relinked = 0
    for m in db.scalars(select(MailMessage).where(MailMessage.crm_client_id.is_(None)).order_by(MailMessage.id.desc()).limit(2000)).all():
        other = [m.from_email] if m.folder == "inbox" else [x.get("email", "") for x in (m.to or []) + (m.cc or [])]
        cid = mail.match_client(db, other, own)
        if cid:
            m.crm_client_id = cid
            relinked += 1
    db.commit()
    return {"contacts": n, "relinked": relinked}

