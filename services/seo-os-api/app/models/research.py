"""2. ütem: fájlok, importok, kulcsszó-adatbázis, versenytárs-pozíciók, háttérfeladatok, stílusbeli irányelvek."""

from datetime import datetime
from typing import Any, Optional

from sqlalchemy import (
    BigInteger,
    Boolean,
    CheckConstraint,
    DateTime,
    ForeignKey,
    Index,
    Integer,
    Numeric,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.dialects.postgresql import ARRAY, JSONB
from sqlalchemy.orm import Mapped, mapped_column, relationship

from ..db import Base, utcnow
from .core import Timestamps, _in

IMPORT_SOURCES = {
    "ahrefs_keywords": "Ahrefs Keywords Explorer",
    "ahrefs_matching_terms": "Ahrefs Matching terms",
    "ahrefs_organic": "Ahrefs Organic keywords",
    "ahrefs_content_gap": "Ahrefs Content gap",
    "gkp": "Google Keyword Planner",
    "house_research": "Saját kulcsszókutatás tábla",
    "screaming_frog": "Screaming Frog export",
    "manual": "Kézi / egyéb tábla",
    "dataforseo": "DataForSEO",
    "ahrefs_api": "Ahrefs API",
}

JOB_STATUSES = ("queued", "running", "done", "failed")


class StoredFile(Base):
    __tablename__ = "files"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[Optional[int]] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    kind: Mapped[str] = mapped_column(String(32))  # import | reference_doc | export | crawl
    filename: Mapped[str] = mapped_column(String(255))
    mime: Mapped[str] = mapped_column(String(128), default="application/octet-stream")
    size: Mapped[int] = mapped_column(BigInteger, default=0)
    storage_key: Mapped[str] = mapped_column(String(512))
    sha256: Mapped[str] = mapped_column(String(64))
    uploaded_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)


class Import(Base):
    __tablename__ = "imports"
    __table_args__ = (
        CheckConstraint(_in("source", IMPORT_SOURCES), name="source"),
        CheckConstraint(_in("status", ("preview", "queued", "running", "done", "failed")), name="status"),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    file_id: Mapped[Optional[int]] = mapped_column(ForeignKey("files.id", ondelete="SET NULL"))
    source: Mapped[str] = mapped_column(String(32))
    sheet: Mapped[str] = mapped_column(String(255), default="")
    header_row: Mapped[int] = mapped_column(Integer, default=0)
    location: Mapped[str] = mapped_column(String(128), default="")
    column_map: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    # Versenytárs-oszlopok: {"domain.com": {"position": "col", "url": "col", "traffic": "col"}}
    competitor_map: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    row_count: Mapped[int] = mapped_column(Integer, default=0)
    stats: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    status: Mapped[str] = mapped_column(String(16), default="preview")
    error: Mapped[str] = mapped_column(Text, default="")
    created_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)

    file: Mapped[Optional[StoredFile]] = relationship(lazy="joined")


class Keyword(Timestamps, Base):
    __tablename__ = "keywords"
    __table_args__ = (
        UniqueConstraint("project_id", "term_normalized", name="uq_keyword_term"),
        Index("ix_keywords_project", "project_id"),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    term: Mapped[str] = mapped_column(String(512))
    term_normalized: Mapped[str] = mapped_column(String(512))
    translation: Mapped[str] = mapped_column(String(512), default="")
    parent_topic: Mapped[str] = mapped_column(String(512), default="")
    category: Mapped[str] = mapped_column(String(255), default="")
    # A kifejezésben szereplő település (a projekt lokációi közül), pl. "naples".
    term_location: Mapped[str] = mapped_column(String(128), default="")
    # Local long-tail módosító a saját táblából (pl. "near me").
    modifier: Mapped[str] = mapped_column(String(128), default="")
    # Az Ahrefs "Intents" oszlopa (Informational, Commercial, Local…) – az AI-osztályozás bemenete.
    source_intents: Mapped[list[str]] = mapped_column(ARRAY(Text), default=list, server_default="{}")
    serp_features: Mapped[list[str]] = mapped_column(ARRAY(Text), default=list, server_default="{}")
    is_excluded: Mapped[bool] = mapped_column(Boolean, default=False)
    exclusion_reason: Mapped[str] = mapped_column(String(255), default="")
    is_seed: Mapped[bool] = mapped_column(Boolean, default=False)
    sources: Mapped[list[str]] = mapped_column(ARRAY(Text), default=list, server_default="{}")
    first_import_id: Mapped[Optional[int]] = mapped_column(ForeignKey("imports.id", ondelete="SET NULL"))
    notes: Mapped[str] = mapped_column(Text, default="")

    metrics: Mapped[list["KeywordMetric"]] = relationship(cascade="all, delete-orphan", lazy="selectin")
    rankings: Mapped[list["CompetitorRanking"]] = relationship(cascade="all, delete-orphan", lazy="selectin")


class KeywordMetric(Base):
    __tablename__ = "keyword_metrics"
    __table_args__ = (UniqueConstraint("keyword_id", "location", "source", name="uq_metric"),)

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    keyword_id: Mapped[int] = mapped_column(ForeignKey("keywords.id", ondelete="CASCADE"))
    location: Mapped[str] = mapped_column(String(128), default="")  # üres = országos
    source: Mapped[str] = mapped_column(String(32))
    volume: Mapped[Optional[int]] = mapped_column(Integer)
    kd: Mapped[Optional[int]] = mapped_column(Integer)
    cpc: Mapped[Optional[float]] = mapped_column(Numeric(10, 2))
    currency: Mapped[str] = mapped_column(String(8), default="")
    traffic_potential: Mapped[Optional[int]] = mapped_column(Integer)
    competition: Mapped[str] = mapped_column(String(32), default="")
    trend: Mapped[list[int]] = mapped_column(ARRAY(Integer), default=list, server_default="{}")
    fetched_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)


class CompetitorRanking(Base):
    __tablename__ = "competitor_rankings"
    __table_args__ = (UniqueConstraint("keyword_id", "domain", "location", name="uq_ranking"),)

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    keyword_id: Mapped[int] = mapped_column(ForeignKey("keywords.id", ondelete="CASCADE"))
    # A saját domain is itt van (is_own = true) – így egy helyen látszik a teljes pozíciókép.
    domain: Mapped[str] = mapped_column(String(255))
    is_own: Mapped[bool] = mapped_column(Boolean, default=False)
    location: Mapped[str] = mapped_column(String(128), default="")
    position: Mapped[Optional[int]] = mapped_column(Integer)
    url: Mapped[str] = mapped_column(Text, default="")
    traffic: Mapped[Optional[int]] = mapped_column(Integer)
    source: Mapped[str] = mapped_column(String(32), default="")
    fetched_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)


class Job(Base):
    __tablename__ = "jobs"
    __table_args__ = (
        CheckConstraint(_in("status", JOB_STATUSES), name="status"),
        Index("ix_jobs_queue", "status", "id"),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[Optional[int]] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    type: Mapped[str] = mapped_column(String(64))
    payload: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    status: Mapped[str] = mapped_column(String(16), default="queued")
    progress: Mapped[float] = mapped_column(Numeric(5, 4), default=0)
    message: Mapped[str] = mapped_column(Text, default="")
    attempts: Mapped[int] = mapped_column(Integer, default=0)
    result: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    error: Mapped[str] = mapped_column(Text, default="")
    created_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    started_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))
    finished_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))


class StyleGuide(Base):
    """Stílusbeli irányelvek szövegíráshoz (az ügyfél tölti ki)."""

    __tablename__ = "style_guides"

    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"), primary_key=True)
    brand_name: Mapped[str] = mapped_column(String(255), default="")
    answers: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    received_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, onupdate=utcnow)


# A "Stílusbeli irányelvek szövegíráshoz – sablon" kérdései.
STYLE_QUESTIONS = [
    ("audience", "Ki a célközönség?", ""),
    ("tone", "Milyen stílusban íródjanak a cikkek? (formális/közvetlen)",
     "Formális: „A családalapításban gondolkodók örömmel nyugtázhatták, hogy a kormány több otthonteremtési állami támogatás esetében is a meghosszabbítás mellett döntött…”\n"
     "Közvetlen: „Jó hír a családok és a fiatal, családalapítást fontolgató párok számára, hogy idénre is megmaradtak…”"),
    ("depth", "Mennyire legyenek (mély)szakmaiak a cikkek?", ""),
    ("address", "Mi legyen a megszólítás?",
     "Milyen számban, személyben íródjanak a cikkek? Általában T/1-et használunk, egyes esetekben E/2-t. Tegezés/magázás?"),
    ("must_include", "Van valami, ami a márkával kapcsolatban mindenképp szerepeljen a cikkben?", ""),
    ("misconceptions", "Vannak gyakori félreértések/téves közvélekedések azzal kapcsolatban, amivel a márka foglalkozik?",
     "A szakmai pontosság szempontjából lényeges."),
    ("benchmarks", "Vannak olyan (akár külföldi) versenytársak/cégek, amelyek hasonló profillal rendelkeznek, és akiknek színvonalas a weboldala/blogja?", ""),
    ("banned", "Van olyan téma/kulcsszó/mondatszerkezet/marketingközhely, amit kerülni kell, esetleg tiltólistán van?", ""),
    ("knowledge", "Van olyan belsős tudásanyag, ami segítheti a szövegírást?", ""),
    ("example", "Példa cikk az oldalról, ami nagyon jól tükrözi a stílusotokat:", ""),
    ("sources", "Van olyan engedélyezett forrás, amit meg lehet jeleníteni?", ""),
]
