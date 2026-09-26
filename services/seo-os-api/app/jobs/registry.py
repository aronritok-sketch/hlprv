"""Minden feladattípust tartalmazó modul betöltése (a @handler dekorátorok így regisztrálnak)."""

import importlib

MODULES = [
    "app.services.research",
]


def load_handlers() -> None:
    for name in MODULES:
        importlib.import_module(name)
