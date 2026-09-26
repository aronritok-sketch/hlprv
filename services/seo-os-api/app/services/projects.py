"""Projektek: létrehozás, bekérési tételek, upsell-emlékeztető."""

from datetime import date

from fastapi import HTTPException
from sqlalchemy import select
from sqlalchemy.orm import Session

from ..domain import INTAKE_ITEMS, intake_applies
from ..models import Client, IntakeItem, Project, ProjectCompetitor, SeedKeyword


def get_project(db: Session, project_id: int) -> Project:
    project = db.get(Project, project_id)
    if project is None:
        raise HTTPException(404, "A projekt nem található.")
    return project


def add_months(d: date, months: int) -> date:
    y, m = divmod(d.month - 1 + months, 12)
    return date(d.year + y, m + 1, 1)


def upsell_date(project: Project) -> date | None:
    """Az 5. hónap eleje (a tartalommenedzsment 6 hónapos ciklusánál) – ekkor kell upsellelni."""
    if not project.start_date or not ({"content_mgmt", "wireframes_only"} & set(project.scope or [])):
        return None
    return add_months(project.start_date, max((project.strategy_months or 6) - 2, 1))


def sync_intake(project: Project) -> None:
    """A bekérési lista a terjedelemhez igazodik; a már kezelt tételekhez nem nyúl."""
    existing = {i.key: i for i in project.intake_items}
    for key in INTAKE_ITEMS:
        applies = intake_applies(key, project.scope or [])
        item = existing.get(key)
        if item is None:
            project.intake_items.append(IntakeItem(key=key, status="missing" if applies else "n_a"))
        elif item.status == "n_a" and applies and not item.note:
            item.status = "missing"
        elif item.status == "missing" and not applies:
            item.status = "n_a"
    # A domain a projekt létrehozásával megvan.
    for item in project.intake_items:
        if item.key == "domain" and item.status == "missing" and project.domain:
            item.status = "received"


def replace_competitors(project: Project, items) -> None:
    by_domain = {c.domain.lower(): c for c in project.competitors}
    keep, seen = [], set()
    for it in items:
        if it.domain.lower() in seen:
            continue
        seen.add(it.domain.lower())
        c = by_domain.get(it.domain.lower()) or ProjectCompetitor(domain=it.domain)
        c.source, c.notes = it.source, it.notes
        keep.append(c)
    project.competitors = keep


def replace_seeds(project: Project, items) -> None:
    by_kw = {s.keyword.lower(): s for s in project.seed_keywords}
    keep, seen = [], set()
    for it in items:
        if it.keyword.lower() in seen:
            continue
        seen.add(it.keyword.lower())
        s = by_kw.get(it.keyword.lower()) or SeedKeyword(keyword=it.keyword)
        s.kind = it.kind
        keep.append(s)
    project.seed_keywords = keep


def resolve_client(db: Session, client_id, client_in) -> Client:
    if client_id:
        client = db.get(Client, client_id)
        if client is None:
            raise HTTPException(404, "Az ügyfél nem található.")
        return client
    if client_in is None:
        raise HTTPException(422, "Ügyfél megadása kötelező.")
    if client_in.crm_client_id:
        found = db.scalar(select(Client).where(Client.crm_client_id == client_in.crm_client_id))
        if found:
            return found
    client = Client(**client_in.model_dump())
    db.add(client)
    db.flush()
    return client
