"""5. ütem végpontjai: Screaming Frog crawlok (ügynöknek és kézi feltöltéssel), technikai audit, ügynök-API,
kulcsszóadat API-ról (DataForSEO, Ahrefs), integrációk tesztje."""

from typing import Any, Optional
from urllib.parse import unquote

from fastapi import APIRouter, Depends, HTTPException, Request
from pydantic import BaseModel, Field
from sqlalchemy import select
from sqlalchemy.orm import Session

from .. import meta
from ..auth import CurrentUser, current_agent, need
from ..config import get_settings
from ..db import get_db, utcnow
from ..jobs import runner
from ..models import (
    AUDIT_SIZES,
    CRAWL_SOURCES,
    CRAWL_STATUSES,
    FINDING_STATUSES,
    AuditFinding,
    CrawlerAgent,
    CrawlRun,
    StoredFile,
)
from ..services import api_research, audit as audit_service, audit_rules, providers, storage
from ..services import settings as app_settings
from ..services.activity import changes, log
from ..services.files import file_response
from ..services.llm import claude_available, openai_available
from ..services.projects import get_project
from . import dashboard
from .research import job_payload

router = APIRouter(tags=["audit"])

meta.extend("crawl_sources", CRAWL_SOURCES)
meta.extend("crawl_statuses", CRAWL_STATUSES)
meta.extend("audit_sizes", AUDIT_SIZES)
meta.extend("finding_statuses", FINDING_STATUSES)
meta.extend("api_actions", api_research.ACTIONS)
meta.extend("audit_topics", {k: v["title"] for k, v in audit_rules.TOPICS.items()})

AGENT_ONLINE_SECONDS = 180


def run_payload(r: CrawlRun) -> dict[str, Any]:
    return {
        "id": r.id, "project_id": r.project_id, "source": r.source, "status": r.status, "status_label": CRAWL_STATUSES.get(r.status, r.status),
        "start_url": r.start_url, "files": r.files, "agent": r.agent, "message": r.message, "error": r.error, "stats": r.stats,
        "created_at": r.created_at.isoformat() if r.created_at else None, "finished_at": r.finished_at.isoformat() if r.finished_at else None,
    }


def get_run(db: Session, run_id: int) -> CrawlRun:
    r = db.get(CrawlRun, run_id)
    if r is None:
        raise HTTPException(404, "A crawl nem található.")
    return r


def agents_online(db: Session) -> list[dict]:
    rows = db.scalars(select(CrawlerAgent).order_by(CrawlerAgent.last_seen_at.desc())).all()
    now = utcnow()
    return [{"name": a.name, "info": a.info, "last_seen_at": a.last_seen_at.isoformat(), "online": (now - a.last_seen_at).total_seconds() < AGENT_ONLINE_SECONDS}
            for a in rows]


# ── Crawlok ──────────────────────────────────────────────


@router.get("/projects/{project_id}/crawls")
def list_crawls(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("audit.view"))):
    get_project(db, project_id)
    rows = db.scalars(select(CrawlRun).where(CrawlRun.project_id == project_id).order_by(CrawlRun.id.desc()).limit(30)).all()
    return {"runs": [run_payload(r) for r in rows], "agents": agents_online(db)}


class CrawlIn(BaseModel):
    start_url: Optional[str] = None
    export_tabs: Optional[list[str]] = None


@router.post("/projects/{project_id}/crawls", status_code=201)
def start_crawl(project_id: int, body: CrawlIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("audit.edit"))):
    """Crawl kérése a Screaming Frog ügynöktől (az ügynök a következő lekérdezéskor elviszi)."""
    project = get_project(db, project_id)
    busy = db.scalar(select(CrawlRun).where(CrawlRun.project_id == project_id, CrawlRun.source == "agent", CrawlRun.status.in_(["queued", "running", "importing"])))
    if busy:
        raise HTTPException(409, "Ehhez a projekthez már fut vagy sorban áll egy crawl.")
    url = (body.start_url or f"https://{project.domain}/").strip()
    if not url.startswith(("http://", "https://")):
        raise HTTPException(422, "A kezdő URL http:// vagy https:// kezdetű legyen.")
    run = CrawlRun(
        project_id=project_id, source="agent", status="queued", start_url=url, created_by=user.id or None,
        settings={
            "export_tabs": body.export_tabs or app_settings.get(db, "sf_export_tabs"),
            "bulk_exports": app_settings.get(db, "sf_bulk_exports") or [],
            "config_file": app_settings.get(db, "sf_config_file") or "",
            "save_crawl": bool(app_settings.get(db, "sf_save_crawl")),
        },
        message="Az ügynökre vár",
    )
    db.add(run)
    db.flush()
    log(db, project_id, user.id, "crawl", run.id, "queued", url)
    db.commit()
    out = run_payload(run)
    out["agents"] = agents_online(db)
    return out


@router.post("/projects/{project_id}/crawls/upload", status_code=201)
async def upload_export(project_id: int, request: Request, run_id: Optional[int] = None, db: Session = Depends(get_db),
                        user: CurrentUser = Depends(need("audit.edit"))):
    """Kézzel exportált Screaming Frog fájl (CSV / XLSX / ZIP). Több fájl ugyanahhoz a futáshoz: run_id."""
    get_project(db, project_id)
    name = unquote(request.headers.get("x-filename", "")) or "export.csv"
    data = await request.body()
    if not data:
        raise HTTPException(422, "Üres fájl.")
    try:
        recognized = audit_service.recognize(name, data)
    except Exception as e:  # noqa: BLE001 – hibás / nem táblázat fájl
        raise HTTPException(422, f"A fájl nem olvasható: {e}")
    if run_id:
        run = get_run(db, run_id)
        if run.project_id != project_id or run.status != "draft":
            raise HTTPException(409, "Ehhez a futáshoz már nem tölthető fel fájl.")
    else:
        run = CrawlRun(project_id=project_id, source="upload", status="draft", created_by=user.id or None, message="Kézi feltöltés")
        db.add(run)
        db.flush()
    f = storage.save(db, data, name, "crawl", project_id, user.id, request.headers.get("content-type", ""))
    run.files = [*run.files, {"file_id": f.id, "name": name, "recognized": recognized}]
    db.commit()
    return run_payload(run)


@router.post("/crawls/{run_id}/import")
def import_crawl(run_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("audit.edit"))):
    run = get_run(db, run_id)
    if run.status not in ("draft", "failed", "done"):
        raise HTTPException(409, "Ez a futás most nem dolgozható fel.")
    run.status, run.error = "importing", ""
    db.commit()
    job = runner.enqueue(db, "import_crawl", run.project_id, {"run_id": run.id}, user.id)
    if job.status == "failed":
        r = db.get(CrawlRun, run.id)
        r.status, r.error = "failed", job.error or ""
        db.commit()
    return job_payload(job)


@router.delete("/crawls/{run_id}", status_code=204)
def delete_crawl(run_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("audit.edit"))):
    run = get_run(db, run_id)
    if run.status in ("queued", "running"):
        run.status, run.message = "cancelled", "Megszakítva"
        db.commit()
        return
    for f in run.files:
        stored = db.get(StoredFile, f["file_id"])
        if stored:
            storage.delete(stored)
            db.delete(stored)
    db.delete(run)
    db.commit()


# ── Audit ────────────────────────────────────────────────


@router.get("/projects/{project_id}/audit")
def get_audit(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("audit.view"))):
    get_project(db, project_id)
    out = audit_service.overview(db, project_id)
    last = db.scalar(select(CrawlRun).where(CrawlRun.project_id == project_id, CrawlRun.status == "done").order_by(CrawlRun.id.desc()))
    out["last_run"] = run_payload(last) if last else None
    db.commit()
    return out


class TopicPatch(BaseModel):
    size: Optional[str] = None
    observation: Optional[str] = None
    recommendation: Optional[str] = None
    included: Optional[bool] = None
    metrics: Optional[dict[str, Any]] = None


@router.patch("/projects/{project_id}/audit/topics/{topic}")
def patch_topic(project_id: int, topic: str, body: TopicPatch, db: Session = Depends(get_db), user: CurrentUser = Depends(need("audit.edit"))):
    get_project(db, project_id)
    if topic not in audit_rules.TOPICS:
        raise HTTPException(404, "Ismeretlen téma.")
    t = audit_service.ensure_topics(db, project_id)[topic]
    data = body.model_dump(exclude_unset=True)
    if "size" in data and data["size"] not in AUDIT_SIZES:
        raise HTTPException(422, "A méret XL, M vagy S lehet.")
    diff = changes(t, data)
    t.updated_by = user.id or None
    if diff:
        log(db, project_id, user.id, "audit_topic", None, "updated", topic, diff)
    db.commit()
    return next(x for x in audit_service.overview(db, project_id)["topics"] if x["topic"] == topic)


class FindingPatch(BaseModel):
    status: Optional[str] = None
    notes: Optional[str] = None


@router.get("/audit/findings/{finding_id}")
def get_finding(finding_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("audit.view"))):
    f = db.get(AuditFinding, finding_id)
    if f is None:
        raise HTTPException(404, "Nem található.")
    return audit_service.finding_payload(f, full=True)


@router.patch("/audit/findings/{finding_id}")
def patch_finding(finding_id: int, body: FindingPatch, db: Session = Depends(get_db), user: CurrentUser = Depends(need("audit.view"))):
    f = db.get(AuditFinding, finding_id)
    if f is None:
        raise HTTPException(404, "Nem található.")
    data = body.model_dump(exclude_unset=True)
    if not user.can("audit.edit"):
        # A fejlesztő csak a javítás állapotát jelezheti.
        data = {k: v for k, v in data.items() if k == "status" and v in ("in_progress", "fixed")}
        if not data:
            raise HTTPException(403, "Ezt nem módosíthatod.")
    if "status" in data and data["status"] not in FINDING_STATUSES:
        raise HTTPException(422, "Ismeretlen állapot.")
    diff = changes(f, data)
    if diff:
        log(db, f.project_id, user.id, "audit_finding", f.id, "updated", f.issue_key, diff)
    db.commit()
    return audit_service.finding_payload(f)


@router.post("/projects/{project_id}/audit/site-checks")
def run_site_checks(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("audit.edit"))):
    project = get_project(db, project_id)
    res = audit_service.site_checks(db, project)
    log(db, project_id, user.id, "audit", None, "site_checks", str(res))
    db.commit()
    return res


@router.post("/projects/{project_id}/audit/tasks")
def audit_tasks(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("tasks.edit"))):
    project = get_project(db, project_id)
    res = audit_service.generate_tasks(db, project)
    log(db, project_id, user.id, "tasks", None, "audit_tasks", f"{res['created']} új, {res['updated']} frissítve")
    db.commit()
    return res


@router.get("/projects/{project_id}/audit/export.xlsx")
def audit_export(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("audit.view"))):
    project = get_project(db, project_id)
    data = audit_service.export_xlsx(db, project)
    db.commit()
    return file_response(data, f"{project.domain} technikai audit.xlsx", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")


# ── Screaming Frog ügynök (Bearer token + WordPress aláírás) ─


class Heartbeat(BaseModel):
    agent: str = Field(min_length=1, max_length=128)
    info: dict[str, Any] = {}


def _touch(db: Session, name: str, info: dict) -> None:
    a = db.get(CrawlerAgent, name)
    if a is None:
        a = CrawlerAgent(name=name)
        db.add(a)
    if info:
        a.info = info
    a.last_seen_at = utcnow()


@router.post("/agent/heartbeat", dependencies=[Depends(current_agent)])
def agent_heartbeat(body: Heartbeat, db: Session = Depends(get_db)):
    _touch(db, body.agent, body.info)
    db.commit()
    return {"ok": True, "queued": db.scalar(select(CrawlRun.id).where(CrawlRun.status == "queued", CrawlRun.source == "agent").limit(1)) is not None}


@router.post("/agent/crawls/claim", dependencies=[Depends(current_agent)])
def agent_claim(body: Heartbeat, db: Session = Depends(get_db)):
    _touch(db, body.agent, body.info)
    run = db.scalars(select(CrawlRun).where(CrawlRun.status == "queued", CrawlRun.source == "agent").order_by(CrawlRun.id).with_for_update(skip_locked=True).limit(1)).first()
    if run is None:
        db.commit()
        return {"run": None}
    run.status, run.agent, run.claimed_at, run.message = "running", body.agent, utcnow(), "A crawl elindult"
    db.commit()
    return {"run": {"id": run.id, "start_url": run.start_url, **run.settings}}


class AgentProgress(BaseModel):
    message: str = ""


@router.post("/agent/crawls/{run_id}/progress", dependencies=[Depends(current_agent)])
def agent_progress(run_id: int, body: AgentProgress, db: Session = Depends(get_db)):
    run = get_run(db, run_id)
    run.message = body.message[:500]
    db.commit()
    return {"cancelled": run.status == "cancelled"}


class AgentFail(BaseModel):
    error: str


@router.post("/agent/crawls/{run_id}/fail", dependencies=[Depends(current_agent)])
def agent_fail(run_id: int, body: AgentFail, db: Session = Depends(get_db)):
    run = get_run(db, run_id)
    run.status, run.error, run.finished_at = "failed", body.error[:4000], utcnow()
    db.commit()
    return {"ok": True}


@router.post("/agent/crawls/{run_id}/upload", dependencies=[Depends(current_agent)])
async def agent_upload(run_id: int, request: Request, db: Session = Depends(get_db)):
    run = get_run(db, run_id)
    if run.status not in ("running", "queued"):
        raise HTTPException(409, "Ez a crawl nem vár eredményt.")
    data = await request.body()
    if not data:
        raise HTTPException(422, "Üres fájl.")
    name = unquote(request.headers.get("x-filename", "")) or f"crawl-{run.id}.zip"
    f = storage.save(db, data, name, "crawl", run.project_id, None, "application/zip")
    run.files = [*run.files, {"file_id": f.id, "name": name}]
    run.status, run.message = "importing", "Feldolgozás"
    db.commit()
    job = runner.enqueue(db, "import_crawl", run.project_id, {"run_id": run.id}, run.created_by)
    if job.status == "failed":
        r = db.get(CrawlRun, run.id)
        r.status, r.error = "failed", job.error or ""
        db.commit()
    return {"job": job_payload(job)}


@router.get("/crawler/agents")
def crawler_agents(db: Session = Depends(get_db), user: CurrentUser = Depends(need("audit.view"))):
    return {"agents": agents_online(db), "token_set": bool(get_settings().agent_token)}


# ── Kulcsszóadat API-ról ─────────────────────────────────


class ApiFetch(BaseModel):
    action: str
    location_name: Optional[str] = None
    language_code: Optional[str] = None
    location_label: Optional[str] = ""
    country: Optional[str] = None
    keyword_ids: list[int] = []
    terms: list[str] = []
    seeds: list[str] = []
    target: Optional[str] = None
    limit: Optional[int] = Field(default=None, ge=10, le=5000)


@router.get("/projects/{project_id}/research/api")
def api_status(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("research.view"))):
    project = get_project(db, project_id)
    return {
        "dataforseo": providers.dataforseo_configured(db), "ahrefs": providers.ahrefs_configured(db),
        "defaults": api_research.defaults(project), "spend_month_usd": round(providers.month_spend(db, project_id), 2),
        "budget_usd": float(app_settings.get(db, "monthly_budget_usd") or 0), "actions": api_research.ACTIONS,
    }


@router.post("/projects/{project_id}/research/api")
def api_fetch(project_id: int, body: ApiFetch, db: Session = Depends(get_db), user: CurrentUser = Depends(need("integrations.run"))):
    get_project(db, project_id)
    if body.action not in api_research.ACTIONS:
        raise HTTPException(422, "Ismeretlen művelet.")
    if body.action.startswith("ahrefs") and not providers.ahrefs_configured(db):
        raise HTTPException(409, "Nincs Ahrefs API kulcs beállítva (Beállítások → Integrációk).")
    if not body.action.startswith("ahrefs") and not providers.dataforseo_configured(db):
        raise HTTPException(409, "Nincs DataForSEO hozzáférés beállítva (Beállítások → Integrációk).")
    job = runner.enqueue(db, "api_research", project_id, body.model_dump(), user.id)
    return job_payload(job)


# ── Integrációk tesztje ──────────────────────────────────


@router.post("/integrations/test")
def integrations_test(db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))):
    out: dict[str, dict] = {}

    def attempt(name, configured, fn):
        if not configured:
            out[name] = {"ok": False, "configured": False, "message": "Nincs beállítva."}
            return
        try:
            out[name] = {"ok": True, "configured": True, "message": fn()}
        except Exception as e:  # noqa: BLE001 – a felületen jelenjen meg a hiba
            out[name] = {"ok": False, "configured": True, "message": f"{type(e).__name__}: {e}"[:300]}

    def t_openai():
        from ..services.llm import openai_client

        openai_client(db).models.list()
        return "Kapcsolódva."

    def t_claude():
        from ..services.claude import client

        client(db).models.list(limit=1)
        return "Kapcsolódva."

    attempt("openai", openai_available(db), t_openai)
    attempt("anthropic", claude_available(db), t_claude)
    attempt("dataforseo", providers.dataforseo_configured(db), lambda: providers.dfs_test(db))
    attempt("ahrefs", providers.ahrefs_configured(db), lambda: providers.ahrefs_test(db))
    agents = agents_online(db)
    out["screaming_frog"] = {"ok": any(a["online"] for a in agents), "configured": bool(agents),
                             "message": (", ".join(f"{a['name']} ({'online' if a['online'] else 'offline'})" for a in agents)) or "Még nem jelentkezett ügynök."}
    return out


@dashboard.widget
def crawl_widget(db: Session, user: CurrentUser) -> dict:
    if not user.can("audit.view"):
        return {}
    running = db.scalars(select(CrawlRun).where(CrawlRun.status.in_(["queued", "running", "importing"])).order_by(CrawlRun.id.desc()).limit(10)).all()
    return {"crawls": [run_payload(r) for r in running]}

