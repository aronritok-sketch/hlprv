"""4. ütem: dokumentumok (ügyfél és belső), fájlváltozatok, referenciadokumentumok (saját minták), gyártási feladatok."""

from datetime import date, datetime
from typing import Any, Optional

from sqlalchemy import BigInteger, CheckConstraint, Date, DateTime, ForeignKey, Index, Integer, String, Text
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column, relationship

from ..db import Base, utcnow
from .core import Timestamps, _in

DOC_TYPES = {
    # ügyfél
    "seo_strategy": ("SEO stratégia", "client"),
    "content_strategy": ("Tartalomstratégia", "client"),
    "roadmap": ("Tartalmi roadmap", "client"),
    "wireframe_deck": ("Wireframe prezentáció", "client"),
    # belső
    "dev_brief": ("Fejlesztői brief", "internal"),
    "writer_brief": ("Szövegírói brief", "internal"),
    "designer_brief": ("Grafikusi brief", "internal"),
    "seo_checklist": ("SEO checklist", "internal"),
    "tech_audit": ("Technikai SEO audit", "client"),
    "monthly_report": ("Havi riport", "client"),
}
DOC_STATUSES = {"draft": "Vázlat", "review": "Ellenőrzésre", "approved": "Jóváhagyva", "sent": "Kiküldve"}
TASK_ROLES = {"seo": "SEO", "writer": "Szövegíró", "developer": "Fejlesztő", "designer": "Grafikus"}
TASK_STATUSES = {"todo": "Teendő", "in_progress": "Folyamatban", "review": "Ellenőrzés", "done": "Kész"}


class Document(Timestamps, Base):
    __tablename__ = "documents"
    __table_args__ = (
        CheckConstraint(_in("doc_type", DOC_TYPES), name="doc_type"),
        CheckConstraint(_in("status", DOC_STATUSES), name="status"),
        Index("ix_documents_project", "project_id", "doc_type"),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    doc_type: Mapped[str] = mapped_column(String(32))
    audience: Mapped[str] = mapped_column(String(16))
    language: Mapped[str] = mapped_column(String(8))
    version: Mapped[int] = mapped_column(Integer, default=1)
    status: Mapped[str] = mapped_column(String(16), default="draft")
    title: Mapped[str] = mapped_column(String(512))
    content: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    # A dokumentum alapjául szolgáló adatok lenyomata – ha változik, a dokumentum elavult.
    source_hash: Mapped[str] = mapped_column(String(64), default="")
    method: Mapped[str] = mapped_column(String(16), default="")  # claude | template
    model: Mapped[str] = mapped_column(String(128), default="")
    prompt_version: Mapped[str] = mapped_column(String(32), default="")
    created_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    approved_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    approved_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))
    sent_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))

    files: Mapped[list["DocumentFile"]] = relationship(cascade="all, delete-orphan", lazy="selectin")


class DocumentFile(Base):
    __tablename__ = "document_files"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    document_id: Mapped[int] = mapped_column(ForeignKey("documents.id", ondelete="CASCADE"))
    format: Mapped[str] = mapped_column(String(8))  # pdf | docx | xlsx
    file_id: Mapped[int] = mapped_column(ForeignKey("files.id", ondelete="CASCADE"))


class ReferenceDoc(Base):
    """Saját HelloProVision minták: a Claude ezekből veszi át a hangnemet és a felépítést."""

    __tablename__ = "reference_docs"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    doc_type: Mapped[str] = mapped_column(String(32))
    language: Mapped[str] = mapped_column(String(8), default="hu")
    title: Mapped[str] = mapped_column(String(255))
    file_id: Mapped[Optional[int]] = mapped_column(ForeignKey("files.id", ondelete="SET NULL"))
    extracted_text: Mapped[str] = mapped_column(Text, default="")
    is_active: Mapped[bool] = mapped_column(default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)


class ProductionTask(Timestamps, Base):
    __tablename__ = "production_tasks"
    __table_args__ = (
        CheckConstraint(_in("role", TASK_ROLES), name="role"),
        CheckConstraint(_in("status", TASK_STATUSES), name="status"),
        Index("ix_tasks_project", "project_id", "role"),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    page_id: Mapped[Optional[int]] = mapped_column(ForeignKey("pages.id", ondelete="SET NULL"))
    roadmap_item_id: Mapped[Optional[int]] = mapped_column(ForeignKey("roadmap_items.id", ondelete="SET NULL"))
    document_id: Mapped[Optional[int]] = mapped_column(ForeignKey("documents.id", ondelete="SET NULL"))
    role: Mapped[str] = mapped_column(String(16))
    priority: Mapped[str] = mapped_column(String(8), default="P2")
    title: Mapped[str] = mapped_column(String(512))
    source_url: Mapped[str] = mapped_column(Text, default="")
    action: Mapped[str] = mapped_column(Text, default="")
    target_url: Mapped[str] = mapped_column(Text, default="")
    done_when: Mapped[str] = mapped_column(Text, default="")
    notes: Mapped[str] = mapped_column(Text, default="")
    status: Mapped[str] = mapped_column(String(16), default="todo")
    assignee_id: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    due_date: Mapped[Optional[date]] = mapped_column(Date)
    crm_task_id: Mapped[Optional[int]] = mapped_column(BigInteger)
    key: Mapped[str] = mapped_column(String(128), default="")  # ismételt generálásnál ez alapján nem duplikál
    overdue_notified_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))
