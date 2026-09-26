"""A /meta végpont tartalma: minden címke és sorrend, amit a felület megjelenít."""

from . import domain

META: dict = {
    "roles": domain.ROLES,
    "statuses": domain.STATUSES,
    "status_order": domain.STATUS_ORDER,
    "scopes": domain.SCOPES,
    "intake_items": {k: v[0] for k, v in domain.INTAKE_ITEMS.items()},
    "intake_statuses": domain.INTAKE_STATUSES,
    "seed_kinds": domain.SEED_KINDS,
    "competitor_sources": domain.COMPETITOR_SOURCES,
    "languages": domain.LANGUAGES,
}


def extend(key: str, value) -> None:
    """A későbbi ütemek moduljai ezzel bővítik a szótárat."""
    META[key] = value
