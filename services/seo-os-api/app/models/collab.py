"""6. ütem: jóváhagyások (belső és ügyfél), megjegyzések, értesítések."""

from datetime import datetime
from typing import Any, Optional

from sqlalchemy import BigInteger, Boolean, CheckConstraint, DateTime, ForeignKey, Index, String, Text
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column

from ..db import Base, utcnow
from .core import _in

# Mire lehet megjegyzést írni / jóváhagyást kérni.
SUBJECTS = {
    "project": "Projekt",
    "document": "Dokumentum",
    "wireframe": "Wireframe",
    "page": "Oldal",
    "roadmap_item": "Roadmap tétel",
    "finding": "Audit megállapítás",
    "task": "Feladat",
}
APPROVAL_STAGES = {"internal": "Belső jóváhagyás", "client": "Ügyfél-jóváhagyás"}
APPROVAL_STATUSES = {"pending": "Függőben", "approved": "Jóváhagyva", "changes_requested": "Módosítást kért", "cancelled": "Visszavonva"}
NOTIFICATION_KINDS = {
    "approval_request": "Jóváhagyási kérés",
    "approval_decision": "Jóváhagyási döntés",
    "comment": "Megjegyzés",
    "mention": "Említés",
    "task_assigned": "Új feladat",
    "task_overdue": "Lejárt feladat",
    "upsell": "Upsell emlékeztető",
    "crawl": "Crawl",
    "document": "Dokumentum",
}


class Approval(Base):
    __tablename__ = "approvals"
    __table_args__ = (
        CheckConstraint(_in("stage", APPROVAL_STAGES), name="stage"),
        CheckConstraint(_in("status", APPROVAL_STATUSES), name="status"),
        CheckConstraint(_in("subject_type", SUBJECTS), name="subject_type"),
        Index("ix_approvals_subject", "subject_type", "subject_id"),
        Index("ix_approvals_pending", "status", "stage"),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"), index=True)
    subject_type: Mapped[str] = mapped_column(String(32))
    subject_id: Mapped[int] = mapped_column(BigInteger)
    stage: Mapped[str] = mapped_column(String(16))
    status: Mapped[str] = mapped_column(String(24), default="pending")
    title: Mapped[str] = mapped_column(String(512), default="")
    message: Mapped[str] = mapped_column(Text, default="")
    requested_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    # Belső: kitől kérjük (üres = bármelyik jóváhagyó). Ügyfél: token + címzett.
    requested_from: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    token: Mapped[Optional[str]] = mapped_column(String(64), unique=True)
    client_email: Mapped[str] = mapped_column(String(255), default="")
    language: Mapped[str] = mapped_column(String(8), default="hu")
    decided_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    decided_by_name: Mapped[str] = mapped_column(String(255), default="")
    decision_note: Mapped[str] = mapped_column(Text, default="")
    decided_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))
    viewed_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))
    expires_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)


class Comment(Base):
    __tablename__ = "comments"
    __table_args__ = (
        CheckConstraint(_in("subject_type", SUBJECTS), name="subject_type"),
        Index("ix_comments_subject", "subject_type", "subject_id"),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"), index=True)
    subject_type: Mapped[str] = mapped_column(String(32))
    subject_id: Mapped[int] = mapped_column(BigInteger)
    parent_id: Mapped[Optional[int]] = mapped_column(ForeignKey("comments.id", ondelete="CASCADE"))
    author_id: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    author_name: Mapped[str] = mapped_column(String(255), default="")
    is_client: Mapped[bool] = mapped_column(Boolean, default=False)
    body: Mapped[str] = mapped_column(Text)
    mentions: Mapped[list[int]] = mapped_column(JSONB, default=list)
    resolved: Mapped[bool] = mapped_column(Boolean, default=False)
    resolved_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    edited_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))


class Notification(Base):
    __tablename__ = "notifications"
    __table_args__ = (
        CheckConstraint(_in("kind", NOTIFICATION_KINDS), name="kind"),
        CheckConstraint(_in("email_status", ("pending", "sent", "skipped", "failed")), name="email_status"),
        Index("ix_notifications_user", "user_id", "read_at"),
        Index("ix_notifications_outbox", "email_status"),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"))
    project_id: Mapped[Optional[int]] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"))
    kind: Mapped[str] = mapped_column(String(32))
    title: Mapped[str] = mapped_column(String(512))
    body: Mapped[str] = mapped_column(Text, default="")
    link: Mapped[str] = mapped_column(String(512), default="")  # SPA útvonal, pl. #/projects/3?tab=documents&doc=7
    data: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    read_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))
    email_status: Mapped[str] = mapped_column(String(16), default="pending")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
