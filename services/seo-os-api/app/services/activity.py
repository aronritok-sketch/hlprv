"""Tevékenységnapló: ki, mikor, mit változtatott."""

from typing import Any, Optional

from sqlalchemy.orm import Session

from ..models import ActivityLog


def log(
    db: Session,
    project_id: Optional[int],
    user_id: Optional[int],
    entity: str,
    entity_id: Optional[int],
    action: str,
    summary: str = "",
    diff: Optional[dict[str, Any]] = None,
) -> None:
    db.add(
        ActivityLog(
            project_id=project_id,
            user_id=user_id or None,
            entity=entity,
            entity_id=entity_id,
            action=action,
            summary=summary,
            diff=diff or {},
        )
    )


def changes(obj: Any, data: dict[str, Any]) -> dict[str, Any]:
    """Beállítja a mezőket, és visszaadja a tényleges változásokat ({mező: [régi, új]})."""
    diff = {}
    for key, value in data.items():
        old = getattr(obj, key)
        if old != value:
            diff[key] = [_plain(old), _plain(value)]
            setattr(obj, key, value)
    return diff


def _plain(value: Any) -> Any:
    if hasattr(value, "isoformat"):
        return value.isoformat()
    return value
