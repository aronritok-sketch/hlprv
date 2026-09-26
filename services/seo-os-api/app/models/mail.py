"""Levelezés a CRM-ben: postafiókok (IMAP/SMTP), szinkronizált levelek, CRM-ügyfél kapcsolat, egységes aláírás."""

from datetime import datetime
from typing import Any, Optional

from sqlalchemy import BigInteger, Boolean, DateTime, ForeignKey, Index, Integer, String, Text, UniqueConstraint
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column, relationship

from ..db import Base, utcnow

SECURITY = ("ssl", "starttls", "none")


class MailAccount(Base):
    """Egy postafiók. owner_wp_user_id üres = közös fiók (pl. info@), minden munkatárs látja."""

    __tablename__ = "mail_accounts"

    id: Mapped[int] = mapped_column(primary_key=True)
    owner_wp_user_id: Mapped[Optional[int]] = mapped_column(Integer, index=True)
    email: Mapped[str] = mapped_column(String(255))
    display_name: Mapped[str] = mapped_column(String(255), default="")
    provider: Mapped[str] = mapped_column(String(16), default="imap")
    imap_host: Mapped[str] = mapped_column(String(255), default="")
    imap_port: Mapped[int] = mapped_column(Integer, default=993)
    imap_security: Mapped[str] = mapped_column(String(8), default="ssl")
    smtp_host: Mapped[str] = mapped_column(String(255), default="")
    smtp_port: Mapped[int] = mapped_column(Integer, default=465)
    smtp_security: Mapped[str] = mapped_column(String(8), default="ssl")
    username: Mapped[str] = mapped_column(String(255), default="")
    secret_enc: Mapped[str] = mapped_column(Text, default="")
    sent_folder: Mapped[str] = mapped_column(String(255), default="")
    sync_state: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)  # mappánként {uidvalidity, last_uid}
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    last_sync_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True))
    last_error: Mapped[str] = mapped_column(Text, default="")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, onupdate=utcnow)

    messages: Mapped[list["MailMessage"]] = relationship(back_populates="account", cascade="all, delete-orphan", passive_deletes=True)


class MailMessage(Base):
    __tablename__ = "mail_messages"
    __table_args__ = (
        UniqueConstraint("account_id", "folder", "uid", name="folder_uid"),
        Index("ix_mail_messages_list", "account_id", "folder", "date"),
        Index("ix_mail_messages_msgid", "account_id", "message_id"),
        Index("ix_mail_messages_thread", "account_id", "thread_key"),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    account_id: Mapped[int] = mapped_column(ForeignKey("mail_accounts.id", ondelete="CASCADE"))
    folder: Mapped[str] = mapped_column(String(8))  # inbox | sent
    uid: Mapped[Optional[int]] = mapped_column(BigInteger)
    message_id: Mapped[str] = mapped_column(String(512), default="")
    in_reply_to: Mapped[str] = mapped_column(String(512), default="")
    references: Mapped[str] = mapped_column(Text, default="")
    thread_key: Mapped[str] = mapped_column(String(512), default="")
    subject: Mapped[str] = mapped_column(Text, default="")
    from_email: Mapped[str] = mapped_column(String(320), default="")
    from_name: Mapped[str] = mapped_column(String(255), default="")
    to: Mapped[list[dict[str, str]]] = mapped_column(JSONB, default=list)
    cc: Mapped[list[dict[str, str]]] = mapped_column(JSONB, default=list)
    date: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True), index=True)
    snippet: Mapped[str] = mapped_column(String(300), default="")
    body_text: Mapped[str] = mapped_column(Text, default="")
    body_html: Mapped[str] = mapped_column(Text, default="")
    attachments: Mapped[list[dict[str, Any]]] = mapped_column(JSONB, default=list)  # [{file_id, name, mime, size}]
    seen: Mapped[bool] = mapped_column(Boolean, default=False)
    flagged: Mapped[bool] = mapped_column(Boolean, default=False)
    crm_client_id: Mapped[Optional[int]] = mapped_column(Integer, index=True)
    crm_task_id: Mapped[Optional[int]] = mapped_column(Integer)
    sent_by_wp_user_id: Mapped[Optional[int]] = mapped_column(Integer)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)

    account: Mapped[MailAccount] = relationship(back_populates="messages")


class MailContact(Base):
    """E-mail cím → CRM ügyfél (a WordPress küldi az ügyfelek címeit, illetve kézi hozzárendelésből)."""

    __tablename__ = "mail_contacts"

    email: Mapped[str] = mapped_column(String(320), primary_key=True)
    crm_client_id: Mapped[int] = mapped_column(Integer, index=True)
    name: Mapped[str] = mapped_column(String(255), default="")
    source: Mapped[str] = mapped_column(String(16), default="crm")  # crm | manual
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, onupdate=utcnow)


class MailProfile(Base):
    """Az aláírás személyes adatai – csak az admin szerkeszti, így mindenkié egységes."""

    __tablename__ = "mail_profiles"

    wp_user_id: Mapped[int] = mapped_column(Integer, primary_key=True)
    name: Mapped[str] = mapped_column(String(255), default="")
    title: Mapped[str] = mapped_column(String(255), default="")
    phone: Mapped[str] = mapped_column(String(64), default="")
    extra: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, onupdate=utcnow)
