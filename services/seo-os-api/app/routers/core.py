"""Állapot, saját adatok, szótár, csapat, ügyfelek, beállítások."""

from typing import Any, Optional

from fastapi import APIRouter, Body, Depends, HTTPException
from sqlalchemy import or_, select
from sqlalchemy.orm import Session

from .. import domain, permissions
from ..auth import CurrentUser, current_user, need, verify_request
from ..db import get_db
from ..models import Client, User
from ..schemas.core import ClientIn, ClientOut, ClientPatch, UserOut, UserSync
from ..services import settings as app_settings
from ..services.activity import changes, log

router = APIRouter()


@router.get("/health")
def health(db: Session = Depends(get_db)):
    db.execute(select(1))
    return {"ok": True}


@router.get("/me")
def me(user: CurrentUser = Depends(current_user)):
    return {
        "id": user.id,
        "wp_user_id": user.wp_user_id,
        "name": user.name,
        "email": user.email,
        "role": user.role,
        "role_label": domain.ROLES[user.role],
        "caps": permissions.caps_for(user.role),
    }


@router.get("/meta")
def meta(user: CurrentUser = Depends(current_user)):
    """A felület szótára: címkék, sorrendek. Egy helyen van, a frontend nem duplikálja."""
    from ..meta import META

    return META


@router.get("/users")
def list_users(db: Session = Depends(get_db), user: CurrentUser = Depends(need("team.view"))):
    rows = db.scalars(select(User).order_by(User.display_name)).all()
    return [UserOut.model_validate(u).model_dump(mode="json") for u in rows]


@router.put("/users/{wp_user_id}")
async def sync_user(wp_user_id: int, body: UserSync, db: Session = Depends(get_db), info: dict = Depends(verify_request)):
    """A WordPress bővítmény hívja, ha egy felhasználó szerepköre vagy neve változik (vagy törlik)."""
    if info["role"] != "admin":
        raise HTTPException(403, "Csak adminisztrátor szinkronizálhat.")
    if body.role not in domain.ROLES:
        raise HTTPException(422, "Ismeretlen szerepkör.")
    user = db.scalar(select(User).where(User.wp_user_id == wp_user_id))
    if user is None:
        user = User(wp_user_id=wp_user_id, role=body.role)
        db.add(user)
    user.email, user.display_name, user.role, user.is_active = body.email, body.display_name, body.role, body.is_active
    db.commit()
    return UserOut.model_validate(user).model_dump(mode="json")


@router.get("/clients")
def list_clients(search: Optional[str] = None, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    stmt = select(Client).order_by(Client.name)
    if search:
        like = f"%{search.strip()}%"
        stmt = stmt.where(or_(Client.name.ilike(like), Client.primary_domain.ilike(like)))
    return [ClientOut.model_validate(c).model_dump() for c in db.scalars(stmt.limit(200)).all()]


@router.post("/clients", status_code=201)
def create_client(body: ClientIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.create"))):
    if body.crm_client_id and db.scalar(select(Client).where(Client.crm_client_id == body.crm_client_id)):
        raise HTTPException(409, "Ez a CRM ügyfél már szerepel.")
    client = Client(**body.model_dump())
    db.add(client)
    db.flush()
    log(db, None, user.id, "client", client.id, "create", client.name)
    db.commit()
    return ClientOut.model_validate(client).model_dump()


@router.patch("/clients/{client_id}")
def update_client(
    client_id: int, body: ClientPatch, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.edit"))
):
    client = db.get(Client, client_id)
    if client is None:
        raise HTTPException(404, "Az ügyfél nem található.")
    data = body.model_dump(exclude_unset=True, exclude_none=True)
    if "primary_domain" in data:
        data["primary_domain"] = ClientIn(name="x", primary_domain=data["primary_domain"]).primary_domain
    diff = changes(client, data)
    if diff:
        log(db, None, user.id, "client", client.id, "update", client.name, diff)
    db.commit()
    return ClientOut.model_validate(client).model_dump()


@router.get("/settings")
def get_app_settings(db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))):
    return app_settings.public_view(db)


@router.put("/settings")
def put_app_settings(
    body: dict[str, Any] = Body(...), db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))
):
    for key, value in body.items():
        if key not in app_settings.DEFINITIONS:
            raise HTTPException(422, f"Ismeretlen beállítás: {key}")
        # Titkos mezőnél a maszkolt érték visszaküldése nem írja felül a kulcsot.
        if app_settings.DEFINITIONS[key][1] and isinstance(value, str) and value.startswith("••••"):
            continue
        app_settings.set_value(db, key, value, user.id)
    log(db, None, user.id, "settings", None, "update", "Beállítások: " + ", ".join(sorted(body)))
    db.commit()
    return app_settings.public_view(db)
