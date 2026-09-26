"""A WordPress bővítmény aláírt kéréseinek ellenőrzése.

A bővítmény minden kéréshez ezeket a fejléceket küldi:
  X-HPV-Timestamp  Unix idő (másodperc)
  X-HPV-User       WordPress felhasználó-azonosító (ügynöknél 0)
  X-HPV-Role       SEO OS szerepkör (admin | seo_manager | content_manager | designer | developer | agent)
  X-HPV-Email      e-mail cím (URL-kódolva)
  X-HPV-Name       megjelenített név (URL-kódolva)
  X-HPV-Signature  hex(HMAC-SHA256(titok, kanonikus szöveg))

Kanonikus szöveg (sorvége: \\n):
  METÓDUS, útvonal+lekérdezés, időbélyeg, sha256(törzs) hex, felhasználó, szerepkör, e-mail, név
"""

import hashlib
import hmac
import time
from dataclasses import dataclass
from urllib.parse import unquote

from fastapi import Depends, HTTPException, Request
from sqlalchemy import select
from sqlalchemy.orm import Session

from . import permissions
from .config import get_settings
from .db import get_db, utcnow
from .domain import ROLES
from .models import User


@dataclass
class CurrentUser:
    id: int  # seo.users.id (ügynöknél 0)
    wp_user_id: int
    role: str
    email: str
    name: str

    def can(self, cap: str) -> bool:
        return permissions.has(self.role, cap)

    def require(self, cap: str) -> None:
        if not self.can(cap):
            raise HTTPException(403, "Ehhez nincs jogosultságod.")


def canonical(method: str, path: str, ts: str, body: bytes, user: str, role: str, email: str, name: str) -> str:
    return "\n".join([method.upper(), path, ts, hashlib.sha256(body).hexdigest(), user, role, email, name])


def sign(secret: str, message: str) -> str:
    return hmac.new(secret.encode(), message.encode(), hashlib.sha256).hexdigest()


async def verify_request(request: Request) -> dict:
    settings = get_settings()
    if len(settings.hmac_secret) < 32:
        raise HTTPException(503, "A szerver nincs beállítva (SEO_OS_HMAC_SECRET).")
    h = request.headers
    ts = h.get("x-hpv-timestamp", "")
    sig = h.get("x-hpv-signature", "")
    user = h.get("x-hpv-user", "")
    role = h.get("x-hpv-role", "")
    email = h.get("x-hpv-email", "")
    name = h.get("x-hpv-name", "")
    if not (ts.isdigit() and sig and user.isdigit() and role):
        raise HTTPException(401, "Hiányzó aláírás.")
    if abs(time.time() - int(ts)) > settings.hmac_window:
        raise HTTPException(401, "Lejárt aláírás.")
    path = request.url.path + (("?" + request.url.query) if request.url.query else "")
    body = await request.body()
    expected = sign(settings.hmac_secret, canonical(request.method, path, ts, body, user, role, email, name))
    if not hmac.compare_digest(expected, sig):
        raise HTTPException(401, "Érvénytelen aláírás.")
    return {"wp_user_id": int(user), "role": role, "email": unquote(email), "name": unquote(name)}


async def current_user(request: Request, db: Session = Depends(get_db)) -> CurrentUser:
    info = await verify_request(request)
    if info["role"] not in ROLES:
        raise HTTPException(403, "Ismeretlen szerepkör.")
    user = db.scalar(select(User).where(User.wp_user_id == info["wp_user_id"]))
    now = utcnow()
    if user is None:
        user = User(wp_user_id=info["wp_user_id"], role=info["role"], email=info["email"], display_name=info["name"])
        db.add(user)
    elif not user.is_active:
        raise HTTPException(403, "A felhasználó ki van kapcsolva.")
    # A WordPress az igazságforrás: a szerepkör és a név minden kérésnél frissül.
    user.role, user.email, user.display_name, user.last_seen_at = info["role"], info["email"], info["name"], now
    db.commit()
    return CurrentUser(id=user.id, wp_user_id=user.wp_user_id, role=user.role, email=user.email, name=user.display_name)


async def current_agent(request: Request) -> None:
    """A Screaming Frog ügynök: WordPress-aláírás + saját Bearer token."""
    info = await verify_request(request)
    token = get_settings().agent_token
    given = request.headers.get("authorization", "").removeprefix("Bearer ").strip()
    if info["role"] != "agent" or not token or not hmac.compare_digest(token, given):
        raise HTTPException(401, "Érvénytelen ügynök token.")


async def current_system(request: Request) -> None:
    """A WordPress bővítmény saját, szerveroldali hívásai (ütemezett e-mail küldés, ügyfél-jóváhagyó oldal).
    A „system” szerepkört a bővítmény csak a saját kódjából küldi; a böngészőből érkező kérések a felhasználó
    szerepkörével vannak aláírva, így ezt nem érhetik el."""
    info = await verify_request(request)
    if info["role"] != "system":
        raise HTTPException(403, "Csak a rendszer hívhatja.")


def need(cap: str):
    """Függőség: a végponthoz szükséges jogosultság."""

    async def dep(user: CurrentUser = Depends(current_user)) -> CurrentUser:
        user.require(cap)
        return user

    return dep
