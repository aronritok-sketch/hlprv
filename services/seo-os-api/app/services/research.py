"""Kutatás: import futtatása (kulcsszavak összevonása, lokációnkénti mérőszámok, versenytárs-pozíciók),
kizárások, kiinduló kulcsszavak jelölése, és a kulcsszólista összesítése a felületnek.
"""

from collections import defaultdict
from typing import Any, Optional

from sqlalchemy import delete, select
from sqlalchemy.orm import Session

from ..db import utcnow
from ..jobs.runner import JobError, Progress, handler
from ..models import CompetitorRanking, Import, Job, Keyword, KeywordMetric, Project
from . import importers, storage
from .activity import log

# Ha ugyanarra a lokációra több forrásból is van adat, ez a sorrend dönt (elöl a megbízhatóbb).
SOURCE_RANK = ["house_research", "dataforseo", "ahrefs_api", "ahrefs_keywords", "ahrefs_matching_terms",
               "ahrefs_organic", "ahrefs_content_gap", "gkp", "manual"]


def _rank(source: str) -> int:
    return SOURCE_RANK.index(source) if source in SOURCE_RANK else len(SOURCE_RANK)


def exclusion_terms(project: Project) -> list[str]:
    terms = [s.keyword for s in project.seed_keywords if s.kind == "excluded"] + list(project.excluded_topics or [])
    return [importers.normalize_term(t) for t in terms if t and t.strip()]


def exclusion_for(term_norm: str, excluded: list[str]) -> str:
    padded = f" {term_norm} "
    for e in excluded:
        if e and f" {e} " in padded:
            return f"Kizárt téma: {e}"
    return ""


def upsert_keyword(db: Session, project: Project, cache: dict[str, Keyword], term: str, source: str,
                   import_id: Optional[int], excluded: list[str], seeds: set[str]) -> tuple[Keyword, bool]:
    norm = importers.normalize_term(term)
    kw = cache.get(norm)
    created = False
    if kw is None:
        reason = exclusion_for(norm, excluded)
        kw = Keyword(
            project_id=project.id,
            term=term.strip(),
            term_normalized=norm,
            term_location=importers.term_location(term, project.locations or []),
            first_import_id=import_id,
            sources=[source],
            is_excluded=bool(reason),
            exclusion_reason=reason,
            is_seed=norm in seeds,
        )
        db.add(kw)
        cache[norm] = kw
        created = True
    elif source not in (kw.sources or []):
        kw.sources = [*(kw.sources or []), source]
    return kw, created


def keyword_cache(db: Session, project_id: int) -> dict[str, Keyword]:
    return {k.term_normalized: k for k in db.scalars(select(Keyword).where(Keyword.project_id == project_id)).all()}


def set_metric(db: Session, kw: Keyword, location: str, source: str, **values) -> None:
    existing = next((m for m in kw.metrics if m.location == location and m.source == source), None)
    if existing is None:
        existing = KeywordMetric(location=location, source=source)
        kw.metrics.append(existing)
    for k, v in values.items():
        if v is not None and v != [] and v != "":
            setattr(existing, k, v)
    existing.fetched_at = utcnow()


def set_ranking(kw: Keyword, domain: str, location: str, source: str, position, url, traffic, is_own=False) -> None:
    existing = next((r for r in kw.rankings if r.domain == domain and r.location == location), None)
    if existing is None:
        existing = CompetitorRanking(domain=domain, location=location, is_own=is_own)
        kw.rankings.append(existing)
    existing.position, existing.url, existing.traffic, existing.source = position, url or "", traffic, source
    existing.fetched_at = utcnow()


def apply_import(db: Session, imp: Import, progress: Optional[Progress] = None) -> dict[str, Any]:
    project = db.get(Project, imp.project_id)
    if imp.file is None:
        raise JobError("Az importhoz tartozó fájl hiányzik.")
    sheets = importers.read_table(storage.path_of(imp.file), imp.file.filename)
    sheet = next((s for s in sheets if s.name == imp.sheet), None)
    if sheet is None:
        raise JobError(f"A munkalap nem található: {imp.sheet}")
    det = importers.Detection(
        source=imp.source, sheet=imp.sheet, header_row=imp.header_row, columns=[],
        column_map=imp.column_map, competitor_map=imp.competitor_map, location=imp.location,
    )
    if "term" not in det.column_map:
        raise JobError("Nincs kiválasztva a kulcsszó oszlop.")
    own_domain = (project.domain or "").lower()
    cache = keyword_cache(db, project.id)
    excluded = exclusion_terms(project)
    seeds = {importers.normalize_term(s.keyword) for s in project.seed_keywords if s.kind != "excluded"}
    stats = defaultdict(int)
    rows = list(importers.iter_rows(sheet, det, project.locations or []))
    for i, r in enumerate(rows):
        kw, created = upsert_keyword(db, project, cache, r.term, imp.source, imp.id, excluded, seeds)
        stats["created" if created else "merged"] += 1
        if r.parent_topic and not kw.parent_topic:
            kw.parent_topic = r.parent_topic
        if r.translation and not kw.translation:
            kw.translation = r.translation
        if r.category and not kw.category:
            kw.category = r.category
        if r.modifier and not kw.modifier:
            kw.modifier = r.modifier
        if r.intents:
            kw.source_intents = r.intents
        if r.serp_features:
            kw.serp_features = r.serp_features
        if any(v is not None for v in (r.volume, r.kd, r.cpc, r.traffic_potential)) or r.trend:
            currency = r.currency or ("USD" if imp.source.startswith("ahrefs") and r.cpc is not None else "")
            set_metric(db, kw, r.location, imp.source, volume=r.volume, kd=r.kd, cpc=r.cpc, currency=currency,
                       traffic_potential=r.traffic_potential, competition=r.competition, trend=r.trend or None)
        if r.own:
            set_ranking(kw, own_domain, r.location, imp.source, *r.own, is_own=True)
            stats["own_rankings"] += 1
        for domain, (pos, url, traffic) in r.competitors.items():
            is_own = domain == own_domain
            set_ranking(kw, domain, r.location, imp.source, pos, url, traffic, is_own=is_own)
            stats["rankings"] += 1
        if progress and i % 200 == 0:
            db.flush()
            progress(i / max(len(rows), 1), f"{i} / {len(rows)} sor")
    db.flush()
    stats["rows"] = len(rows)
    stats["excluded"] = sum(1 for k in cache.values() if k.is_excluded and k.first_import_id == imp.id)
    imp.row_count, imp.stats, imp.status = len(rows), dict(stats), "done"
    log(db, project.id, imp.created_by, "import", imp.id, "import",
        f"Import: {importers_label(imp.source)} – {len(rows)} sor, {stats['created']} új kulcsszó")
    return dict(stats)


def importers_label(source: str) -> str:
    from ..models import IMPORT_SOURCES

    return IMPORT_SOURCES.get(source, source)


@handler("import_keywords")
def job_import(db: Session, job: Job, progress: Progress):
    imp = db.get(Import, job.payload["import_id"])
    if imp is None:
        raise JobError("Az import nem található.")
    imp.status = "running"
    db.flush()
    try:
        return apply_import(db, imp, progress)
    except JobError:
        raise
    except ValueError as e:
        raise JobError(str(e))


def reapply_exclusions(db: Session, project: Project) -> int:
    """Kizárt témák újraalkalmazása (csak az automatikusan kizártakat írja felül)."""
    excluded = exclusion_terms(project)
    changed = 0
    for kw in db.scalars(select(Keyword).where(Keyword.project_id == project.id)).all():
        reason = exclusion_for(kw.term_normalized, excluded)
        auto = kw.exclusion_reason.startswith("Kizárt téma:")
        if reason and not kw.is_excluded:
            kw.is_excluded, kw.exclusion_reason = True, reason
            changed += 1
        elif not reason and kw.is_excluded and auto:
            kw.is_excluded, kw.exclusion_reason = False, ""
            changed += 1
    return changed


def best_metric(kw: Keyword, location: Optional[str] = None) -> dict[str, Any]:
    """Egy kulcsszó összesített mérőszámai. Lokáció nélkül: az országos adat, ha van, különben a legnagyobb volumen."""
    ms = sorted(kw.metrics, key=lambda m: _rank(m.source))
    if location is not None:
        ms = [m for m in ms if m.location == location] or [m for m in ms if m.location == ""]
    national = [m for m in ms if m.location == ""]
    pool = national or ms
    out: dict[str, Any] = {"volume": None, "kd": None, "cpc": None, "traffic_potential": None}
    for fld in list(out):
        vals = [getattr(m, fld) for m in pool if getattr(m, fld) is not None]
        if not vals:
            vals = [getattr(m, fld) for m in ms if getattr(m, fld) is not None]
        if vals:
            out[fld] = max(vals) if fld in ("volume", "traffic_potential") else vals[0]
    if out["cpc"] is not None:
        out["cpc"] = float(out["cpc"])
        src = next((m for m in pool + ms if m.cpc is not None), None)
        out["currency"] = src.currency if src else ""
    return out


def keyword_row(kw: Keyword, own_domain: str) -> dict[str, Any]:
    agg = best_metric(kw)
    own = [r for r in kw.rankings if r.is_own and r.position]
    comp = [r for r in kw.rankings if not r.is_own and r.position]
    return {
        "id": kw.id,
        "term": kw.term,
        "translation": kw.translation,
        "parent_topic": kw.parent_topic,
        "category": kw.category,
        "term_location": kw.term_location,
        "modifier": kw.modifier,
        "source_intents": kw.source_intents,
        "serp_features": kw.serp_features,
        "is_excluded": kw.is_excluded,
        "exclusion_reason": kw.exclusion_reason,
        "is_seed": kw.is_seed,
        "sources": kw.sources,
        "notes": kw.notes,
        **agg,
        "locations": sorted({m.location for m in kw.metrics if m.location}),
        "metrics": [
            {"location": m.location, "source": m.source, "volume": m.volume, "kd": m.kd,
             "cpc": float(m.cpc) if m.cpc is not None else None, "currency": m.currency, "traffic_potential": m.traffic_potential}
            for m in sorted(kw.metrics, key=lambda m: (m.location, _rank(m.source)))
        ],
        "own_position": min((r.position for r in own), default=None),
        "own_url": next((r.url for r in sorted(own, key=lambda r: r.position)), ""),
        "competitors": sorted(
            [{"domain": r.domain, "position": r.position, "url": r.url, "traffic": r.traffic, "location": r.location} for r in comp],
            key=lambda x: x["position"],
        ),
        "best_competitor_position": min((r.position for r in comp), default=None),
    }


def delete_keywords(db: Session, project_id: int, ids: list[int]) -> int:
    res = db.execute(delete(Keyword).where(Keyword.project_id == project_id, Keyword.id.in_(ids)))
    return res.rowcount or 0


from sqlalchemy import func  # noqa: E402

from .workflow import gate  # noqa: E402


@gate
def research_gate(db: Session, project: Project, to: str) -> list[str]:
    """Az AI-elemzéshez kulcsszavak kellenek (ha a kulcsszókutatás a terjedelem része)."""
    if to != "ai_analysis_complete" or "keyword_research" not in (project.scope or []):
        return []
    active = db.scalar(
        select(func.count()).select_from(Keyword).where(Keyword.project_id == project.id, Keyword.is_excluded.is_(False))
    )
    return [] if active else ["Még nincs importált kulcsszó (Kutatás fül)."]
