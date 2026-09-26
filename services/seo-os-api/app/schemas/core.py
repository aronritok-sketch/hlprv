"""1. ütem: kérés- és válaszmodellek."""

import re
from datetime import date, datetime
from typing import Any, Optional

from pydantic import BaseModel, ConfigDict, Field, field_validator

from ..domain import COMPETITOR_SOURCES, INTAKE_STATUSES, LANGUAGES, SCOPES, SEED_KINDS, STATUSES


def normalize_domain(value: str) -> str:
    v = (value or "").strip().lower()
    v = re.sub(r"^[a-z]+://", "", v)
    v = v.split("/")[0].split("?")[0].split("#")[0]
    v = re.sub(r"^www\.", "", v)
    return v


def _clean_list(values: list[str]) -> list[str]:
    seen, out = set(), []
    for v in values or []:
        v = " ".join(str(v).split())
        if v and v.lower() not in seen:
            seen.add(v.lower())
            out.append(v)
    return out


class Out(BaseModel):
    model_config = ConfigDict(from_attributes=True)


class UserOut(Out):
    id: int
    wp_user_id: int
    email: str
    display_name: str
    role: str
    is_active: bool
    last_seen_at: Optional[datetime] = None


class UserSync(BaseModel):
    email: str = ""
    display_name: str = ""
    role: str
    is_active: bool = True


class ClientIn(BaseModel):
    name: str = Field(min_length=1, max_length=255)
    crm_client_id: Optional[int] = None
    primary_domain: str = ""
    notes: str = ""

    @field_validator("primary_domain")
    @classmethod
    def _domain(cls, v: str) -> str:
        return normalize_domain(v)


class ClientPatch(BaseModel):
    name: Optional[str] = None
    primary_domain: Optional[str] = None
    notes: Optional[str] = None


class ClientOut(Out):
    id: int
    name: str
    crm_client_id: Optional[int]
    primary_domain: str
    notes: str


class ServiceItem(BaseModel):
    name: str = Field(min_length=1)
    high_margin: bool = False
    priority: bool = False


class CompetitorIn(BaseModel):
    domain: str
    source: str = "seo"
    notes: str = ""

    @field_validator("domain")
    @classmethod
    def _domain(cls, v: str) -> str:
        v = normalize_domain(v)
        if not v or "." not in v:
            raise ValueError("Érvénytelen domain.")
        return v

    @field_validator("source")
    @classmethod
    def _source(cls, v: str) -> str:
        if v not in COMPETITOR_SOURCES:
            raise ValueError("Ismeretlen forrás.")
        return v


class SeedIn(BaseModel):
    keyword: str = Field(min_length=1, max_length=255)
    kind: str = "service"

    @field_validator("keyword")
    @classmethod
    def _kw(cls, v: str) -> str:
        return " ".join(v.split())

    @field_validator("kind")
    @classmethod
    def _kind(cls, v: str) -> str:
        if v not in SEED_KINDS:
            raise ValueError("Ismeretlen kulcsszótípus.")
        return v


class ProjectBase(BaseModel):
    name: Optional[str] = None
    domain: Optional[str] = None
    industry: Optional[str] = None
    market: Optional[str] = None
    locations: Optional[list[str]] = None
    content_language: Optional[str] = None
    working_language: Optional[str] = None
    business_services: Optional[list[ServiceItem]] = None
    target_audience: Optional[str] = None
    business_goals: Optional[str] = None
    conversion_goals: Optional[str] = None
    excluded_topics: Optional[list[str]] = None
    scope: Optional[list[str]] = None
    owner_id: Optional[int] = None
    start_date: Optional[date] = None
    strategy_months: Optional[int] = Field(default=None, ge=1, le=24)
    content_per_month: Optional[int] = Field(default=None, ge=0, le=30)
    on_hold: Optional[bool] = None

    @field_validator("domain")
    @classmethod
    def _domain(cls, v):
        if v is None:
            return v
        v = normalize_domain(v)
        if not v or "." not in v:
            raise ValueError("Érvénytelen domain.")
        return v

    @field_validator("locations", "excluded_topics")
    @classmethod
    def _lists(cls, v):
        return None if v is None else _clean_list(v)

    @field_validator("scope")
    @classmethod
    def _scope(cls, v):
        if v is None:
            return v
        bad = [s for s in v if s not in SCOPES]
        if bad:
            raise ValueError("Ismeretlen szolgáltatás: " + ", ".join(bad))
        return list(dict.fromkeys(v))

    @field_validator("content_language", "working_language")
    @classmethod
    def _lang(cls, v):
        if v is not None and v not in LANGUAGES:
            raise ValueError("Ismeretlen nyelv.")
        return v

    @field_validator("market")
    @classmethod
    def _market(cls, v):
        return None if v is None else v.strip().upper()[:8]


class ProjectCreate(ProjectBase):
    name: str = Field(min_length=1, max_length=255)
    domain: str
    client_id: Optional[int] = None
    client: Optional[ClientIn] = None
    competitors: list[CompetitorIn] = []
    seed_keywords: list[SeedIn] = []


class ProjectPatch(ProjectBase):
    client_id: Optional[int] = None


class CompetitorOut(Out):
    id: int
    domain: str
    source: str
    is_active: bool
    notes: str


class SeedOut(Out):
    id: int
    keyword: str
    kind: str


class IntakeOut(Out):
    key: str
    status: str
    note: str
    updated_at: Optional[datetime] = None


class IntakePatch(BaseModel):
    status: Optional[str] = None
    note: Optional[str] = None

    @field_validator("status")
    @classmethod
    def _status(cls, v):
        if v is not None and v not in INTAKE_STATUSES:
            raise ValueError("Ismeretlen állapot.")
        return v


class TransitionIn(BaseModel):
    to: str
    note: str = ""
    force: bool = False

    @field_validator("to")
    @classmethod
    def _to(cls, v):
        if v not in STATUSES:
            raise ValueError("Ismeretlen státusz.")
        return v


class MemberIn(BaseModel):
    user_id: int
    project_role: str = ""


class ProjectSummary(Out):
    id: int
    name: str
    domain: str
    status: str
    on_hold: bool
    client: ClientOut
    owner: Optional[UserOut] = None
    scope: list[str]
    market: str
    locations: list[str]
    start_date: Optional[date] = None
    updated_at: datetime
    archived_at: Optional[datetime] = None


class ProjectOut(ProjectSummary):
    industry: str
    content_language: str
    working_language: str
    business_services: list[dict[str, Any]]
    target_audience: str
    business_goals: str
    conversion_goals: str
    excluded_topics: list[str]
    strategy_months: int
    content_per_month: int
    upsell_reminder_at: Optional[date] = None
    created_at: datetime
    competitors: list[CompetitorOut]
    seed_keywords: list[SeedOut]
    intake_items: list[IntakeOut]
