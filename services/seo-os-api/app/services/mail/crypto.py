"""A postafiók-jelszavak titkosítása az adatbázisban (Fernet). A kulcs a SEO_OS_MAIL_KEY, ennek hiányában a közös
HMAC titokból származik – ha azt lecseréled, a postafiókok jelszavát újra meg kell adni."""

import base64
import hashlib

from cryptography.fernet import Fernet, InvalidToken

from ...config import get_settings


def _fernet() -> Fernet:
    s = get_settings()
    material = (s.mail_key or s.hmac_secret or "").encode()
    if not material:
        raise RuntimeError("Nincs titkosítási kulcs (SEO_OS_MAIL_KEY vagy SEO_OS_HMAC_SECRET).")
    key = base64.urlsafe_b64encode(hashlib.sha256(b"hpv-mail-v1:" + material).digest())
    return Fernet(key)


def encrypt(value: str) -> str:
    return _fernet().encrypt(value.encode()).decode() if value else ""


def decrypt(token: str) -> str:
    if not token:
        return ""
    try:
        return _fernet().decrypt(token.encode()).decode()
    except InvalidToken:
        return ""
