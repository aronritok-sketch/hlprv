"""5. ütem: Screaming Frog crawlok (ügynök vagy kézi feltöltés), technikai audit (témák XL / M / S mérettel, megállapítások
URL-listával), crawler ügynökök nyilvántartása.

A téma- és megállapításkészlet a HelloProVision „Technikai SEO audit – összefoglaló” sablonját követi.
"""

from datetime import datetime
from typing import Any, Optional

from sqlalchemy import BigInteger, Boolean, CheckConstraint, DateTime, ForeignKey, Index, Integer, String, Text, UniqueConstraint
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column

from ..db import Base, utcnow
from .core import Timestamps, _in

CRAWL_SOURCES = {"agent": "Screaming Frog ügynök", "upload": "Kézi feltöltés"}
CRAWL_STATUSES = {
    "draft": "Feltöltés alatt",
    "queued": "Sorban áll",
    "running": "Crawl fut",
    "importing": "Feldolgozás",
    "done": "Kész",
    "failed": "Hiba",
    "cancelled": "Megszakítva",
}
AUDIT_SIZES = {"XL": "XL – kritikus", "M": "M – fontos", "S": "S – javasolt"}
FINDING_STATUSES = {"open": "Nyitott", "in_progress": "Javítás alatt", "fixed": "Javítva", "wontfix": "Nem javítjuk"}


class CrawlRun(Timestamps, Base):
    __tablename__ = "crawl_runs"
    __table_args__ = (
        CheckConstraint(_in("source", CRAWL_SOURCES), name="source"),
        CheckConstraint(_in("status", CRAWL_STATUSES), name="status"),
        Index("ix_crawl_runs_project", "project_id", "id"),
        Index("ix_crawl_runs_queue", "status", "source"),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    source: Mapped[str] = mapped_column(String(16))
    status: Mapped[str] = mapped_column(String(16), default="draft")
    start_url: Mapped[str] = mapped_column(Text, default="")
    # Ügynöknek: export fülek, bulk exportok, konfigurációs fájl neve, mentse-e a crawlt.
    settings: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    # Feltöltött fájlok: [{file_id, name, issue_key, rows}]
    files: Mapped[list[dict[str, Any]]] = mapped_column(JSONB, default=list)
    agent: Mapped[str] = mapped_column(String(128), default="")
    message: Mapped[str] = mapped_column(Text, default="")
    error: Mapped[str] = mapped_column(Text, default="")
    # Összesítés: {urls, html, indexable, findings: {issue_key: count}}
    stats: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    created_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    claimed_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))
    finished_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))


class AuditTopic(Base):
    """Projektenkénti témabeállítás: méret (XL/M/S), saját megfigyelés, javaslat, benne legyen-e a dokumentumban."""

    __tablename__ = "audit_topics"
    __table_args__ = (CheckConstraint(_in("size", AUDIT_SIZES), name="size"),)

    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"), primary_key=True)
    topic: Mapped[str] = mapped_column(String(32), primary_key=True)
    size: Mapped[str] = mapped_column(String(2))
    observation: Mapped[str] = mapped_column(Text, default="")
    recommendation: Mapped[str] = mapped_column(Text, default="")
    included: Mapped[bool] = mapped_column(Boolean, default=True)
    # Kézi mérőszámok, pl. PageSpeed pontszám.
    metrics: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    updated_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, onupdate=utcnow)


class AuditFinding(Timestamps, Base):
    __tablename__ = "audit_findings"
    __table_args__ = (
        CheckConstraint(_in("status", FINDING_STATUSES), name="status"),
        UniqueConstraint("project_id", "issue_key", name="uq_finding_issue"),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"), index=True)
    run_id: Mapped[Optional[int]] = mapped_column(ForeignKey("crawl_runs.id", ondelete="SET NULL"))
    topic: Mapped[str] = mapped_column(String(32))
    issue_key: Mapped[str] = mapped_column(String(64))
    count: Mapped[int] = mapped_column(Integer, default=0)
    previous_count: Mapped[Optional[int]] = mapped_column(Integer)
    # Érintett URL-ek (legfeljebb 2000): [{url, detail, from}]
    urls: Mapped[list[dict[str, Any]]] = mapped_column(JSONB, default=list)
    status: Mapped[str] = mapped_column(String(16), default="open")
    notes: Mapped[str] = mapped_column(Text, default="")
    task_id: Mapped[Optional[int]] = mapped_column(ForeignKey("production_tasks.id", ondelete="SET NULL"))


class CrawlerAgent(Base):
    __tablename__ = "crawler_agents"

    name: Mapped[str] = mapped_column(String(128), primary_key=True)
    info: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    last_seen_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
