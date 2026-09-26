"""Kulcsszóadatok API-ról: keresési volumen + nehézség, kulcsszóötletek, rangsorolt kulcsszavak, SERP-versenytársak.

Az eredmény ugyanabba a kulcsszó-adatbázisba kerül, mint a feltöltött exportoké: új kulcsszó (kizárási szabályokkal),
mérőszám (forrás + lokáció szerint), rangsor (saját domain / versenytárs).
"""

from collections import defaultdict
from typing import Any

from sqlalchemy import select
from sqlalchemy.orm import Session

from ..jobs.runner import JobError, Progress, handler
from ..models import Job, Keyword, Project
from . import importers, providers
from .activity import log
from .projects import get_project
from .research import exclusion_terms, keyword_cache, set_metric, set_ranking, upsert_keyword

ACTIONS = {
    "volume": "Keresési volumen és nehézség (DataForSEO)",
    "ideas": "Kulcsszóötletek a seed kulcsszavakból (DataForSEO)",
    "ranked": "Domain rangsorolt kulcsszavai (DataForSEO)",
    "serp": "SERP top 20 – versenytársak (DataForSEO)",
    "ahrefs_organic": "Domain organikus kulcsszavai (Ahrefs API)",
}


def defaults(project: Project) -> dict[str, str]:
    name, lang, country = providers.COUNTRIES.get((project.market or "us").lower(), ("United States", "en", "us"))
    if (project.content_language or "").startswith("hu"):
        lang = "hu"
    return {"location_name": name, "language_code": lang, "country": country}


def _own(project: Project) -> str:
    return (project.domain or "").lower().removeprefix("www.")


@handler("api_research")
def job_api_research(db: Session, job: Job, progress: Progress):
    project = get_project(db, job.project_id)
    p = job.payload
    action = p["action"]
    d = defaults(project)
    location_name = p.get("location_name") or d["location_name"]
    language = p.get("language_code") or d["language_code"]
    label = p.get("location_label") or ""  # a projekt melyik lokációjához tartozzon a mérőszám ("" = országos)
    source = "ahrefs_api" if action.startswith("ahrefs") else "dataforseo"
    kw_args = {"project_id": project.id, "job_id": job.id}
    cache = keyword_cache(db, project.id)
    excluded = exclusion_terms(project)
    seeds_set = {importers.normalize_term(s.keyword) for s in project.seed_keywords if s.kind != "excluded"}
    stats: dict[str, Any] = defaultdict(int)
    try:
        if action == "volume":
            ids = p.get("keyword_ids") or []
            q = select(Keyword).where(Keyword.project_id == project.id, Keyword.is_excluded.is_(False))
            if p.get("terms"):
                q = q.where(Keyword.term_normalized.in_([importers.normalize_term(t) for t in p["terms"] if t.strip()]))
            if ids:
                q = q.where(Keyword.id.in_(ids))
            kws = db.scalars(q).all()
            if not kws:
                raise JobError("Nincs kulcsszó, amihez adatot kérhetnénk.")
            terms = sorted({k.term.lower() for k in kws})
            progress(0.1, f"{len(terms)} kulcsszó keresési volumene…")
            vols = providers.dfs_search_volume(db, terms, location_name, language, **kw_args)
            progress(0.55, "Kulcsszó-nehézség…")
            kds = providers.dfs_keyword_difficulty(db, terms, location_name, language, **kw_args)
            for k in kws:
                v, kd = vols.get(k.term.lower()), kds.get(k.term.lower())
                if v is None and kd is None:
                    continue
                v = v or {}
                set_metric(db, k, label, source, volume=v.get("volume"), kd=kd, cpc=v.get("cpc"), currency="USD" if v.get("cpc") is not None else "",
                           competition=v.get("competition"), trend=v.get("trend") or None)
                stats["updated"] += 1
        elif action in ("ideas", "ranked", "ahrefs_organic"):
            if action == "ideas":
                seeds = p.get("seeds") or [s.keyword for s in project.seed_keywords if s.kind != "excluded"]
                if not seeds:
                    raise JobError("Adj meg legalább egy seed kulcsszót.")
                progress(0.1, f"Ötletek {len(seeds)} seed kulcsszóból…")
                rows = providers.dfs_keyword_ideas(db, seeds[:20], location_name, language, limit=int(p.get("limit") or 200), **kw_args)
            elif action == "ranked":
                target = (p.get("target") or _own(project)).lower().removeprefix("https://").removeprefix("http://").strip("/")
                progress(0.1, f"{target} rangsorolt kulcsszavai…")
                rows = providers.dfs_ranked_keywords(db, target, location_name, language, limit=int(p.get("limit") or 500), **kw_args)
            else:
                target = (p.get("target") or _own(project)).lower().removeprefix("https://").removeprefix("http://").strip("/")
                progress(0.1, f"{target} organikus kulcsszavai (Ahrefs)…")
                rows = providers.ahrefs_organic_keywords(db, target, p.get("country") or d["country"], limit=int(p.get("limit") or 500), **kw_args)
            progress(0.6, f"{len(rows)} kulcsszó mentése…")
            for r in rows:
                kw, created = upsert_keyword(db, project, cache, r["keyword"], source, None, excluded, seeds_set)
                stats["created" if created else "merged"] += 1
                set_metric(db, kw, label, source, volume=r.get("volume"), kd=r.get("kd"), cpc=r.get("cpc"),
                           currency="USD" if r.get("cpc") is not None else "", competition=r.get("competition"))
                if r.get("intent") and not kw.source_intents:
                    kw.source_intents = [r["intent"]]
                if action != "ideas" and r.get("position"):
                    domain = target.removeprefix("www.")
                    set_ranking(kw, domain, label, source, r["position"], r.get("url"), r.get("traffic"), is_own=domain == _own(project))
                    stats["rankings"] += 1
        elif action == "serp":
            ids = p.get("keyword_ids") or []
            terms = [importers.normalize_term(t) for t in p.get("terms") or [] if t.strip()]
            q = select(Keyword).where(Keyword.project_id == project.id)
            kws = db.scalars(q.where(Keyword.id.in_(ids))).all() if ids else db.scalars(q.where(Keyword.term_normalized.in_(terms))).all() if terms else []
            if not kws or len(kws) > 50:
                raise JobError("Válassz ki 1–50 kulcsszót a SERP-lekérdezéshez.")
            own = _own(project)
            for i, k in enumerate(kws):
                progress(0.05 + 0.9 * i / len(kws), f"SERP: {k.term}")
                for item in providers.dfs_serp(db, k.term, location_name, language, **kw_args):
                    set_ranking(k, item["domain"], label, source, item["position"], item["url"], None, is_own=item["domain"] == own)
                    stats["rankings"] += 1
        else:
            raise JobError("Ismeretlen művelet.")
    except providers.ProviderError as e:
        raise JobError(str(e))
    db.flush()
    stats["spend_month_usd"] = round(providers.month_spend(db, project.id), 4)
    log(db, project.id, job.created_by, "research", None, "api_fetch", f"{ACTIONS[action]}: {dict(stats)}")
    return dict(stats)
