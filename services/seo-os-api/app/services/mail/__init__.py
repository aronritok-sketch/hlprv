"""Levelezés a CRM-ben.

- Postafiókok: az admin veszi fel (IMAP + SMTP, titkosított jelszó), munkatársanként vagy közösnek (pl. info@).
- Szinkron: a worker 2 percenként behozza az új leveleket a Beérkezett és az Elküldött mappából.
- Küldés: SMTP-n, az admin által beállított egységes aláírással; a levél a levelezőprogramban is az Elküldöttek közé kerül.
- CRM: a levél a feladó / címzett e-mail címe alapján magától ügyfélhez kötődik; levélből feladat készíthető.
"""

import html
import logging
import re
import uuid
from datetime import datetime, timedelta, timezone
from email.message import EmailMessage
from email.utils import formataddr, formatdate, make_msgid
from typing import Any, Iterable, Optional

from sqlalchemy import or_, select
from sqlalchemy.orm import Session

from ...db import utcnow
from ...jobs.runner import JobError, handler
from ...models import AppSetting, Job, MailAccount, MailContact, MailMessage, MailProfile, StoredFile
from .. import storage
from . import crypto, parse, transport
from .transport import MailError

log = logging.getLogger("seo_os.mail")

INITIAL_DAYS = 30
INITIAL_LIMIT = 300
MAX_ATTACHMENT = 15 * 1024 * 1024

DEFAULT_SIGNATURE = (
    '<table cellpadding="0" cellspacing="0" style="font-family:Arial,sans-serif;font-size:13px;color:#17170f">'
    '<tr><td style="padding-top:12px;border-top:2px solid #A4DA4C">'
    '<strong style="font-size:14px">{name}</strong><br>'
    '<span style="color:#5b5b4c">{title}</span><br>'
    '<strong>HelloProVision</strong> · <a href="https://helloprovision.com" style="color:#5b7419">helloprovision.com</a><br>'
    '{phone_line}<a href="mailto:{email}" style="color:#5b7419">{email}</a>'
    "</td></tr></table>"
)


# ── Beállítások ─────────────────────────────────────────


def _setting(db: Session, key: str, default: Any) -> Any:
    row = db.get(AppSetting, key)
    return default if row is None or row.value in (None, "") else row.value


def _set_setting(db: Session, key: str, value: Any, user_id: Optional[int]) -> None:
    row = db.get(AppSetting, key) or AppSetting(key=key, is_secret=False)
    row.value, row.updated_by = value, user_id or None
    db.add(row)


def signature_template(db: Session) -> str:
    return str(_setting(db, "mail_signature_html", DEFAULT_SIGNATURE))


def set_signature_template(db: Session, template: str, user_id: Optional[int]) -> None:
    safe, _ = parse.sanitize_html(template, images=True)
    _set_setting(db, "mail_signature_html", safe, user_id)


def render_signature(db: Session, wp_user_id: Optional[int], account: MailAccount, template: Optional[str] = None) -> str:
    """Az egységes aláírás a munkatárs adataival. A mezőket csak az admin állítja (MailProfile)."""
    prof = db.get(MailProfile, wp_user_id) if wp_user_id else None
    name = (prof.name if prof and prof.name else account.display_name) or account.email
    title = prof.title if prof else ""
    phone = prof.phone if prof else ""
    values = {
        "name": html.escape(name),
        "title": html.escape(title),
        "phone": html.escape(phone),
        "phone_line": (html.escape(phone) + "<br>") if phone else "",
        "email": html.escape(account.email),
    }
    out = parse.sanitize_html(template, images=True)[0] if template is not None else signature_template(db)
    for k, v in values.items():
        out = out.replace("{" + k + "}", v)
    # Üres sor (pl. nincs beosztás) ne maradjon.
    return re.sub(r'<span[^>]*></span><br>', "", out)


# ── Fiókok ──────────────────────────────────────────────


def account_payload(a: MailAccount, admin: bool = False) -> dict[str, Any]:
    out = {
        "id": a.id,
        "email": a.email,
        "display_name": a.display_name,
        "owner_wp_user_id": a.owner_wp_user_id,
        "shared": a.owner_wp_user_id is None,
        "is_active": a.is_active,
        "last_sync_at": a.last_sync_at.isoformat() if a.last_sync_at else None,
        "last_error": a.last_error,
    }
    if admin:
        out.update({
            "imap_host": a.imap_host, "imap_port": a.imap_port, "imap_security": a.imap_security,
            "smtp_host": a.smtp_host, "smtp_port": a.smtp_port, "smtp_security": a.smtp_security,
            "username": a.username, "has_password": bool(a.secret_enc), "sent_folder": a.sent_folder,
        })
    return out


def visible_accounts(db: Session, wp_user_id: int, admin: bool, all_accounts: bool = False) -> list[MailAccount]:
    q = select(MailAccount).where(MailAccount.is_active.is_(True)).order_by(MailAccount.owner_wp_user_id.is_(None), MailAccount.email)
    if not (admin and all_accounts):
        q = q.where(or_(MailAccount.owner_wp_user_id == wp_user_id, MailAccount.owner_wp_user_id.is_(None)))
    return list(db.scalars(q).all())


def can_access(account: MailAccount, wp_user_id: int, admin: bool) -> bool:
    return admin or account.owner_wp_user_id is None or account.owner_wp_user_id == wp_user_id


def apply_account(a: MailAccount, data: dict[str, Any]) -> None:
    for key in ("email", "display_name", "imap_host", "imap_port", "imap_security", "smtp_host", "smtp_port",
                "smtp_security", "username", "sent_folder", "is_active"):
        if key in data and data[key] is not None:
            setattr(a, key, data[key])
    if "owner_wp_user_id" in data:
        a.owner_wp_user_id = data["owner_wp_user_id"] or None
    if data.get("password"):
        a.secret_enc = crypto.encrypt(data["password"])
    a.email = a.email.strip().lower()


# ── CRM ügyfelek ────────────────────────────────────────


def upsert_contacts(db: Session, contacts: Iterable[dict[str, Any]], full: bool = False) -> int:
    """A WordPress küldi: [{client_id, name, emails: [...]}]. full=True esetén a CRM-ből törölt címek is kikerülnek."""
    seen: set[str] = set()
    n = 0
    for c in contacts:
        cid = int(c.get("client_id") or 0)
        if not cid:
            continue
        for email in c.get("emails") or []:
            email = (email or "").strip().lower()
            if "@" not in email or email in seen:
                continue
            seen.add(email)
            row = db.get(MailContact, email) or MailContact(email=email)
            row.crm_client_id, row.name, row.source = cid, (c.get("name") or "")[:255], "crm"
            db.add(row)
            n += 1
    if full:
        for row in db.scalars(select(MailContact).where(MailContact.source == "crm")).all():
            if row.email not in seen:
                db.delete(row)
    db.flush()
    return n


def match_client(db: Session, emails: Iterable[str], own: set[str]) -> Optional[int]:
    emails = [e.lower() for e in emails if e and e.lower() not in own]
    if not emails:
        return None
    rows = {r.email: r.crm_client_id for r in db.scalars(select(MailContact).where(MailContact.email.in_(emails))).all()}
    for e in emails:
        if e in rows:
            return rows[e]
    # Ugyanarról a domainről (pl. info@ és mike@ ugyanannál a cégnél), kivéve a nagy ingyenes szolgáltatókat.
    free = {"gmail.com", "googlemail.com", "yahoo.com", "hotmail.com", "outlook.com", "icloud.com", "freemail.hu", "citromail.hu", "t-online.hu"}
    domains = {e.split("@", 1)[1] for e in emails} - free
    for d in domains:
        row = db.scalar(select(MailContact).where(MailContact.email.like(f"%@{d}")).limit(1))
        if row:
            return row.crm_client_id
    return None


def own_addresses(db: Session) -> set[str]:
    return {a.lower() for a in db.scalars(select(MailAccount.email)).all()}


# ── Szinkron ────────────────────────────────────────────


def _store(db: Session, account: MailAccount, folder: str, uid: int, raw: bytes, seen: bool, flagged: bool, own: set[str]) -> Optional[MailMessage]:
    data = parse.parse(raw)
    existing = None
    if data["message_id"]:
        existing = db.scalar(select(MailMessage).where(MailMessage.account_id == account.id, MailMessage.folder == folder,
                                                       MailMessage.message_id == data["message_id"]))
    if existing is not None:
        # A CRM-ből küldött levél: most kapja meg a szerveren kiosztott azonosítót.
        if existing.uid is None:
            existing.uid = uid
        return None
    files = []
    for att in data.pop("attachments"):
        entry = {"name": att["name"], "mime": att["mime"], "size": att["size"], "file_id": None}
        if att["size"] <= MAX_ATTACHMENT:
            f = storage.save(db, att["data"], att["name"], "mail", None, None, att["mime"])
            entry["file_id"] = f.id
        files.append(entry)
    if data["in_reply_to"]:
        parent = db.scalar(select(MailMessage).where(MailMessage.account_id == account.id, MailMessage.message_id == data["in_reply_to"]).limit(1))
        if parent is not None and parent.thread_key:
            data["thread_key"] = parent.thread_key
    counterpart = [data["from_email"]] if folder == "inbox" else [x["email"] for x in data["to"] + data["cc"]]
    msg = MailMessage(account_id=account.id, folder=folder, uid=uid, attachments=files, seen=seen or folder == "sent",
                      flagged=flagged, crm_client_id=match_client(db, counterpart, own), **data)
    db.add(msg)
    return msg


def sync_account(db: Session, account: MailAccount, since_days: int = INITIAL_DAYS) -> dict[str, int]:
    own = own_addresses(db)
    m = transport.open_imap(transport.imap_conn(account))
    counts = {"inbox": 0, "sent": 0}
    try:
        if not account.sent_folder:
            account.sent_folder = transport.find_sent_folder(m)
        state = dict(account.sync_state or {})
        for key, folder_name in (("inbox", "INBOX"), ("sent", account.sent_folder)):
            if not folder_name:
                continue
            uidvalidity, _ = transport.select(m, folder_name)
            st = dict(state.get(key) or {})
            if st.get("uidvalidity") and st.get("uidvalidity") != uidvalidity:
                # A szerver újraszámozta a mappát: a régi azonosítók érvénytelenek.
                for old in db.scalars(select(MailMessage).where(MailMessage.account_id == account.id, MailMessage.folder == key)).all():
                    old.uid = None
                st = {}
            last = int(st.get("last_uid") or 0)
            uids = transport.search_uids(m, last, since_days)
            if not last:
                uids = uids[-INITIAL_LIMIT:]
            for uid, raw, seen, flagged in transport.fetch(m, uids):
                if _store(db, account, key, uid, raw, seen, flagged, own) is not None:
                    counts[key] += 1
                last = max(last, uid)
                db.flush()
            state[key] = {"uidvalidity": uidvalidity, "last_uid": last}
        account.sync_state = state
        account.last_sync_at, account.last_error = utcnow(), ""
    finally:
        transport._logout(m)
    return counts


def sync_all(db: Session) -> dict[str, Any]:
    out: dict[str, Any] = {}
    for account in db.scalars(select(MailAccount).where(MailAccount.is_active.is_(True))).all():
        try:
            out[account.email] = sync_account(db, account)
            db.commit()
        except MailError as e:
            db.rollback()
            account = db.get(MailAccount, account.id)
            account.last_error, account.last_sync_at = str(e), utcnow()
            db.commit()
            out[account.email] = {"error": str(e)}
        except Exception as e:  # noqa: BLE001 – egy fiók hibája ne állítsa meg a többit
            log.exception("levél szinkron hiba: %s", account.email)
            db.rollback()
            account = db.get(MailAccount, account.id)
            account.last_error, account.last_sync_at = f"Váratlan hiba: {e}", utcnow()
            db.commit()
            out[account.email] = {"error": str(e)}
    return out


@handler("mail_sync")
def job_sync(db: Session, job: Job, progress) -> dict[str, Any]:
    account_id = (job.payload or {}).get("account_id")
    if account_id:
        account = db.get(MailAccount, account_id)
        if account is None:
            raise JobError("A postafiók nem létezik.")
        try:
            counts = sync_account(db, account)
        except MailError as e:
            account.last_error = str(e)
            db.commit()
            raise JobError(str(e)) from e
        return {account.email: counts}
    return sync_all(db)


def enqueue_sync_if_idle(db: Session) -> Optional[Job]:
    """A worker hívja rendszeresen: új szinkron, ha nincs függőben lévő és van aktív fiók."""
    from ...jobs import runner

    pending = db.scalar(select(Job.id).where(Job.type == "mail_sync", Job.status.in_(("queued", "running"))).limit(1))
    if pending or not db.scalar(select(MailAccount.id).where(MailAccount.is_active.is_(True)).limit(1)):
        return None
    return runner.enqueue(db, "mail_sync", None, {}, None)


# ── Olvasás ─────────────────────────────────────────────


def message_summary(m: MailMessage) -> dict[str, Any]:
    return {
        "id": m.id,
        "account_id": m.account_id,
        "folder": m.folder,
        "subject": m.subject or "(nincs tárgy)",
        "from": {"name": m.from_name, "email": m.from_email},
        "to": m.to,
        "date": m.date.isoformat() if m.date else None,
        "snippet": m.snippet,
        "seen": m.seen,
        "flagged": m.flagged,
        "has_attachments": bool(m.attachments),
        "crm_client_id": m.crm_client_id,
        "crm_task_id": m.crm_task_id,
        "thread_key": m.thread_key,
    }


def message_detail(db: Session, m: MailMessage, images: bool = False) -> dict[str, Any]:
    body_html, blocked = parse.sanitize_html(m.body_html, images=images) if m.body_html else (parse.text_to_html(m.body_text), 0)
    thread = []
    if m.thread_key:
        thread = [message_summary(x) for x in db.scalars(select(MailMessage).where(
            MailMessage.account_id == m.account_id, MailMessage.thread_key == m.thread_key).order_by(MailMessage.date)).all()]
    return {**message_summary(m), "cc": m.cc, "body_html": body_html, "body_text": m.body_text, "blocked_images": blocked,
            "attachments": m.attachments, "message_id": m.message_id, "references": m.references, "thread": thread}


def mark_seen(db: Session, m: MailMessage, seen: bool = True) -> None:
    """Az olvasottság azonnal a CRM-ben; a levelezőszerveren a háttérben (ne várjon rá a felület)."""
    if m.seen == seen:
        return
    m.seen = seen
    if m.uid and m.folder == "inbox":
        from ...jobs import runner

        db.flush()
        runner.enqueue(db, "mail_flag", None, {"message_id": m.id, "seen": seen}, None)


@handler("mail_flag")
def job_flag(db: Session, job: Job, progress) -> dict[str, Any]:
    m = db.get(MailMessage, int((job.payload or {}).get("message_id") or 0))
    if m is None or not m.uid:
        return {}
    seen = bool((job.payload or {}).get("seen", True))
    try:
        transport.set_flag(m.account, "INBOX", m.uid, "\\Seen", seen)
    except MailError as e:
        log.warning("olvasottság jelölése nem sikerült (%s): %s", m.account.email, e)
        return {"error": str(e)}
    return {"ok": True}


# ── Küldés ──────────────────────────────────────────────


def _addr_list(items: Iterable[Any]) -> list[str]:
    out = []
    for it in items or []:
        if isinstance(it, dict):
            email = (it.get("email") or "").strip()
            if email:
                out.append(formataddr((it.get("name") or "", email)))
        elif isinstance(it, str) and it.strip():
            out.extend(a.strip() for a in it.split(",") if a.strip())
    return out


def build_message(db: Session, account: MailAccount, wp_user_id: Optional[int], *, to, cc=None, bcc=None, subject: str,
                  body_html: str, reply_to: Optional[MailMessage] = None, attachments: Optional[list[StoredFile]] = None,
                  with_signature: bool = True) -> tuple[EmailMessage, str, str]:
    safe_body, _ = parse.sanitize_html(body_html, images=True)
    signature = render_signature(db, wp_user_id, account) if with_signature else ""
    full_html = f'<div style="font-family:Arial,sans-serif;font-size:14px">{safe_body}</div>' + (f"<br>{signature}" if signature else "")
    if reply_to is not None:
        quoted = parse.sanitize_html(reply_to.body_html, images=True)[0] if reply_to.body_html else parse.text_to_html(reply_to.body_text)
        when = reply_to.date.strftime("%Y. %m. %d. %H:%M") if reply_to.date else ""
        who = html.escape(reply_to.from_name or reply_to.from_email)
        full_html += (f'<br><div style="color:#666">{when}, {who} írta:</div>'
                      f'<blockquote style="margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex">{quoted}</blockquote>')
    msg = EmailMessage()
    msg["From"] = formataddr((account.display_name or "", account.email))
    to_list, cc_list, bcc_list = _addr_list(to), _addr_list(cc), _addr_list(bcc)
    if not to_list:
        raise MailError("Adj meg címzettet.")
    msg["To"] = ", ".join(to_list)
    if cc_list:
        msg["Cc"] = ", ".join(cc_list)
    if bcc_list:
        msg["Bcc"] = ", ".join(bcc_list)
    msg["Subject"] = subject or ""
    msg["Date"] = formatdate(localtime=True)
    domain = account.email.split("@", 1)[-1] or "helloprovision.com"
    message_id = make_msgid(idstring=uuid.uuid4().hex[:12], domain=domain)
    msg["Message-ID"] = message_id
    if reply_to is not None and reply_to.message_id:
        msg["In-Reply-To"] = reply_to.message_id
        msg["References"] = (reply_to.references + " " + reply_to.message_id).strip()
    msg.set_content(parse.html_to_text(full_html))
    msg.add_alternative(full_html, subtype="html")
    for f in attachments or []:
        maintype, _, subtype = (f.mime or "application/octet-stream").partition("/")
        msg.add_attachment(storage.read(f), maintype=maintype, subtype=subtype or "octet-stream", filename=f.filename)
    return msg, full_html, message_id


def send(db: Session, account: MailAccount, wp_user_id: Optional[int], **kw) -> MailMessage:
    reply_to: Optional[MailMessage] = kw.get("reply_to")
    msg, full_html, message_id = build_message(db, account, wp_user_id, **kw)
    transport.send(account, msg)
    raw = bytes(msg)
    if not transport.saves_sent_automatically(account):
        try:
            transport.append_sent(account, account.sent_folder, raw)
        except MailError as e:
            log.warning("az elküldött levél nem került a szerver Elküldött mappájába (%s): %s", account.email, e)
    # Bcc nélkül tároljuk (a címzettek listáján ne látszódjon).
    to = parse.addresses(msg.get("To", ""))
    cc = parse.addresses(msg.get("Cc", ""))
    client = (reply_to.crm_client_id if reply_to is not None else None) or match_client(db, [x["email"] for x in to + cc], own_addresses(db))
    refs = msg.get("References", "") or ""
    sent = MailMessage(
        account_id=account.id, folder="sent", uid=None, message_id=message_id, in_reply_to=msg.get("In-Reply-To", "") or "",
        references=refs, thread_key=reply_to.thread_key if reply_to is not None and reply_to.thread_key else parse.thread_key(message_id, msg.get("In-Reply-To", ""), refs, msg["Subject"]),
        subject=msg["Subject"], from_email=account.email, from_name=account.display_name, to=to, cc=cc,
        date=datetime.now(timezone.utc), snippet=re.sub(r"\s+", " ", parse.html_to_text(full_html))[:280], body_text=parse.html_to_text(full_html),
        body_html=full_html, seen=True, crm_client_id=client, sent_by_wp_user_id=wp_user_id,
        attachments=[{"name": f.filename, "mime": f.mime, "size": f.size, "file_id": f.id} for f in kw.get("attachments") or []],
    )
    db.add(sent)
    db.flush()
    return sent


def cleanup_old_uploads(db: Session, older_than_days: int = 2) -> int:
    """Levélhez feltöltött, de soha el nem küldött csatolmányok törlése."""
    cutoff = utcnow() - timedelta(days=older_than_days)
    used: set[int] = set()
    for atts in db.scalars(select(MailMessage.attachments).where(MailMessage.folder == "sent")).all():
        used |= {a.get("file_id") for a in atts or [] if a.get("file_id")}
    n = 0
    for f in db.scalars(select(StoredFile).where(StoredFile.kind == "mail_upload", StoredFile.created_at < cutoff)).all():
        if f.id not in used:
            storage.delete(f)
            db.delete(f)
            n += 1
    return n
