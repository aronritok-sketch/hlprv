"""Levelek feldolgozása: MIME → mezők, szöveg, biztonságos HTML, csatolmányok."""

import base64
import html
import re
from datetime import datetime, timezone
from email import policy
from email.message import EmailMessage
from email.parser import BytesParser
from email.utils import getaddresses, parseaddr, parsedate_to_datetime
from html.parser import HTMLParser
from typing import Any

MAX_INLINE_IMAGE = 300_000

ALLOWED_TAGS = {
    "a", "abbr", "b", "blockquote", "br", "caption", "center", "code", "col", "colgroup", "div", "em", "font", "h1", "h2",
    "h3", "h4", "h5", "h6", "hr", "i", "img", "li", "ol", "p", "pre", "s", "small", "span", "strike", "strong", "sub",
    "sup", "table", "tbody", "td", "tfoot", "th", "thead", "tr", "u", "ul",
}
DROP_WITH_CONTENT = {"script", "style", "head", "title", "iframe", "object", "embed", "noscript", "template", "svg", "math", "form"}
ALLOWED_ATTRS = {
    "href", "src", "alt", "title", "width", "height", "align", "valign", "bgcolor", "color", "border", "cellpadding",
    "cellspacing", "colspan", "rowspan", "style", "face", "size", "dir",
}
VOID = {"br", "hr", "img", "col"}
_BAD_STYLE = re.compile(r"expression|javascript:|url\s*\(|behavior|@import", re.I)


class _Sanitizer(HTMLParser):
    def __init__(self, images: bool, cid: dict[str, str]):
        super().__init__(convert_charrefs=True)
        self.out: list[str] = []
        self.skip = 0
        self.images = images
        self.cid = cid
        self.blocked_images = 0

    def handle_starttag(self, tag, attrs):
        if tag in DROP_WITH_CONTENT:
            self.skip += 1
            return
        if self.skip or tag not in ALLOWED_TAGS:
            return
        kept = []
        for name, value in attrs:
            name, value = name.lower(), (value or "")
            if name not in ALLOWED_ATTRS:
                continue
            if name == "style" and _BAD_STYLE.search(value):
                continue
            if name in ("href", "src"):
                v = value.strip()
                if v.lower().startswith("cid:"):
                    v = self.cid.get(v[4:].strip("<>"), "")
                    if not v:
                        continue
                elif name == "src" and re.match(r"https?:", v, re.I):
                    if not self.images:
                        self.blocked_images += 1
                        kept.append(("data-blocked-src", v))
                        continue
                elif not re.match(r"(https?:|mailto:|tel:|#|data:image/)", v, re.I):
                    continue
                value = v
            kept.append((name, value))
        if tag == "a":
            kept += [("target", "_blank"), ("rel", "noopener noreferrer")]
        attr = "".join(f' {k}="{html.escape(v, quote=True)}"' for k, v in kept)
        self.out.append(f"<{tag}{attr}>")

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)

    def handle_endtag(self, tag):
        if tag in DROP_WITH_CONTENT:
            self.skip = max(0, self.skip - 1)
            return
        if not self.skip and tag in ALLOWED_TAGS and tag not in VOID:
            self.out.append(f"</{tag}>")

    def handle_data(self, data):
        if not self.skip:
            self.out.append(html.escape(data, quote=False))


def sanitize_html(raw: str, images: bool = False, cid: dict[str, str] | None = None) -> tuple[str, int]:
    """Biztonságos HTML (nincs szkript, esemény, űrlap, külső stílus). A távoli képek alapból le vannak tiltva
    (követőpixel); a felület egy gombbal engedi. Visszaad: (html, letiltott képek száma)."""
    p = _Sanitizer(images, cid or {})
    p.feed(raw or "")
    p.close()
    return "".join(p.out), p.blocked_images


def html_to_text(raw: str) -> str:
    text = re.sub(r"(?is)<(script|style|head)[^>]*>.*?</\1>", " ", raw or "")
    text = re.sub(r"(?i)<br\s*/?>|</(p|div|tr|li|h\d)>", "\n", text)
    text = re.sub(r"<[^>]+>", "", text)
    text = html.unescape(text)
    text = re.sub(r"[ \t\r\f\v]+", " ", text)
    return re.sub(r"\n\s*\n\s*\n+", "\n\n", text).strip()


def text_to_html(text: str) -> str:
    esc = html.escape(text or "")
    esc = re.sub(r"(https?://[^\s<]+)", r'<a href="\1">\1</a>', esc)
    return "<div>" + esc.replace("\n", "<br>") + "</div>"


def addresses(value: str) -> list[dict[str, str]]:
    return [{"name": n, "email": e.lower()} for n, e in getaddresses([value or ""]) if e and "@" in e]


def normalize_subject(subject: str) -> str:
    s = subject or ""
    while True:
        new = re.sub(r"^\s*(re|fw|fwd|vs|válasz|továbbítás|aw|wg|tr)\s*(\[\d+\])?\s*:\s*", "", s, flags=re.I)
        if new == s:
            return s.strip().lower()
        s = new


def thread_key(message_id: str, in_reply_to: str, references: str, subject: str) -> str:
    """Szál azonosító: a hivatkozási lánc első eleme (az első levél Message-ID-je); ha nincs, a saját Message-ID."""
    refs = re.findall(r"<[^>]+>", references or "")
    if refs:
        return refs[0][:500]
    if in_reply_to:
        return in_reply_to.strip()[:500]
    if message_id:
        return message_id[:500]
    subj = normalize_subject(subject)
    return ("subj:" + subj)[:500] if subj else ""


def _decode(part: EmailMessage) -> str:
    try:
        return part.get_content()
    except Exception:  # noqa: BLE001 – hibás kódolású levélrész
        payload = part.get_payload(decode=True) or b""
        return payload.decode(part.get_content_charset() or "utf-8", errors="replace")


def parse(raw: bytes) -> dict[str, Any]:
    msg: EmailMessage = BytesParser(policy=policy.default).parsebytes(raw)
    text_parts: list[str] = []
    html_parts: list[str] = []
    attachments: list[dict[str, Any]] = []
    cid: dict[str, str] = {}
    for part in msg.walk():
        if part.is_multipart():
            continue
        ctype = part.get_content_type()
        disp = part.get_content_disposition()
        filename = part.get_filename()
        if disp in ("attachment", "inline") and (filename or ctype.startswith("image/")) and not (disp == "inline" and ctype in ("text/plain", "text/html") and not filename):
            data = part.get_payload(decode=True) or b""
            content_id = (part.get("Content-ID") or "").strip().strip("<>")
            if content_id and ctype.startswith("image/") and len(data) <= MAX_INLINE_IMAGE:
                cid[content_id] = f"data:{ctype};base64," + base64.b64encode(data).decode()
                if disp == "inline" and not filename:
                    continue
            attachments.append({"name": filename or f"melleklet.{ctype.split('/')[-1]}", "mime": ctype, "size": len(data), "data": data})
            continue
        if ctype == "text/plain":
            text_parts.append(_decode(part))
        elif ctype == "text/html":
            html_parts.append(_decode(part))
    body_html_raw = "\n".join(html_parts)
    body_text = "\n".join(text_parts).strip() or html_to_text(body_html_raw)
    safe_html, _ = sanitize_html(body_html_raw, images=True, cid=cid) if body_html_raw else ("", 0)
    frm_name, frm_email = parseaddr(msg.get("From", ""))
    try:
        date = parsedate_to_datetime(msg.get("Date")) if msg.get("Date") else None
        if date is not None and date.tzinfo is None:
            date = date.replace(tzinfo=timezone.utc)
    except (TypeError, ValueError):
        date = None
    message_id = (msg.get("Message-ID") or "").strip()[:500]
    in_reply_to = (msg.get("In-Reply-To") or "").strip()[:500]
    references = " ".join((msg.get("References") or "").split())
    subject = str(msg.get("Subject") or "")
    snippet = re.sub(r"\s+", " ", body_text)[:280]
    return {
        "message_id": message_id,
        "in_reply_to": in_reply_to,
        "references": references,
        "thread_key": thread_key(message_id, in_reply_to, references, subject),
        "subject": subject[:2000],
        "from_email": (frm_email or "").lower()[:320],
        "from_name": (frm_name or "")[:255],
        "to": addresses(msg.get("To", "")),
        "cc": addresses(msg.get("Cc", "")),
        "date": date or datetime.now(timezone.utc),
        "snippet": snippet,
        "body_text": body_text[:200_000],
        "body_html": safe_html[:500_000],
        "attachments": attachments,
    }
