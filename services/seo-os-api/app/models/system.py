"""Rendszerállapot: a háttérfolyamatok (worker, WordPress cron) életjele – a Rendszer oldal ebből látja, hogy futnak-e."""

from datetime import datetime
from typing import Any

from sqlalchemy import DateTime, String
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column

from ..db import Base, utcnow


class ServiceHeartbeat(Base):
    __tablename__ = "service_heartbeats"

    name: Mapped[str] = mapped_column(String(64), primary_key=True)
    info: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    last_seen_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
