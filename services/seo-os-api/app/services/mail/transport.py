"""IMAP (olvasás, szinkron) és SMTP (küldés). Bármely szabványos levelező működik vele: Google Workspace
(alkalmazásjelszóval), Microsoft 365, a tárhely saját levelezője. A kapcsolatot a tesztek helyettesíthetik."""

import imaplib
import re
import smtplib
import ssl
import time
from dataclasses import dataclass
from email.message import EmailMessage
from typing import Callable, Iterator, Optional

from . import crypto

TIMEOUT = 30
SENT_NAMES = ("Sent", "INBOX.Sent", "Sent Items", "Sent Messages", "[Gmail]/Sent Mail", "[Gmail]/Elküldött levelek",
              "Elküldött elemek", "Elküldött", "INBOX.Elküldött")


class MailError(Exception):
    """Felhasználónak szóló hiba (pl. rossz jelszó, nem elérhető szerver)."""


@dataclass
class Conn:
    host: str
    port: int
    security: str
    username: str
    password: str


def imap_conn(account) -> Conn:
    return Conn(account.imap_host, account.imap_port, account.imap_security, account.username or account.email, crypto.decrypt(account.secret_enc))


def smtp_conn(account) -> Conn:
    return Conn(account.smtp_host, account.smtp_port, account.smtp_security, account.username or account.email, crypto.decrypt(account.secret_enc))


# A tesztek ezt cserélik le (hamis IMAP/SMTP szerverre).
IMAP_FACTORY: Optional[Callable[[Conn], "imaplib.IMAP4"]] = None
SMTP_FACTORY: Optional[Callable[[Conn], "smtplib.SMTP"]] = None


def _friendly(e: Exception) -> str:
    text = str(e)
    if isinstance(e, (imaplib.IMAP4.error, smtplib.SMTPAuthenticationError)) and re.search(r"auth|login|credential|password|invalid", text, re.I):
        return "A levelezőszerver elutasította a belépést: hibás felhasználónév vagy jelszó (Google-nél alkalmazásjelszó kell)."
    if isinstance(e, (OSError, TimeoutError)):
        return f"A levelezőszerver nem érhető el ({text or type(e).__name__}). Ellenőrizd a címet és a portot."
    return text or type(e).__name__


def open_imap(c: Conn):
    if not c.host or not c.password:
        raise MailError("Hiányzik az IMAP szerver vagy a jelszó.")
    try:
        if IMAP_FACTORY:
            m = IMAP_FACTORY(c)
        elif c.security == "ssl":
            m = imaplib.IMAP4_SSL(c.host, c.port, ssl_context=ssl.create_default_context(), timeout=TIMEOUT)
        else:
            m = imaplib.IMAP4(c.host, c.port, timeout=TIMEOUT)
            if c.security == "starttls":
                m.starttls(ssl_context=ssl.create_default_context())
        m.login(c.username, c.password)
        return m
    except MailError:
        raise
    except Exception as e:  # noqa: BLE001
        raise MailError(_friendly(e)) from e


def open_smtp(c: Conn):
    if not c.host or not c.password:
        raise MailError("Hiányzik az SMTP szerver vagy a jelszó.")
    try:
        if SMTP_FACTORY:
            s = SMTP_FACTORY(c)
        elif c.security == "ssl":
            s = smtplib.SMTP_SSL(c.host, c.port, context=ssl.create_default_context(), timeout=TIMEOUT)
        else:
            s = smtplib.SMTP(c.host, c.port, timeout=TIMEOUT)
            if c.security == "starttls":
                s.starttls(context=ssl.create_default_context())
        s.login(c.username, c.password)
        return s
    except MailError:
        raise
    except Exception as e:  # noqa: BLE001
        raise MailError(_friendly(e)) from e


def _quote(folder: str) -> str:
    return '"' + folder.replace("\\", "\\\\").replace('"', '\\"') + '"'


def find_sent_folder(m) -> str:
    typ, data = m.list()
    names: list[tuple[str, str]] = []
    for raw in data or []:
        line = raw.decode(errors="replace") if isinstance(raw, bytes) else str(raw)
        mm = re.match(r'\((?P<flags>[^)]*)\)\s+(?:"[^"]*"|NIL)\s+(?P<name>.+)$', line)
        if not mm:
            continue
        name = mm.group("name").strip()
        if name.startswith('"') and name.endswith('"'):
            name = name[1:-1].replace('\\"', '"')
        names.append((mm.group("flags"), name))
    for flags, name in names:
        if "\\Sent" in flags:
            return name
    lower = {n.lower(): n for _, n in names}
    for cand in SENT_NAMES:
        if cand.lower() in lower:
            return lower[cand.lower()]
    return ""


def select(m, folder: str) -> tuple[int, int]:
    """Mappa megnyitása csak olvasásra. Visszaad: (uidvalidity, üzenetek száma)."""
    typ, data = m.select(_quote(folder), readonly=True)
    if typ != "OK":
        raise MailError(f"A(z) {folder} mappa nem nyitható meg.")
    count = int((data or [b"0"])[0] or 0)
    typ, resp = m.response("UIDVALIDITY")
    uidvalidity = int((resp or [b"0"])[0] or 0) if resp and resp[0] else 0
    return uidvalidity, count


def search_uids(m, since_uid: int, since_days: int) -> list[int]:
    if since_uid:
        typ, data = m.uid("SEARCH", None, f"UID {since_uid + 1}:*")
    else:
        since = time.strftime("%d-%b-%Y", time.gmtime(time.time() - since_days * 86400))
        typ, data = m.uid("SEARCH", None, f"SINCE {since}")
    uids = [int(x) for x in (data[0] or b"").split()] if typ == "OK" and data else []
    return sorted(u for u in uids if u > since_uid)


def fetch(m, uids: list[int]) -> Iterator[tuple[int, bytes, bool, bool]]:
    """(uid, nyers levél, olvasott, csillagos) – 20-as csomagokban."""
    for i in range(0, len(uids), 20):
        chunk = ",".join(str(u) for u in uids[i:i + 20])
        typ, data = m.uid("FETCH", chunk, "(UID FLAGS BODY.PEEK[])")
        if typ != "OK":
            continue
        for item in data or []:
            if not isinstance(item, tuple) or len(item) < 2:
                continue
            head = item[0].decode(errors="replace") if isinstance(item[0], bytes) else str(item[0])
            mu = re.search(r"UID (\d+)", head)
            if not mu:
                continue
            flags = re.search(r"FLAGS \(([^)]*)\)", head)
            flag_text = flags.group(1) if flags else ""
            yield int(mu.group(1)), item[1], "\\Seen" in flag_text, "\\Flagged" in flag_text


def set_flag(account, folder_name: str, uid: int, flag: str, on: bool) -> None:
    m = open_imap(imap_conn(account))
    try:
        if m.select(_quote(folder_name))[0] == "OK":
            m.uid("STORE", str(uid), "+FLAGS" if on else "-FLAGS", f"({flag})")
    finally:
        _logout(m)


def append_sent(account, folder_name: str, raw: bytes) -> None:
    if not folder_name:
        return
    m = open_imap(imap_conn(account))
    try:
        m.append(_quote(folder_name), "(\\Seen)", imaplib.Time2Internaldate(time.time()), raw)
    finally:
        _logout(m)


def send(account, msg: EmailMessage) -> None:
    s = open_smtp(smtp_conn(account))
    try:
        s.send_message(msg)
    except Exception as e:  # noqa: BLE001
        raise MailError("A levél nem ment ki: " + _friendly(e)) from e
    finally:
        try:
            s.quit()
        except Exception:  # noqa: BLE001
            pass


def _logout(m) -> None:
    try:
        m.logout()
    except Exception:  # noqa: BLE001
        pass


def saves_sent_automatically(account) -> bool:
    """A Gmail és a Microsoft 365 az SMTP-n küldött levelet magától elteszi az Elküldöttek közé."""
    host = (account.smtp_host or "").lower()
    return "gmail.com" in host or "googlemail.com" in host or "office365.com" in host or "outlook.com" in host


def test_account(account) -> dict:
    out = {"imap": "", "smtp": "", "sent_folder": ""}
    m = open_imap(imap_conn(account))
    try:
        select(m, "INBOX")
        out["sent_folder"] = find_sent_folder(m)
        out["imap"] = "ok"
    finally:
        _logout(m)
    s = open_smtp(smtp_conn(account))
    try:
        out["smtp"] = "ok"
    finally:
        try:
            s.quit()
        except Exception:  # noqa: BLE001
            pass
    return out
