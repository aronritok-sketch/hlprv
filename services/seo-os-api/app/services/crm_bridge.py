"""Kapcsolat a CRM / ügyfélportál havi riportjával (helloprovision-portal `hpv_report_metrics` szűrő).

A portál egy ügyfélre és hónapra kér mutatókat; az SEO OS a CRM ügyfélhez kötött projektjeiből adja:
kulcsszó-helyezések (havi pillanatképből, előző hónappal és 6 havi előzménnyel), megjelent tartalmak,
javított és nyitott technikai hibák. A címkék az ügyfél nyelvén érkeznek (a portál változatlanul mutatja őket).
"""

from datetime import date, datetime, time, timedelta, timezone
from typing import Any, Optional

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from ..db import utcnow
from ..models import AuditFinding, Client, Keyword, Project, RankingSnapshot, RoadmapItem

LABELS = {
    "section": {"hu": "SEO (HelloProVision SEO OS)", "en": "SEO (HelloProVision SEO OS)"},
    "top10": {"hu": "Kulcsszavak a Google első 10 találatában", "en": "Keywords in the top 10"},
    "top3": {"hu": "Kulcsszavak a Google első 3 találatában", "en": "Keywords in the top 3"},
    "avg": {"hu": "Követett kulcsszavak átlagos helyezése", "en": "Average position of tracked keywords"},
    "published": {"hu": "Megjelent SEO-tartalmak", "en": "SEO content published"},
    "fixed": {"hu": "Javított technikai hibák", "en": "Technical issues fixed"},
    "open": {"hu": "Nyitott technikai hibák", "en": "Open technical issues"},
}


def _period_bounds(period: str) -> tuple[datetime, datetime]:
    y, m = (int(x) for x in period.split("-"))
    start = date(y, m, 1)
    end = (start.replace(day=28) + timedelta(days=4)).replace(day=1)
    tz = timezone.utc
    return datetime.combine(start, time.min, tz), datetime.combine(end, time.min, tz)


def prev_period(period: str) -> str:
    start, _ = _period_bounds(period)
    p = (start.date() - timedelta(days=1)).replace(day=1)
    return f"{p.year:04d}-{p.month:02d}"


def current_period() -> str:
    t = date.today()
    return f"{t.year:04d}-{t.month:02d}"


def ranking_stats(db: Session, project: Project) -> dict[str, Any]:
    best: dict[int, int] = {}
    kws = db.scalars(select(Keyword).where(Keyword.project_id == project.id, Keyword.is_excluded.is_(False))).all()
    for k in kws:
        own = [r.position for r in k.rankings if r.is_own and r.position]
        if own:
            best[k.id] = min(own)
    pos = list(best.values())
    return {"tracked": len(pos), "top3": sum(1 for p in pos if p <= 3), "top10": sum(1 for p in pos if p <= 10),
            "avg_position": round(sum(pos) / len(pos), 2) if pos else None}


def snapshot(db: Session, project: Project, period: Optional[str] = None) -> RankingSnapshot:
    period = period or current_period()
    s = db.get(RankingSnapshot, (project.id, period))
    if s is None:
        s = RankingSnapshot(project_id=project.id, period=period)
        db.add(s)
    st = ranking_stats(db, project)
    s.tracked, s.top3, s.top10, s.avg_position, s.taken_at = st["tracked"], st["top3"], st["top10"], st["avg_position"], utcnow()
    db.flush()
    return s


def snapshot_all(db: Session) -> int:
    n = 0
    for p in db.scalars(select(Project).where(Project.archived_at.is_(None))).all():
        snapshot(db, p)
        n += 1
    return n


def report_metrics(db: Session, crm_client_id: int, period: str, lang: str = "hu") -> list[dict[str, Any]]:
    lang = "en" if lang == "en" else "hu"
    projects = db.scalars(select(Project).join(Client, Client.id == Project.client_id)
                          .where(Client.crm_client_id == crm_client_id, Project.archived_at.is_(None))).all()
    if not projects:
        return []
    ids = [p.id for p in projects]
    prev = prev_period(period)
    start, end = _period_bounds(period)
    pstart, pend = _period_bounds(prev)
    if period == current_period():
        for p in projects:
            snapshot(db, p, period)

    def snap_sum(per: str) -> Optional[dict[str, Any]]:
        rows = db.scalars(select(RankingSnapshot).where(RankingSnapshot.project_id.in_(ids), RankingSnapshot.period == per)).all()
        if not rows:
            return None
        tracked = sum(r.tracked for r in rows)
        avg = [float(r.avg_position) * r.tracked for r in rows if r.avg_position is not None and r.tracked]
        return {"top3": sum(r.top3 for r in rows), "top10": sum(r.top10 for r in rows), "tracked": tracked,
                "avg": round(sum(avg) / tracked, 1) if tracked and avg else None}

    history_periods = [period]
    for _ in range(5):
        history_periods.insert(0, prev_period(history_periods[0]))
    snaps = {per: snap_sum(per) for per in history_periods}
    cur, before = snaps[period], snaps[prev]

    def count_published(a, b):
        return db.scalar(select(func.count()).select_from(RoadmapItem).where(
            RoadmapItem.project_id.in_(ids), RoadmapItem.status == "published", RoadmapItem.updated_at >= a, RoadmapItem.updated_at < b)) or 0

    def count_fixed(a, b):
        return db.scalar(select(func.count()).select_from(AuditFinding).where(
            AuditFinding.project_id.in_(ids), AuditFinding.status == "fixed", AuditFinding.updated_at >= a, AuditFinding.updated_at < b)) or 0

    section = LABELS["section"][lang]
    out: list[dict[str, Any]] = []
    if cur and cur["tracked"]:
        hist = lambda k: [s[k] for s in snaps.values() if s and s.get(k) is not None]  # noqa: E731
        out.append({"section": section, "key": "seo_os_top10", "label": LABELS["top10"][lang], "value": cur["top10"],
                    "prev": before["top10"] if before else None, "format": "int", "better": "up", "history": hist("top10")})
        out.append({"section": section, "key": "seo_os_top3", "label": LABELS["top3"][lang], "value": cur["top3"],
                    "prev": before["top3"] if before else None, "format": "int", "better": "up", "history": hist("top3")})
        if cur["avg"] is not None:
            out.append({"section": section, "key": "seo_os_avg_position", "label": LABELS["avg"][lang], "value": cur["avg"],
                        "prev": before["avg"] if before else None, "format": "position", "better": "down", "history": hist("avg")})
    out.append({"section": section, "key": "seo_os_published", "label": LABELS["published"][lang], "value": count_published(start, end),
                "prev": count_published(pstart, pend), "format": "int", "better": "up"})
    fixed_now = count_fixed(start, end)
    has_audit = db.scalar(select(func.count()).select_from(AuditFinding).where(AuditFinding.project_id.in_(ids))) or 0
    if has_audit:
        out.append({"section": section, "key": "seo_os_fixed", "label": LABELS["fixed"][lang], "value": fixed_now,
                    "prev": count_fixed(pstart, pend), "format": "int", "better": "up"})
        open_now = db.scalar(select(func.count()).select_from(AuditFinding).where(
            AuditFinding.project_id.in_(ids), AuditFinding.count > 0, AuditFinding.status.in_(["open", "in_progress"]))) or 0
        out.append({"section": section, "key": "seo_os_open_issues", "label": LABELS["open"][lang], "value": open_now,
                    "prev": None, "format": "int", "better": "down"})
    return out
