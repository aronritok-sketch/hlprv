"""1. ütem: felhasználók, ügyfelek, projektek, bekérés, státusztörténet, naplók."""

from datetime import date, datetime
from typing import Any, Optional

from sqlalchemy import (
    BigInteger,
    Boolean,
    CheckConstraint,
    Date,
    DateTime,
    ForeignKey,
    Index,
    Integer,
    Numeric,
    String,
    Text,
    UniqueConstraint,
    func,
    text,
)
from sqlalchemy.dialects.postgresql import ARRAY, JSONB
from sqlalchemy.orm import Mapped, mapped_column, relationship

from ..db import Base, utcnow
from ..domain import INTAKE_STATUSES, ROLES, SEED_KINDS, STATUSES


def _in(column: str, values) -> str:
    return f"{column} IN (" + ", ".join(f"'{v}'" for v in values) + ")"


class Timestamps:
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, onupdate=utcnow, server_default=func.now()
    )


class User(Timestamps, Base):
    __tablename__ = "users"
    __table_args__ = (CheckConstraint(_in("role", ROLES), name="role"),)

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    wp_user_id: Mapped[int] = mapped_column(BigInteger, unique=True)
    email: Mapped[str] = mapped_column(String(255), default="")
    display_name: Mapped[str] = mapped_column(String(255), default="")
    role: Mapped[str] = mapped_column(String(32))
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, server_default=text("true"))
    last_seen_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))


class Client(Timestamps, Base):
    __tablename__ = "clients"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    name: Mapped[str] = mapped_column(String(255))
    # A CRM ügyfél (wp_hpv_clients.id) – ha a projekt CRM-ügyfélhez tartozik.
    crm_client_id: Mapped[Optional[int]] = mapped_column(BigInteger, unique=True)
    primary_domain: Mapped[str] = mapped_column(String(255), default="")
    notes: Mapped[str] = mapped_column(Text, default="")


class Project(Timestamps, Base):
    __tablename__ = "projects"
    __table_args__ = (
        CheckConstraint(_in("status", STATUSES), name="status"),
        Index("ix_projects_status", "status"),
        Index("ix_projects_owner", "owner_id"),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    client_id: Mapped[int] = mapped_column(ForeignKey("clients.id", ondelete="RESTRICT"))
    name: Mapped[str] = mapped_column(String(255))
    domain: Mapped[str] = mapped_column(String(255))
    industry: Mapped[str] = mapped_column(String(255), default="")
    market: Mapped[str] = mapped_column(String(8), default="US")
    locations: Mapped[list[str]] = mapped_column(ARRAY(Text), default=list, server_default="{}")
    content_language: Mapped[str] = mapped_column(String(8), default="en-US")
    working_language: Mapped[str] = mapped_column(String(8), default="hu")
    # [{name, high_margin, priority}]
    business_services: Mapped[list[dict[str, Any]]] = mapped_column(JSONB, default=list, server_default="[]")
    target_audience: Mapped[str] = mapped_column(Text, default="")
    business_goals: Mapped[str] = mapped_column(Text, default="")
    conversion_goals: Mapped[str] = mapped_column(Text, default="")
    excluded_topics: Mapped[list[str]] = mapped_column(ARRAY(Text), default=list, server_default="{}")
    scope: Mapped[list[str]] = mapped_column(ARRAY(Text), default=list, server_default="{}")
    status: Mapped[str] = mapped_column(String(32), default="draft", server_default="draft")
    on_hold: Mapped[bool] = mapped_column(Boolean, default=False, server_default=text("false"))
    owner_id: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    start_date: Mapped[Optional[date]] = mapped_column(Date)
    strategy_months: Mapped[int] = mapped_column(Integer, default=6, server_default="6")
    content_per_month: Mapped[int] = mapped_column(Integer, default=2, server_default="2")
    upsell_reminder_at: Mapped[Optional[date]] = mapped_column(Date)
    upsell_notified_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))
    archived_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))

    client: Mapped[Client] = relationship(lazy="joined")
    owner: Mapped[Optional[User]] = relationship(lazy="joined")
    competitors: Mapped[list["ProjectCompetitor"]] = relationship(
        cascade="all, delete-orphan", order_by="ProjectCompetitor.id"
    )
    seed_keywords: Mapped[list["SeedKeyword"]] = relationship(cascade="all, delete-orphan", order_by="SeedKeyword.id")
    intake_items: Mapped[list["IntakeItem"]] = relationship(cascade="all, delete-orphan", order_by="IntakeItem.id")
    members: Mapped[list["ProjectMember"]] = relationship(cascade="all, delete-orphan")


class ProjectMember(Base):
    __tablename__ = "project_members"

    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"), primary_key=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), primary_key=True)
    project_role: Mapped[str] = mapped_column(String(32), default="")

    user: Mapped[User] = relationship(lazy="joined")


class ProjectCompetitor(Base):
    __tablename__ = "project_competitors"
    __table_args__ = (Index("uq_competitor_domain", "project_id", text("lower(domain)"), unique=True),)

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    domain: Mapped[str] = mapped_column(String(255))
    source: Mapped[str] = mapped_column(String(16), default="seo")
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, server_default=text("true"))
    notes: Mapped[str] = mapped_column(Text, default="")


class SeedKeyword(Base):
    __tablename__ = "seed_keywords"
    __table_args__ = (
        CheckConstraint(_in("kind", SEED_KINDS), name="kind"),
        Index("uq_seed_keyword", "project_id", text("lower(keyword)"), unique=True),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    keyword: Mapped[str] = mapped_column(String(255))
    kind: Mapped[str] = mapped_column(String(32), default="service")


class IntakeItem(Base):
    __tablename__ = "intake_items"
    __table_args__ = (
        CheckConstraint(_in("status", INTAKE_STATUSES), name="status"),
        UniqueConstraint("project_id", "key", name="uq_intake_key"),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    key: Mapped[str] = mapped_column(String(32))
    status: Mapped[str] = mapped_column(String(16), default="missing")
    note: Mapped[str] = mapped_column(Text, default="")
    updated_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, onupdate=utcnow)


class StatusHistory(Base):
    __tablename__ = "status_history"
    __table_args__ = (Index("ix_status_history_project", "project_id", "at"),)

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    from_status: Mapped[Optional[str]] = mapped_column(String(32))
    to_status: Mapped[str] = mapped_column(String(32))
    user_id: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    note: Mapped[str] = mapped_column(Text, default="")
    at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)

    user: Mapped[Optional[User]] = relationship(lazy="joined")


class ActivityLog(Base):
    __tablename__ = "activity_log"
    __table_args__ = (Index("ix_activity_project", "project_id", "at"),)

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[Optional[int]] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    user_id: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    entity: Mapped[str] = mapped_column(String(64))
    entity_id: Mapped[Optional[int]] = mapped_column(BigInteger)
    action: Mapped[str] = mapped_column(String(64))
    summary: Mapped[str] = mapped_column(Text, default="")
    diff: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict, server_default="{}")
    at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)

    user: Mapped[Optional[User]] = relationship(lazy="joined")


class ApiLog(Base):
    __tablename__ = "api_logs"
    __table_args__ = (Index("ix_api_logs_project", "project_id", "created_at"),)

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[Optional[int]] = mapped_column(ForeignKey("projects.id", ondelete="SET NULL"))
    job_id: Mapped[Optional[int]] = mapped_column(BigInteger)
    provider: Mapped[str] = mapped_column(String(32))
    endpoint: Mapped[str] = mapped_column(String(255))
    model: Mapped[str] = mapped_column(String(128), default="")
    request_hash: Mapped[str] = mapped_column(String(64), default="")
    status_code: Mapped[Optional[int]] = mapped_column(Integer)
    tokens_in: Mapped[int] = mapped_column(Integer, default=0)
    tokens_out: Mapped[int] = mapped_column(Integer, default=0)
    units: Mapped[float] = mapped_column(Numeric(12, 4), default=0)
    cost_usd: Mapped[float] = mapped_column(Numeric(12, 6), default=0)
    duration_ms: Mapped[int] = mapped_column(Integer, default=0)
    error: Mapped[str] = mapped_column(Text, default="")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)


class AppSetting(Base):
    """Admin által szerkeszthető beállítások (API kulcsok, modellek, pontozási súlyok)."""

    __tablename__ = "app_settings"

    key: Mapped[str] = mapped_column(String(64), primary_key=True)
    value: Mapped[Any] = mapped_column(JSONB)
    is_secret: Mapped[bool] = mapped_column(Boolean, default=False)
    updated_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, onupdate=utcnow)
