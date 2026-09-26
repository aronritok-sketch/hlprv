"""Minden feladattípust tartalmazó modul betöltése (a @handler dekorátorok így regisztrálnak)."""

import importlib

MODULES = [
    "app.services.research",
    "app.services.analysis",
    "app.services.planning",
    "app.services.documents",
]


def load_handlers() -> None:
    for name in MODULES:
        importlib.import_module(name)
