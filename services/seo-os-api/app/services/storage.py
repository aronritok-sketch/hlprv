"""Fájltár: helyi mappa (Docker kötet). Az útvonal projekt/azonosító alapú, a fájlnév csak metaadat."""

import hashlib
import re
import uuid
from pathlib import Path

from fastapi import HTTPException
from sqlalchemy.orm import Session

from ..config import get_settings
from ..models import StoredFile

MAX_UPLOAD = 50 * 1024 * 1024


def root() -> Path:
    p = Path(get_settings().storage_dir)
    p.mkdir(parents=True, exist_ok=True)
    return p


def safe_name(name: str) -> str:
    name = name.replace("\\", "/").split("/")[-1].strip() or "file"
    return re.sub(r"[^\w.\- ()–áéíóöőúüűÁÉÍÓÖŐÚÜŰ]", "_", name)[:200]


def save(db: Session, data: bytes, filename: str, kind: str, project_id: int | None, user_id: int | None, mime: str = "") -> StoredFile:
    if len(data) > MAX_UPLOAD:
        raise HTTPException(413, "A fájl túl nagy (legfeljebb 50 MB).")
    key = f"{project_id or 'global'}/{kind}/{uuid.uuid4().hex}"
    path = root() / key
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(data)
    f = StoredFile(
        project_id=project_id,
        kind=kind,
        filename=safe_name(filename),
        mime=mime or "application/octet-stream",
        size=len(data),
        storage_key=key,
        sha256=hashlib.sha256(data).hexdigest(),
        uploaded_by=user_id or None,
    )
    db.add(f)
    db.flush()
    return f


def path_of(f: StoredFile) -> Path:
    return root() / f.storage_key


def read(f: StoredFile) -> bytes:
    return path_of(f).read_bytes()


def delete(f: StoredFile) -> None:
    path_of(f).unlink(missing_ok=True)
