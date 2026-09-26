"""3. ütem: üzleti profil, kulcsszó-elemzés, klaszterek, oldalstruktúra, belső linkek, roadmap, mérési és paid terv,
wireframe-ek, AI-gyorsítótár.

AI-javaslat és emberi döntés: minden elemzett/generált rekord tárolja a legutóbbi AI-javaslatot (`ai` mező) és egy
állapotot. „suggested” = még az AI értéke; „accepted” = egy ember jóváhagyta; „overridden” = egy ember átírta.
Újrafuttatáskor az AI csak a „suggested” rekordokat írja felül, a többinél csak az `ai` mező frissül.
"""

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
    text,
)
from sqlalchemy.dialects.postgresql import ARRAY, JSONB
from sqlalchemy.orm import Mapped, mapped_column, relationship

from ..db import Base, utcnow
from .core import Timestamps, _in

INTENTS = {
    "informational": "Információs",
    "commercial_investigation": "Információs / kereskedelmi vizsgálódó",
    "commercial": "Kereskedelmi",
    "transactional": "Tranzakciós",
    "problem": "Problématudatos",
    "navigational": "Navigációs",
    "mixed": "Vegyes",
}
BUCKETS = {
    "commercial": "Kereskedelmi",
    "local": "Lokális",
    "comparison": "Összehasonlító",
    "problem": "Problémaalapú",
    "informational": "Információs",
}
PRIORITIES = {"P1": "P1", "P2": "P2", "parked": "Félretett"}
REVIEW_STATUSES = ("suggested", "accepted", "overridden")
PAGE_TYPES = {
    "home": "Főoldal",
    "service_hub": "Szolgáltatás (regionális)",
    "city_hub": "Városi hub",
    "city_service": "Városi szolgáltatási landing",
    "service": "Szolgáltatási aloldal",
    "category": "Kategóriaoldal",
    "product": "Termékoldal",
    "pillar": "Pillar / tudástár hub",
    "article": "Cikk",
    "case_study": "Case study",
    "support": "Támogató oldal",
}
PAGE_LIFECYCLE = {"new": "Új", "existing": "Meglévő", "update": "Meglévő, módosítandó", "redirect": "Átirányítandó", "merge": "Összevonandó", "remove": "Törlendő"}
LINK_TYPES = {"menu": "Menü", "button": "Gomb", "card": "Kártya", "text": "Szöveglink", "none": "Nincs link"}
ROADMAP_STATUSES = {
    "planned": "Tervezett",
    "wireframe": "Wireframe kész",
    "writing": "Szövegírás",
    "qa": "Ellenőrzés",
    "client": "Ügyfélnél",
    "published": "Publikálva",
}
CONTENT_TYPES = {"article": "Cikk", "case_study": "Case study", "service": "Szolgáltatási oldal", "pillar": "Pillar oldal", "category": "Kategóriaoldal", "listicle": "Listicle / checklist"}


class BusinessProfile(Base):
    __tablename__ = "business_profiles"
    __table_args__ = (UniqueConstraint("project_id", "version", name="uq_profile_version"),)

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    version: Mapped[int] = mapped_column(Integer, default=1)
    summary: Mapped[str] = mapped_column(Text, default="")
    positioning: Mapped[str] = mapped_column(Text, default="")
    services: Mapped[list[dict[str, Any]]] = mapped_column(JSONB, default=list)
    audiences: Mapped[list[dict[str, Any]]] = mapped_column(JSONB, default=list)
    differentiators: Mapped[list[str]] = mapped_column(ARRAY(Text), default=list)
    proof_assets: Mapped[list[str]] = mapped_column(ARRAY(Text), default=list)
    competitors_notes: Mapped[str] = mapped_column(Text, default="")
    site_snapshot: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    source: Mapped[str] = mapped_column(String(16), default="ai")
    approved_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    approved_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)


class Cluster(Timestamps, Base):
    __tablename__ = "clusters"
    __table_args__ = (Index("ix_clusters_project", "project_id"),)

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    name: Mapped[str] = mapped_column(String(255))
    pillar: Mapped[str] = mapped_column(String(255), default="")
    intent: Mapped[str] = mapped_column(String(32), default="")
    priority: Mapped[str] = mapped_column(String(8), default="")
    total_volume: Mapped[int] = mapped_column(Integer, default=0)
    target_page_id: Mapped[Optional[int]] = mapped_column(ForeignKey("pages.id", ondelete="SET NULL", use_alter=True))
    cannibalization_rule: Mapped[str] = mapped_column(Text, default="")
    notes: Mapped[str] = mapped_column(Text, default="")
    status: Mapped[str] = mapped_column(String(16), default="suggested")
    ai: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)


class KeywordAnalysis(Timestamps, Base):
    __tablename__ = "keyword_analysis"
    __table_args__ = (
        CheckConstraint(_in("status", REVIEW_STATUSES), name="status"),
        CheckConstraint("intent = '' OR " + _in("intent", INTENTS), name="intent"),
    )

    keyword_id: Mapped[int] = mapped_column(ForeignKey("keywords.id", ondelete="CASCADE"), primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"), index=True)
    intent: Mapped[str] = mapped_column(String(32), default="")
    bucket: Mapped[str] = mapped_column(String(16), default="")
    modifiers: Mapped[list[str]] = mapped_column(ARRAY(Text), default=list)
    is_local: Mapped[bool] = mapped_column(Boolean, default=False)
    business_value: Mapped[int] = mapped_column(Integer, default=3)
    commercial_opportunity: Mapped[int] = mapped_column(Integer, default=3)
    priority: Mapped[str] = mapped_column(String(8), default="")
    priority_score: Mapped[float] = mapped_column(Numeric(6, 4), default=0)
    cluster_id: Mapped[Optional[int]] = mapped_column(ForeignKey("clusters.id", ondelete="SET NULL"))
    reason: Mapped[str] = mapped_column(Text, default="")
    notes: Mapped[str] = mapped_column(Text, default="")
    status: Mapped[str] = mapped_column(String(16), default="suggested")
    method: Mapped[str] = mapped_column(String(16), default="")  # llm | heuristic
    ai: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    reviewed_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    reviewed_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))

    cluster: Mapped[Optional[Cluster]] = relationship(lazy="joined")


class Page(Timestamps, Base):
    __tablename__ = "pages"
    __table_args__ = (
        UniqueConstraint("project_id", "url", name="uq_page_url"),
        CheckConstraint(_in("page_type", PAGE_TYPES), name="page_type"),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"), index=True)
    url: Mapped[str] = mapped_column(String(512))
    page_type: Mapped[str] = mapped_column(String(32))
    parent_id: Mapped[Optional[int]] = mapped_column(ForeignKey("pages.id", ondelete="SET NULL"))
    location: Mapped[str] = mapped_column(String(128), default="")
    service: Mapped[str] = mapped_column(String(255), default="")
    title: Mapped[str] = mapped_column(String(255), default="")  # belső név
    seo_title: Mapped[str] = mapped_column(String(255), default="")
    h1: Mapped[str] = mapped_column(String(255), default="")
    meta_description: Mapped[str] = mapped_column(Text, default="")
    intent: Mapped[str] = mapped_column(String(32), default="")
    seo_goal: Mapped[str] = mapped_column(Text, default="")
    cta_label: Mapped[str] = mapped_column(String(255), default="")
    cta_url: Mapped[str] = mapped_column(String(512), default="")
    priority: Mapped[str] = mapped_column(String(16), default="")
    lifecycle: Mapped[str] = mapped_column(String(16), default="new")
    redirect_to: Mapped[str] = mapped_column(String(512), default="")
    schema_types: Mapped[list[str]] = mapped_column(ARRAY(Text), default=list)
    word_count_min: Mapped[Optional[int]] = mapped_column(Integer)
    word_count_max: Mapped[Optional[int]] = mapped_column(Integer)
    local_variation: Mapped[str] = mapped_column(Text, default="")
    notes: Mapped[str] = mapped_column(Text, default="")
    sort: Mapped[int] = mapped_column(Integer, default=0)
    status: Mapped[str] = mapped_column(String(16), default="suggested")
    ai: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)

    keywords: Mapped[list["PageKeyword"]] = relationship(cascade="all, delete-orphan", lazy="selectin")


class PageKeyword(Base):
    __tablename__ = "page_keywords"
    __table_args__ = (
        CheckConstraint(_in("role", ("primary", "secondary", "supporting")), name="role"),
        # Egy elsődleges kulcsszó = egy URL (kannibalizáció elleni védelem az adatbázisban).
        Index("uq_primary_keyword", "keyword_id", unique=True, postgresql_where=text("role = 'primary'")),
        Index("uq_page_primary", "page_id", unique=True, postgresql_where=text("role = 'primary'")),
    )

    page_id: Mapped[int] = mapped_column(ForeignKey("pages.id", ondelete="CASCADE"), primary_key=True)
    keyword_id: Mapped[int] = mapped_column(ForeignKey("keywords.id", ondelete="CASCADE"), primary_key=True)
    role: Mapped[str] = mapped_column(String(16), default="secondary")


class InternalLink(Base):
    __tablename__ = "internal_links"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"), index=True)
    from_page_id: Mapped[int] = mapped_column(ForeignKey("pages.id", ondelete="CASCADE"))
    to_page_id: Mapped[Optional[int]] = mapped_column(ForeignKey("pages.id", ondelete="CASCADE"))
    to_url: Mapped[str] = mapped_column(String(512), default="")
    anchor: Mapped[str] = mapped_column(String(255), default="")
    placement: Mapped[str] = mapped_column(String(255), default="")
    link_type: Mapped[str] = mapped_column(String(16), default="text")
    note: Mapped[str] = mapped_column(Text, default="")
    wireframe_section: Mapped[Optional[int]] = mapped_column(Integer)
    source: Mapped[str] = mapped_column(String(16), default="ai")


class RoadmapItem(Timestamps, Base):
    __tablename__ = "roadmap_items"
    __table_args__ = (Index("ix_roadmap_project", "project_id", "month"),)

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    month: Mapped[date] = mapped_column(Date)
    priority: Mapped[str] = mapped_column(String(8), default="P1")
    page_id: Mapped[Optional[int]] = mapped_column(ForeignKey("pages.id", ondelete="SET NULL"))
    cluster_id: Mapped[Optional[int]] = mapped_column(ForeignKey("clusters.id", ondelete="SET NULL"))
    keyword_id: Mapped[Optional[int]] = mapped_column(ForeignKey("keywords.id", ondelete="SET NULL"))
    content_type: Mapped[str] = mapped_column(String(32), default="article")
    pillar: Mapped[str] = mapped_column(String(255), default="")
    title: Mapped[str] = mapped_column(String(512), default="")
    url: Mapped[str] = mapped_column(String(512), default="")
    related_keywords: Mapped[list[str]] = mapped_column(ARRAY(Text), default=list)
    location_context: Mapped[str] = mapped_column(String(255), default="")
    content_direction: Mapped[str] = mapped_column(Text, default="")
    cta: Mapped[str] = mapped_column(String(255), default="")
    internal_links: Mapped[list[str]] = mapped_column(ARRAY(Text), default=list)
    social_hook: Mapped[str] = mapped_column(Text, default="")
    cannibalization_rule: Mapped[str] = mapped_column(Text, default="")
    client_input: Mapped[str] = mapped_column(Text, default="")
    status: Mapped[str] = mapped_column(String(16), default="planned")
    assignee_id: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    due_date: Mapped[Optional[date]] = mapped_column(Date)
    sort: Mapped[int] = mapped_column(Integer, default=0)
    review: Mapped[str] = mapped_column(String(16), default="suggested")
    ai: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)


class ParkedTopic(Base):
    __tablename__ = "parked_topics"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"), index=True)
    keyword_id: Mapped[Optional[int]] = mapped_column(ForeignKey("keywords.id", ondelete="SET NULL"))
    term: Mapped[str] = mapped_column(String(512), default="")
    pillar: Mapped[str] = mapped_column(String(255), default="")
    reason: Mapped[str] = mapped_column(Text, default="")
    recommended_handling: Mapped[str] = mapped_column(Text, default="")


class MeasurementItem(Base):
    __tablename__ = "measurement_plan"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"), index=True)
    sort: Mapped[int] = mapped_column(Integer, default=0)
    period: Mapped[str] = mapped_column(String(64))
    focus: Mapped[str] = mapped_column(Text, default="")
    what: Mapped[str] = mapped_column(Text, default="")
    where_measured: Mapped[str] = mapped_column(Text, default="")
    success_signal: Mapped[str] = mapped_column(Text, default="")
    decision: Mapped[str] = mapped_column(Text, default="")
    content_scope: Mapped[str] = mapped_column(Text, default="")
    owner: Mapped[str] = mapped_column(String(255), default="")
    status: Mapped[str] = mapped_column(String(32), default="Tervezett")
    note: Mapped[str] = mapped_column(Text, default="")


class PaidPlanItem(Base):
    __tablename__ = "paid_plan_items"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"), index=True)
    roadmap_item_id: Mapped[Optional[int]] = mapped_column(ForeignKey("roadmap_items.id", ondelete="CASCADE"))
    seo_role: Mapped[str] = mapped_column(Text, default="")
    meta_creative: Mapped[str] = mapped_column(Text, default="")
    paid_role: Mapped[str] = mapped_column(Text, default="")
    search_target: Mapped[str] = mapped_column(Text, default="")
    remarketing_next: Mapped[str] = mapped_column(Text, default="")
    kpi: Mapped[str] = mapped_column(Text, default="")


class Wireframe(Timestamps, Base):
    __tablename__ = "wireframes"
    __table_args__ = (UniqueConstraint("page_id", "version", name="uq_wireframe_version"),)

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"), index=True)
    page_id: Mapped[int] = mapped_column(ForeignKey("pages.id", ondelete="CASCADE"))
    roadmap_item_id: Mapped[Optional[int]] = mapped_column(ForeignKey("roadmap_items.id", ondelete="SET NULL"))
    version: Mapped[int] = mapped_column(Integer, default=1)
    label: Mapped[str] = mapped_column(String(255), default="")
    essence: Mapped[str] = mapped_column(Text, default="")
    flow: Mapped[list[str]] = mapped_column(ARRAY(Text), default=list)
    word_count_min: Mapped[Optional[int]] = mapped_column(Integer)
    word_count_max: Mapped[Optional[int]] = mapped_column(Integer)
    sections: Mapped[list[dict[str, Any]]] = mapped_column(JSONB, default=list)
    links: Mapped[list[dict[str, Any]]] = mapped_column(JSONB, default=list)
    proof_requirements: Mapped[str] = mapped_column(Text, default="")
    must_have: Mapped[list[str]] = mapped_column(ARRAY(Text), default=list)
    forbidden: Mapped[list[str]] = mapped_column(ARRAY(Text), default=list)
    visual_sequence: Mapped[str] = mapped_column(Text, default="")
    inputs: Mapped[list[dict[str, Any]]] = mapped_column(JSONB, default=list)
    ux_notes: Mapped[str] = mapped_column(Text, default="")
    status: Mapped[str] = mapped_column(String(16), default="draft")  # draft | review | approved
    method: Mapped[str] = mapped_column(String(16), default="")
    approved_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    approved_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))


class LlmCache(Base):
    __tablename__ = "llm_cache"

    key: Mapped[str] = mapped_column(String(64), primary_key=True)
    provider: Mapped[str] = mapped_column(String(16))
    model: Mapped[str] = mapped_column(String(128))
    response: Mapped[Any] = mapped_column(JSONB)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
