"""Rendszerállapot: a háttérfolyamatok (worker, WordPress cron) életjele – a Rendszer oldal ebből látja, hogy futnak-e."""

from datetime import datetime
from typing import Any, Optional

from sqlalchemy import DateTime, ForeignKey, Integer, Numeric, String
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column

from ..db import Base, utcnow


class ServiceHeartbeat(Base):
    __tablename__ = "service_heartbeats"

    name: Mapped[str] = mapped_column(String(64), primary_key=True)
    info: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    last_seen_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)


class RankingSnapshot(Base):
    """Havi helyezés-pillanatkép projektenként (a havi riport előző hónapjához és mini grafikonjához)."""

    __tablename__ = "ranking_snapshots"

    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id", ondelete="CASCADE"), primary_key=True)
    period: Mapped[str] = mapped_column(String(7), primary_key=True)  # ÉÉÉÉ-HH
    tracked: Mapped[int] = mapped_column(Integer, default=0)
    top3: Mapped[int] = mapped_column(Integer, default=0)
    top10: Mapped[int] = mapped_column(Integer, default=0)
    avg_position: Mapped[Optional[float]] = mapped_column(Numeric(6, 2))
    taken_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
