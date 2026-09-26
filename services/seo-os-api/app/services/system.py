"""Rendszerállapot: életjelek, a Rendszer oldal összesítése és az élesítési ellenőrzőlista."""

import os
import platform
import shutil
from datetime import timedelta
from typing import Any, Optional

from sqlalchemy import func, select, text
from sqlalchemy.orm import Session

from ..config import get_settings
from ..db import session_factory, utcnow
from ..models import (
    ApiLog,
    AuditFinding,
    CrawlerAgent,
    CrawlRun,
    Document,
    Job,
    Keyword,
    Notification,
    ProductionTask,
    Project,
    ServiceHeartbeat,
    StoredFile,
    User,
)
from . import settings as app_settings

WORKER_ONLINE = 120  # mp
CRON_ONLINE = 20 * 60
DAILY_ONLINE = 26 * 3600


def heartbeat(name: str, info: dict, db: Optional[Session] = None) -> None:
    """Életjel mentése. Saját munkamenettel, ha nincs megadva (a worker így hívja)."""
    own = db is None
    s = session_factory()() if own else db
    try:
        row = s.get(ServiceHeartbeat, name)
        if row is None:
            row = ServiceHeartbeat(name=name)
            s.add(row)
        row.info, row.last_seen_at = info, utcnow()
        if own:
            s.commit()
        else:
            s.flush()
    finally:
        if own:
            s.close()


def _beat(db: Session, name: str, limit: int) -> dict:
    row = db.get(ServiceHeartbeat, name)
    if row is None:
        return {"online": False, "last_seen_at": None, "info": {}}
    age = (utcnow() - row.last_seen_at).total_seconds()
    return {"online": age < limit, "last_seen_at": row.last_seen_at.isoformat(), "age_seconds": int(age), "info": row.info}


def _migrations(db: Session) -> dict:
    current = db.execute(text("SELECT version_num FROM seo.alembic_version")).scalar()
    head = None
    try:
        from alembic.config import Config
        from alembic.script import ScriptDirectory

        root = os.path.join(os.path.dirname(__file__), "..", "..")
        cfg = Config(os.path.join(root, "alembic.ini"))
        cfg.set_main_option("script_location", os.path.join(root, "alembic"))
        head = ScriptDirectory.from_config(cfg).get_current_head()
    except Exception:  # noqa: BLE001 – ha a migrációs fájlok nincsenek a képben, csak a jelenlegit mutatjuk
        pass
    return {"revision": current, "head": head, "up_to_date": head is None or current == head}


INTEGRATIONS = [
    ("openai", "OpenAI", ["openai_api_key"], "openai"),
    ("anthropic", "Anthropic (Claude)", ["anthropic_api_key"], "anthropic"),
    ("dataforseo", "DataForSEO", ["dataforseo_login", "dataforseo_password"], "dataforseo"),
    ("ahrefs", "Ahrefs API", ["ahrefs_api_key"], "ahrefs"),
]


def status(db: Session) -> dict[str, Any]:
    cfg = get_settings()
    now = utcnow()
    day = now - timedelta(hours=24)
    month = now.replace(day=1, hour=0, minute=0, second=0, microsecond=0)

    # Háttérfeladatok
    by_status = dict(db.execute(select(Job.status, func.count()).where(Job.created_at >= day).group_by(Job.status)).all())
    queued = db.scalar(select(func.count()).select_from(Job).where(Job.status == "queued")) or 0
    oldest = db.scalar(select(func.min(Job.created_at)).where(Job.status == "queued"))
    failed = db.execute(select(Job, Project.name).outerjoin(Project, Project.id == Job.project_id)
                        .where(Job.status == "failed").order_by(Job.id.desc()).limit(10)).all()

    # Integrációk
    integrations = []
    for key, label, fields, provider in INTEGRATIONS:
        configured = all(app_settings.get(db, f) for f in fields)
        last_ok = db.scalar(select(func.max(ApiLog.created_at)).where(ApiLog.provider == provider, ApiLog.error == "", ApiLog.status_code < 400))
        last_err = db.execute(select(ApiLog.created_at, ApiLog.error).where(ApiLog.provider == provider, ApiLog.error != "")
                              .order_by(ApiLog.id.desc()).limit(1)).first()
        cost, calls = db.execute(select(func.coalesce(func.sum(ApiLog.cost_usd), 0), func.count()).where(ApiLog.provider == provider, ApiLog.created_at >= month)).one()
        integrations.append({
            "key": key, "label": label, "configured": configured,
            "fields": [{"key": f, "source": app_settings.source(db, f), "masked": _mask(app_settings.get(db, f), app_settings.DEFINITIONS[f][1])} for f in fields],
            "last_ok_at": last_ok.isoformat() if last_ok else None,
            "last_error_at": last_err[0].isoformat() if last_err and (not last_ok or last_err[0] > last_ok) else None,
            "last_error": last_err[1][:300] if last_err and (not last_ok or last_err[0] > last_ok) else "",
            "month_cost_usd": round(float(cost or 0), 2), "month_calls": calls,
        })

    # Tárhely
    storage_dir = cfg.storage_dir
    try:
        du = shutil.disk_usage(storage_dir)
        disk = {"free": du.free, "total": du.total}
        writable = os.access(storage_dir, os.W_OK)
    except OSError:
        disk, writable = {"free": None, "total": None}, False
    files_n, files_bytes = db.execute(select(func.count(), func.coalesce(func.sum(StoredFile.size), 0))).one()

    agents = db.scalars(select(CrawlerAgent).order_by(CrawlerAgent.last_seen_at.desc())).all()
    out = {
        "api": {"python": platform.python_version(), "time": now.isoformat(), "llm_fake": cfg.llm_fake, "jobs_inline": cfg.jobs_inline},
        "database": {"ok": True, **_migrations(db)},
        "services": {"worker": _beat(db, "worker", WORKER_ONLINE), "wp_cron": _beat(db, "wp_cron", CRON_ONLINE), "wp_daily": _beat(db, "wp_daily", DAILY_ONLINE)},
        "jobs": {"queued": queued, "running": db.scalar(select(func.count()).select_from(Job).where(Job.status == "running")) or 0,
                 "done_24h": by_status.get("done", 0), "failed_24h": by_status.get("failed", 0),
                 "oldest_queued_seconds": int((now - oldest).total_seconds()) if oldest else None,
                 "failed": [{"id": j.id, "type": j.type, "project": name or "", "error": (j.error or "")[:400], "finished_at": j.finished_at.isoformat() if j.finished_at else None} for j, name in failed]},
        "integrations": integrations,
        "crawler": {"token_set": bool(cfg.agent_token),
                    "agents": [{"name": a.name, "online": (now - a.last_seen_at).total_seconds() < 180, "last_seen_at": a.last_seen_at.isoformat(), "info": a.info} for a in agents],
                    "queued": db.scalar(select(func.count()).select_from(CrawlRun).where(CrawlRun.status == "queued")) or 0},
        "email": {"pending": db.scalar(select(func.count()).select_from(Notification).where(Notification.email_status == "pending")) or 0,
                  "failed_24h": db.scalar(select(func.count()).select_from(Notification).where(Notification.email_status == "failed", Notification.created_at >= day)) or 0,
                  "sent_24h": db.scalar(select(func.count()).select_from(Notification).where(Notification.email_status == "sent", Notification.created_at >= day)) or 0},
        "storage": {"dir": storage_dir, "writable": writable, **disk, "files": files_n, "bytes": int(files_bytes or 0)},
        "counts": {
            "projects": db.scalar(select(func.count()).select_from(Project).where(Project.archived_at.is_(None))) or 0,
            "users": db.scalar(select(func.count()).select_from(User).where(User.is_active.is_(True))) or 0,
            "keywords": db.scalar(select(func.count()).select_from(Keyword)) or 0,
            "documents": db.scalar(select(func.count()).select_from(Document)) or 0,
            "tasks_open": db.scalar(select(func.count()).select_from(ProductionTask).where(ProductionTask.status != "done")) or 0,
            "findings_open": db.scalar(select(func.count()).select_from(AuditFinding).where(AuditFinding.count > 0, AuditFinding.status.in_(["open", "in_progress"]))) or 0,
        },
        "spend": {"month_usd": round(sum(i["month_cost_usd"] for i in integrations), 2), "budget_per_project_usd": float(app_settings.get(db, "monthly_budget_usd") or 0)},
        "models": {"openai": app_settings.get(db, "openai_model"), "claude": app_settings.get(db, "claude_model_client"), "claude_effort": app_settings.get(db, "claude_effort")},
    }
    out["checklist"] = checklist(out)
    return out


def _mask(value, secret: bool) -> str:
    if not value:
        return ""
    return ("••••" + str(value)[-4:]) if secret else str(value)


def checklist(s: dict) -> list[dict]:
    """Élesítési ellenőrzőlista (API oldal). status: ok | warn | error | optional."""
    items = []

    def add(key, label, ok, detail="", fix="", level="error"):
        items.append({"key": key, "label": label, "status": "ok" if ok else level, "detail": detail, "fix": "" if ok else fix})

    db = s["database"]
    add("migrations", "Adatbázis-migrációk naprakészek", db["up_to_date"], f"{db['revision']} / {db['head'] or '?'}", "Futtasd: alembic upgrade head (Dockerben induláskor magától lefut).")
    add("fake_mode", "Éles AI mód (nem teszt-helyettesítő)", not s["api"]["llm_fake"] and not s["api"]["jobs_inline"], "",
        "A szerver .env-jéből töröld a SEO_OS_LLM_FAKE / SEO_OS_JOBS_INLINE sort.")
    w = s["services"]["worker"]
    add("worker", "Háttérfeladat-worker fut", w["online"], _ago(w), "Indítsd el a worker konténert: docker compose up -d worker.")
    if s["jobs"]["queued"] and s["jobs"]["oldest_queued_seconds"] and s["jobs"]["oldest_queued_seconds"] > 300:
        add("queue", "A feladatsor halad", False, f"{s['jobs']['queued']} feladat vár, a legrégebbi {s['jobs']['oldest_queued_seconds'] // 60} perce", "Ellenőrizd a worker naplóját: docker compose logs worker.")
    c = s["services"]["wp_cron"]
    add("wp_cron", "WordPress ütemezett feladat (e-mailek) fut", c["online"], _ago(c),
        "Állíts be valódi cront: */5 * * * * curl -s https://seo.helloprovision.com/wp-cron.php (és DISABLE_WP_CRON a wp-config.php-ban).")
    for i in s["integrations"]:
        level = "warn" if i["key"] in ("openai", "anthropic") else "optional"
        hint = {"openai": "Kulcs nélkül szabályalapú elemzés fut.", "anthropic": "Kulcs nélkül sablonszöveges dokumentumok készülnek.",
                "dataforseo": "Nem kötelező: exportfeltöltéssel is megy.", "ahrefs": "Csak Ahrefs Enterprise csomaggal; exportfeltöltéssel is megy."}[i["key"]]
        if i["configured"] and i["last_error_at"]:
            add(i["key"], f"{i['label']} működik", False, i["last_error"], "Ellenőrizd a kulcsot / egyenleget, majd a „Kapcsolatok tesztelése” gombot.", level="warn")
        else:
            add(i["key"], f"{i['label']} beállítva", i["configured"], "" if i["configured"] else hint, "Beállítások → Kulcsok és AI.", level=level)
    cr = s["crawler"]
    add("agent_token", "Screaming Frog ügynök token beállítva", cr["token_set"], "", "SEO_OS_AGENT_TOKEN a szerver .env-jébe (openssl rand -hex 24).", level="warn")
    add("agent", "Screaming Frog ügynök online", any(a["online"] for a in cr["agents"]),
        ", ".join(a["name"] for a in cr["agents"]) or "még nem jelentkezett", "Telepítsd az ügynököt (Beállítások → Screaming Frog). Addig kézi feltöltéssel megy.", level="warn")
    st = s["storage"]
    free_ok = st["free"] is None or st["free"] > 1024 ** 3
    add("storage", "Fájltár írható, van hely", st["writable"] and free_ok, f"{(st['free'] or 0) / 1024 ** 3:.1f} GB szabad", "Ellenőrizd a files kötetet / lemezhelyet.")
    add("budget", "Havi API-keret beállítva", s["spend"]["budget_per_project_usd"] > 0, "", "Beállítások → Kulcsok és AI → Havi API-keret.", level="warn")
    if s["jobs"]["failed_24h"]:
        add("failed_jobs", "Nincs hibás feladat az elmúlt 24 órában", False, f"{s['jobs']['failed_24h']} hibás", "Nézd meg a hibák listáját lent; javítás után újraindítható.", level="warn")
    if s["email"]["failed_24h"]:
        add("email_failed", "Az e-mailek kimennek", False, f"{s['email']['failed_24h']} sikertelen", "Ellenőrizd a WP Mail SMTP beállítását.", level="warn")
    return items


def _ago(b: dict) -> str:
    if not b["last_seen_at"]:
        return "még nem jelentkezett"
    a = b.get("age_seconds", 0)
    return f"utoljára {a} mp-e" if a < 120 else f"utoljára {a // 60} perce" if a < 7200 else f"utoljára {a // 3600} órája"
