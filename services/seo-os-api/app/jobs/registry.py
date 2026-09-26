"""Minden feladattípust tartalmazó modul betöltése (a @handler dekorátorok így regisztrálnak)."""

import importlib

MODULES = [
    "app.services.research",
    "app.services.analysis",
    "app.services.planning",
    "app.services.documents",
    "app.services.audit",
    "app.services.api_research",
    "app.services.demo",
    "app.services.mail",
]


def load_handlers() -> None:
    for name in MODULES:
        importlib.import_module(name)
