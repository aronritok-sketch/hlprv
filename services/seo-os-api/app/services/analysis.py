"""3a. ütem: üzleti profil és kulcsszó-intelligencia (szándék, lokalitás, üzleti érték, klaszter, prioritás).

Folyamat egy elemzésnél:
  1. szabályalapú alap minden kulcsszóra (Ahrefs intents, módosítók, lokáció);
  2. ha van OpenAI kulcs: osztályozás 80-as csomagokban (szándék, üzleti érték, kereskedelmi lehetőség, indoklás, fordítás);
  3. klaszterezés: embedding + agglomeratív csoportosítás, az AI elnevezi; kulcs nélkül szabályalapú csoportosítás;
  4. determinisztikus pontszám és prioritás (scoring.py);
  5. mentés javaslatként: csak a még nem jóváhagyott/átírt sorok értéke változik, a többinél az `ai` mező frissül.
"""

import json
from collections import Counter, defaultdict
from typing import Optional

from sqlalchemy import func, select
from sqlalchemy.orm import Session, selectinload

from ..jobs.runner import JobError, Progress, handler
from ..models import BusinessProfile, Cluster, Job, Keyword, KeywordAnalysis, Page, PageKeyword, Project
from . import heuristics, llm, prompts, scoring, site
from . import settings as app_settings
from .activity import log
from .research import best_metric
from .workflow import gate

BATCH = 80


# ── Üzleti profil ────────────────────────────────────────


def latest_profile(db: Session, project_id: int) -> Optional[BusinessProfile]:
    return db.scalar(select(BusinessProfile).where(BusinessProfile.project_id == project_id).order_by(BusinessProfile.version.desc()).limit(1))


@handler("business_profile")
def job_profile(db: Session, job: Job, progress: Progress):
    project = db.get(Project, job.project_id)
    progress(0.1, "Weboldal letöltése…")
    snap = site.snapshot(project.domain)
    prev = latest_profile(db, project.id)
    version = (prev.version + 1) if prev else 1
    if llm.openai_available(db):
        progress(0.4, "Üzleti profil összeállítása (AI)…")
        user = prompts.project_context(project) + "\n\nWeboldal:\n" + json.dumps(snap, ensure_ascii=False)[:6000]
        data = llm.json_call(db, name="business_profile", version=prompts.PROFILE_VERSION, system=prompts.PROFILE_SYSTEM,
                             user=user, schema=prompts.PROFILE_SCHEMA, project_id=project.id, job_id=job.id)
        source = "ai"
    else:
        data = {
            "summary": f"{project.client.name} – {project.industry or 'szolgáltató'}, {', '.join(project.locations or []) or project.market}.",
            "positioning": (snap.get("description") or snap.get("title") or "").strip(),
            "services": [{"name": s.get("name", ""), "description": "", "priority": bool(s.get("priority") or s.get("high_margin"))} for s in project.business_services or []],
            "audiences": [{"name": project.target_audience, "needs": ""}] if project.target_audience else [],
            "differentiators": [],
            "proof_assets": [],
        }
        source = "heuristic"
    profile = BusinessProfile(project_id=project.id, version=version, site_snapshot=snap, source=source,
                              **{k: data.get(k) or ([] if k in ("services", "audiences", "differentiators", "proof_assets") else "") for k in
                                 ("summary", "positioning", "services", "audiences", "differentiators", "proof_assets")})
    db.add(profile)
    log(db, project.id, job.created_by, "profile", None, "generate", f"Üzleti profil v{version} ({source})")
    return {"version": version, "source": source, "site_error": snap.get("error", "")}


# ── Kulcsszó-elemzés ─────────────────────────────────────


def _load(db: Session, project_id: int) -> list[Keyword]:
    return db.scalars(
        select(Keyword).where(Keyword.project_id == project_id, Keyword.is_excluded.is_(False))
        .options(selectinload(Keyword.metrics)).order_by(Keyword.id)
    ).all()


def _llm_classify(db: Session, project: Project, profile, kws: list[Keyword], job: Job, progress: Progress) -> dict[int, dict]:
    out: dict[int, dict] = {}
    context = prompts.project_context(project, profile)
    for start in range(0, len(kws), BATCH):
        chunk = kws[start : start + BATCH]
        items = []
        for k in chunk:
            m = best_metric(k)
            items.append({"id": k.id, "keyword": k.term, "volume": m["volume"], "kd": m["kd"], "location": k.term_location,
                          "ahrefs_intents": k.source_intents, "parent_topic": k.parent_topic})
        user = context + "\n\nKulcsszavak (JSON):\n" + json.dumps(items, ensure_ascii=False)
        data = llm.json_call(db, name="classify_keywords", version=prompts.CLASSIFY_VERSION, system=prompts.CLASSIFY_SYSTEM,
                             user=user, schema=prompts.CLASSIFY_SCHEMA, project_id=project.id, job_id=job.id)
        for it in data.get("items", []):
            out[int(it["id"])] = it
        progress(0.1 + 0.5 * min(1, (start + BATCH) / max(len(kws), 1)), f"AI-osztályozás: {min(start + BATCH, len(kws))} / {len(kws)}")
    return out


def _llm_clusters(db: Session, project: Project, kws: list[Keyword], job: Job, progress: Progress) -> dict[str, dict]:
    """Embedding-alapú csoportok → {kulcs: {name, pillar, rule, ids}}."""
    import numpy as np
    from sklearn.cluster import AgglomerativeClustering

    progress(0.62, "Klaszterezés (embedding)…")
    vecs = llm.embed(db, [k.term for k in kws], project.id, job.id)
    x = np.array(vecs, dtype=float)
    if len(kws) < 3:
        labels = list(range(len(kws)))
    else:
        model = AgglomerativeClustering(n_clusters=None, metric="cosine", linkage="average", distance_threshold=0.35)
        labels = model.fit_predict(x).tolist()
    groups: dict[str, list[Keyword]] = defaultdict(list)
    for k, lab in zip(kws, labels):
        groups[f"c{lab}"].append(k)
    progress(0.72, "Klaszterek elnevezése…")
    payload = []
    for key, members in groups.items():
        top = sorted(members, key=lambda k: -(best_metric(k)["volume"] or 0))[:12]
        payload.append({"key": key, "keywords": [k.term for k in top], "parent_topics": list({k.parent_topic for k in members if k.parent_topic})[:5]})
    named: dict[str, dict] = {}
    for start in range(0, len(payload), 60):
        user = prompts.project_context(project) + "\n\nCsoportok (JSON):\n" + json.dumps(payload[start : start + 60], ensure_ascii=False)
        data = llm.json_call(db, name="name_clusters", version=prompts.CLUSTER_VERSION, system=prompts.CLUSTER_SYSTEM,
                             user=user, schema=prompts.CLUSTER_SCHEMA, project_id=project.id, job_id=job.id)
        for c in data.get("clusters", []):
            named[c["key"]] = c
    out = {}
    for key, members in groups.items():
        n = named.get(key, {})
        out[key] = {"name": n.get("name") or members[0].term.title(), "pillar": n.get("pillar", ""),
                    "rule": n.get("cannibalization_rule", ""), "ids": [k.id for k in members]}
    return out


def _heuristic_clusters(project: Project, kws: list[Keyword]) -> dict[str, dict]:
    groups = heuristics.cluster([{"id": k.id, "term": k.term, "parent_topic": k.parent_topic} for k in kws],
                                project.locations or [], project.business_services or [])
    services = [s.get("name", "") for s in project.business_services or []]
    out = {}
    for name, ids in groups.items():
        pillar = next((s for s in services if s.lower() == name.lower()), "")
        out[name] = {"name": name, "pillar": pillar or name, "rule": "", "ids": ids}
    return out


def _sync_clusters(db: Session, project: Project, groups: dict[str, dict]) -> dict[int, Cluster]:
    """A még csak javasolt klaszterek újraépülnek; a jóváhagyott/átírt klaszterek megmaradnak."""
    existing = db.scalars(select(Cluster).where(Cluster.project_id == project.id)).all()
    keep = {c.name.lower(): c for c in existing if c.status != "suggested"}
    for c in existing:
        if c.status == "suggested":
            db.delete(c)
    db.flush()
    by_kw: dict[int, Cluster] = {}
    for g in groups.values():
        c = keep.get(g["name"].lower())
        if c is None:
            c = Cluster(project_id=project.id, name=g["name"][:255], pillar=g["pillar"][:255], cannibalization_rule=g["rule"], status="suggested")
            db.add(c)
            keep[g["name"].lower()] = c
        else:
            c.ai = {"pillar": g["pillar"], "rule": g["rule"]}
        for kid in g["ids"]:
            by_kw[kid] = c
    db.flush()
    return by_kw


@handler("analyze_keywords")
def job_analyze(db: Session, job: Job, progress: Progress):
    project = db.get(Project, job.project_id)
    kws = _load(db, project.id)
    if not kws:
        raise JobError("Nincs elemezhető kulcsszó.")
    profile = latest_profile(db, project.id)
    use_llm = llm.openai_available(db)
    progress(0.05, "Szabályalapú előelemzés…")
    brands = [c.domain.split(".")[0].replace("-", "") for c in project.competitors]
    excluded = list(project.excluded_topics or []) + [s.keyword for s in project.seed_keywords if s.kind == "excluded"]
    base: dict[int, dict] = {}
    for k in kws:
        m = best_metric(k)
        intent = heuristics.classify_intent(k.term, k.source_intents, brands)
        base[k.id] = {
            "intent": intent,
            "is_local": heuristics.is_local(k.term, k.term_location, k.source_intents),
            "business_value": heuristics.business_value(k.term, project.business_services or [], excluded, intent),
            "commercial_opportunity": heuristics.commercial_opportunity(intent, m["cpc"], m.get("currency", "")),
            "reason": "",
            "translation": "",
        }
    method = "heuristic"
    if use_llm:
        try:
            ai = _llm_classify(db, project, profile, kws, job, progress)
            for kid, it in ai.items():
                if kid in base:
                    base[kid].update({k: it[k] for k in ("intent", "is_local", "business_value", "commercial_opportunity", "reason", "translation")})
            method = "llm"
        except llm.LLMError as e:
            raise JobError(str(e))
    try:
        groups = _llm_clusters(db, project, kws, job, progress) if use_llm else _heuristic_clusters(project, kws)
    except llm.LLMError as e:
        raise JobError(str(e))
    progress(0.8, "Pontozás és mentés…")
    cluster_of = _sync_clusters(db, project, groups)
    weights = app_settings.get(db, "scoring_weights")
    thresholds = app_settings.get(db, "priority_thresholds")
    max_volume = max((best_metric(k)["volume"] or 0) for k in kws)
    existing = {a.keyword_id: a for a in db.scalars(select(KeywordAnalysis).where(KeywordAnalysis.project_id == project.id)).all()}
    stats = Counter()
    for k in kws:
        b = base[k.id]
        m = best_metric(k)
        mods = heuristics.modifiers(k.term)
        sc = scoring.score(intent=b["intent"], local=b["is_local"], has_locations=bool(project.locations),
                           business_value=int(b["business_value"]), commercial_opportunity=int(b["commercial_opportunity"]),
                           kd=m["kd"], volume=m["volume"], max_volume=max_volume, weights=weights, thresholds=thresholds)
        values = {
            "intent": b["intent"],
            "is_local": bool(b["is_local"]),
            "modifiers": mods,
            "bucket": heuristics.bucket(b["intent"], bool(b["is_local"]), mods, k.term),
            "business_value": int(b["business_value"]),
            "commercial_opportunity": int(b["commercial_opportunity"]),
            "priority": sc.priority,
            "priority_score": sc.score,
            "reason": (b["reason"] or "") + ((" · " if b["reason"] else "") + sc.reason if sc.reason else ""),
        }
        cl = cluster_of.get(k.id)
        a = existing.get(k.id)
        if a is None:
            a = KeywordAnalysis(keyword_id=k.id, project_id=project.id, status="suggested")
            db.add(a)
        a.ai = {**values, "cluster": cl.name if cl else ""}
        a.method = method
        if a.status == "suggested":
            for f, v in values.items():
                setattr(a, f, v)
            a.cluster_id = cl.id if cl else None
            stats["updated"] += 1
        else:
            stats["kept"] += 1
            if a.cluster_id is None and cl:
                a.cluster_id = cl.id
        if b.get("translation") and not k.translation:
            k.translation = b["translation"][:512]
        stats[sc.priority or "none"] += 1
    db.flush()
    refresh_cluster_totals(db, project.id)
    log(db, project.id, job.created_by, "analysis", None, "run",
        f"Kulcsszó-elemzés ({'AI' if method == 'llm' else 'szabályalapú'}): {len(kws)} kulcsszó, {len(groups)} klaszter")
    return {"keywords": len(kws), "clusters": len(groups), "method": method, **dict(stats)}


def refresh_cluster_totals(db: Session, project_id: int) -> None:
    clusters = db.scalars(select(Cluster).where(Cluster.project_id == project_id)).all()
    rows = db.execute(
        select(KeywordAnalysis, Keyword).join(Keyword, Keyword.id == KeywordAnalysis.keyword_id)
        .where(KeywordAnalysis.project_id == project_id, Keyword.is_excluded.is_(False))
        .options(selectinload(Keyword.metrics))
    ).all()
    by_cluster = defaultdict(list)
    for a, k in rows:
        if a.cluster_id:
            by_cluster[a.cluster_id].append((a, k))
    for c in clusters:
        members = by_cluster.get(c.id, [])
        if not members:
            if c.status == "suggested":
                db.delete(c)
            continue
        c.total_volume = sum(best_metric(k)["volume"] or 0 for _, k in members)
        c.intent = Counter(a.intent for a, _ in members).most_common(1)[0][0]
        prios = {a.priority for a, _ in members}
        c.priority = "P1" if "P1" in prios else ("P2" if "P2" in prios else "parked")
    db.flush()


def rescore(db: Session, project: Project, a: KeywordAnalysis, kw: Keyword) -> None:
    """Kézi módosítás után újraszámolt pontszám (a prioritást csak akkor, ha azt nem írták át kézzel)."""
    m = best_metric(kw)
    siblings = db.scalars(select(Keyword).where(Keyword.project_id == project.id, Keyword.is_excluded.is_(False)).options(selectinload(Keyword.metrics))).all()
    max_volume = max((best_metric(k)["volume"] or 0) for k in siblings) if siblings else 0
    sc = scoring.score(intent=a.intent, local=a.is_local, has_locations=bool(project.locations), business_value=a.business_value,
                       commercial_opportunity=a.commercial_opportunity, kd=m["kd"], volume=m["volume"], max_volume=max_volume,
                       weights=app_settings.get(db, "scoring_weights"), thresholds=app_settings.get(db, "priority_thresholds"))
    a.priority_score = sc.score
    return sc


# ── Kulcsszó-sorok kiegészítése (Kulcsszavak fül, XLSX) ─────────


def extend_rows(db: Session, project: Project, rows: list[dict]) -> None:
    ids = [r["id"] for r in rows]
    if not ids:
        return
    analyses = {a.keyword_id: a for a in db.scalars(select(KeywordAnalysis).where(KeywordAnalysis.keyword_id.in_(ids))).all()}
    mapping = db.execute(
        select(PageKeyword.keyword_id, PageKeyword.role, Page.url, Page.id).join(Page, Page.id == PageKeyword.page_id)
        .where(PageKeyword.keyword_id.in_(ids))
    ).all()
    roles: dict[int, tuple[str, str, int]] = {}
    for kid, role, url, pid in mapping:
        if kid not in roles or role == "primary":
            roles[kid] = (role, url, pid)
    for r in rows:
        a = analyses.get(r["id"])
        role = roles.get(r["id"])
        r["role"], r["url"], r["page_id"] = (role if role else ("", "", None))
        if a is None:
            r["analysis"] = None
            continue
        r["analysis"] = a.status
        r.update({
            "intent": a.intent, "bucket": a.bucket, "is_local": a.is_local, "business_value": a.business_value,
            "commercial_opportunity": a.commercial_opportunity, "priority": a.priority,
            "priority_score": float(a.priority_score or 0), "cluster": a.cluster.name if a.cluster else "",
            "cluster_id": a.cluster_id, "reason": a.reason, "analysis_notes": a.notes, "ai": a.ai, "method": a.method,
        })


def export_analysis(db: Session, project: Project) -> dict[int, dict]:
    rows = [{"id": kid} for kid in db.scalars(select(KeywordAnalysis.keyword_id).where(KeywordAnalysis.project_id == project.id)).all()]
    extend_rows(db, project, rows)
    from ..models import INTENTS, PRIORITIES

    return {
        r["id"]: {
            "intent": INTENTS.get(r.get("intent", ""), ""),
            "cluster": r.get("cluster", ""),
            "priority": PRIORITIES.get(r.get("priority", ""), ""),
            "role": {"primary": "Primary", "secondary": "Secondary", "supporting": "Supporting"}.get(r.get("role", ""), ""),
            "url": r.get("url", ""),
            "notes": r.get("analysis_notes") or r.get("reason", ""),
        }
        for r in rows
        if r.get("analysis")
    }


@gate
def analysis_gate(db: Session, project: Project, to: str) -> list[str]:
    if "keyword_research" not in (project.scope or []):
        return []
    if to == "ai_analysis_complete":
        missing = db.scalar(
            select(func.count()).select_from(Keyword)
            .outerjoin(KeywordAnalysis, KeywordAnalysis.keyword_id == Keyword.id)
            .where(Keyword.project_id == project.id, Keyword.is_excluded.is_(False), KeywordAnalysis.keyword_id.is_(None))
        )
        return [f"{missing} kulcsszó még nincs elemezve (Kulcsszavak → AI-elemzés)."] if missing else []
    if to == "client_review":
        open_p1 = db.scalar(
            select(func.count()).select_from(KeywordAnalysis)
            .join(Keyword, Keyword.id == KeywordAnalysis.keyword_id)
            .where(KeywordAnalysis.project_id == project.id, KeywordAnalysis.status == "suggested",
                   KeywordAnalysis.priority == "P1", Keyword.is_excluded.is_(False))
        )
        return [f"{open_p1} P1 kulcsszó javaslata még nincs átnézve (elfogadás vagy módosítás)."] if open_p1 else []
    return []
