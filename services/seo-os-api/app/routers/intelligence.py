"""3. ütem végpontjai: üzleti profil, kulcsszó-elemzés és jóváhagyás, klaszterek, oldalstruktúra, belső linkek,
roadmap, mérési és paid terv, félretett témák, wireframe-ek."""

from datetime import date
from typing import Any, Optional

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel
from sqlalchemy import func, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session, selectinload

from .. import meta
from ..auth import CurrentUser, need
from ..db import get_db, utcnow
from ..jobs import runner
from ..models import (
    BUCKETS,
    CONTENT_TYPES,
    INTENTS,
    LINK_TYPES,
    PAGE_LIFECYCLE,
    PAGE_TYPES,
    PRIORITIES,
    ROADMAP_STATUSES,
    BusinessProfile,
    Cluster,
    InternalLink,
    Keyword,
    KeywordAnalysis,
    MeasurementItem,
    Page,
    PageKeyword,
    PaidPlanItem,
    ParkedTopic,
    RoadmapItem,
    User,
    Wireframe,
)
from ..services import analysis, planning
from ..services.activity import changes, log
from ..services.projects import get_project
from ..services.research import best_metric
from . import dashboard, research as research_router
from .research import job_payload

router = APIRouter(tags=["intelligence"])

meta.extend("intents", INTENTS)
meta.extend("buckets", BUCKETS)
meta.extend("priorities", PRIORITIES)
meta.extend("page_types", PAGE_TYPES)
meta.extend("page_lifecycle", PAGE_LIFECYCLE)
meta.extend("link_types", LINK_TYPES)
meta.extend("roadmap_statuses", ROADMAP_STATUSES)
meta.extend("content_types", CONTENT_TYPES)

research_router.ROW_EXTENDERS.append(analysis.extend_rows)
research_router.ANALYSIS_FOR_EXPORT.append(analysis.export_analysis)


def start_job(db: Session, type_: str, project_id: int, payload: dict, user: CurrentUser) -> dict:
    job = runner.enqueue(db, type_, project_id, payload, user.id)
    return job_payload(job)


# ── Üzleti profil ────────────────────────────────────────


def profile_payload(p: Optional[BusinessProfile]) -> Optional[dict]:
    if p is None:
        return None
    return {
        "id": p.id, "version": p.version, "summary": p.summary, "positioning": p.positioning, "services": p.services,
        "audiences": p.audiences, "differentiators": p.differentiators, "proof_assets": p.proof_assets,
        "site_snapshot": p.site_snapshot, "source": p.source, "approved_at": p.approved_at.isoformat() if p.approved_at else None,
        "created_at": p.created_at.isoformat(),
    }


@router.get("/projects/{project_id}/profile")
def get_profile(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    get_project(db, project_id)
    return profile_payload(analysis.latest_profile(db, project_id))


@router.post("/projects/{project_id}/profile/generate")
def generate_profile(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("ai.run"))):
    get_project(db, project_id)
    return start_job(db, "business_profile", project_id, {}, user)


class ProfileIn(BaseModel):
    summary: Optional[str] = None
    positioning: Optional[str] = None
    services: Optional[list[dict]] = None
    audiences: Optional[list[dict]] = None
    differentiators: Optional[list[str]] = None
    proof_assets: Optional[list[str]] = None
    approve: bool = False


@router.put("/projects/{project_id}/profile")
def put_profile(project_id: int, body: ProfileIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.edit"))):
    get_project(db, project_id)
    p = analysis.latest_profile(db, project_id)
    if p is None:
        p = BusinessProfile(project_id=project_id, version=1, source="manual")
        db.add(p)
    for k, v in body.model_dump(exclude_unset=True, exclude={"approve"}).items():
        if v is not None:
            setattr(p, k, v)
    if body.approve:
        p.approved_by, p.approved_at = user.id or None, utcnow()
    log(db, project_id, user.id, "profile", None, "update", "Üzleti profil " + ("jóváhagyva" if body.approve else "módosítva"))
    db.commit()
    return profile_payload(p)


# ── Kulcsszó-elemzés ─────────────────────────────────────


@router.post("/projects/{project_id}/analysis/run")
def run_analysis(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("ai.run"))):
    get_project(db, project_id)
    return start_job(db, "analyze_keywords", project_id, {}, user)


@router.get("/projects/{project_id}/analysis/summary")
def analysis_summary(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("keywords.view"))):
    get_project(db, project_id)
    rows = db.execute(
        select(KeywordAnalysis.status, KeywordAnalysis.priority, func.count())
        .join(Keyword, Keyword.id == KeywordAnalysis.keyword_id)
        .where(KeywordAnalysis.project_id == project_id, Keyword.is_excluded.is_(False))
        .group_by(KeywordAnalysis.status, KeywordAnalysis.priority)
    ).all()
    by_status, by_priority = {}, {}
    for st, pr, n in rows:
        by_status[st] = by_status.get(st, 0) + n
        by_priority[pr or "none"] = by_priority.get(pr or "none", 0) + n
    active = db.scalar(select(func.count()).select_from(Keyword).where(Keyword.project_id == project_id, Keyword.is_excluded.is_(False))) or 0
    method = db.scalar(select(KeywordAnalysis.method).where(KeywordAnalysis.project_id == project_id).limit(1))
    clusters = db.scalar(select(func.count()).select_from(Cluster).where(Cluster.project_id == project_id)) or 0
    return {"active": active, "analysed": sum(by_status.values()), "by_status": by_status, "by_priority": by_priority,
            "clusters": clusters, "method": method or ""}


class AnalysisPatch(BaseModel):
    intent: Optional[str] = None
    is_local: Optional[bool] = None
    business_value: Optional[int] = None
    commercial_opportunity: Optional[int] = None
    priority: Optional[str] = None
    cluster_id: Optional[int] = None
    notes: Optional[str] = None


@router.patch("/projects/{project_id}/keywords/{keyword_id}/analysis")
def patch_analysis(project_id: int, keyword_id: int, body: AnalysisPatch, db: Session = Depends(get_db), user: CurrentUser = Depends(need("keywords.edit"))):
    project = get_project(db, project_id)
    kw = db.get(Keyword, keyword_id)
    if kw is None or kw.project_id != project_id:
        raise HTTPException(404, "A kulcsszó nem található.")
    a = db.get(KeywordAnalysis, keyword_id) or KeywordAnalysis(keyword_id=keyword_id, project_id=project_id, method="manual")
    db.add(a)
    data = body.model_dump(exclude_unset=True)
    if "intent" in data and data["intent"] not in INTENTS:
        raise HTTPException(422, "Ismeretlen szándék.")
    if "priority" in data and data["priority"] not in PRIORITIES:
        raise HTTPException(422, "Ismeretlen prioritás.")
    for f in ("business_value", "commercial_opportunity"):
        if f in data and not 1 <= int(data[f]) <= 5:
            raise HTTPException(422, "Az érték 1 és 5 között legyen.")
    if data.get("cluster_id") and db.get(Cluster, data["cluster_id"]) is None:
        raise HTTPException(422, "Ismeretlen klaszter.")
    diff = changes(a, data)
    if diff:
        from ..services import heuristics

        a.bucket = heuristics.bucket(a.intent, a.is_local, a.modifiers or [], kw.term)
        sc = analysis.rescore(db, project, a, kw)
        if "priority" not in data:
            a.priority = sc.priority
        a.status, a.reviewed_by, a.reviewed_at = "overridden", user.id or None, utcnow()
        log(db, project_id, user.id, "analysis", keyword_id, "override", kw.term, diff)
    db.commit()
    analysis.refresh_cluster_totals(db, project_id)
    db.commit()
    row = research_router.research.keyword_row(kw, project.domain)
    analysis.extend_rows(db, project, [row])
    return row


class AcceptIn(BaseModel):
    ids: Optional[list[int]] = None
    priority: Optional[str] = None  # pl. csak a P1-ek elfogadása


@router.post("/projects/{project_id}/analysis/accept")
def accept_analysis(project_id: int, body: AcceptIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("keywords.edit"))):
    get_project(db, project_id)
    q = select(KeywordAnalysis).where(KeywordAnalysis.project_id == project_id, KeywordAnalysis.status == "suggested")
    if body.ids is not None:
        q = q.where(KeywordAnalysis.keyword_id.in_(body.ids))
    if body.priority:
        q = q.where(KeywordAnalysis.priority == body.priority)
    rows = db.scalars(q).all()
    now = utcnow()
    for a in rows:
        a.status, a.reviewed_by, a.reviewed_at = "accepted", user.id or None, now
    log(db, project_id, user.id, "analysis", None, "accept", f"{len(rows)} kulcsszó-javaslat elfogadva")
    db.commit()
    return {"accepted": len(rows)}


# ── Klaszterek ───────────────────────────────────────────


@router.get("/projects/{project_id}/clusters")
def list_clusters(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("keywords.view"))):
    get_project(db, project_id)
    clusters = db.scalars(select(Cluster).where(Cluster.project_id == project_id).order_by(Cluster.total_volume.desc())).all()
    rows = db.execute(
        select(KeywordAnalysis, Keyword).join(Keyword, Keyword.id == KeywordAnalysis.keyword_id)
        .where(KeywordAnalysis.project_id == project_id, Keyword.is_excluded.is_(False))
        .options(selectinload(Keyword.metrics))
    ).all()
    members: dict[int, list] = {}
    for a, k in rows:
        if a.cluster_id:
            members.setdefault(a.cluster_id, []).append({"id": k.id, "term": k.term, "bucket": a.bucket, "priority": a.priority,
                                                         "volume": best_metric(k)["volume"], "score": float(a.priority_score or 0)})
    pages = {p.id: p.url for p in db.scalars(select(Page).where(Page.project_id == project_id)).all()}
    out = []
    for c in clusters:
        ms = sorted(members.get(c.id, []), key=lambda m: -m["score"])
        buckets: dict[str, list] = {}
        for m in ms:
            buckets.setdefault(m["bucket"] or "informational", []).append(m)
        out.append({"id": c.id, "name": c.name, "pillar": c.pillar, "intent": c.intent, "priority": c.priority,
                    "total_volume": c.total_volume, "cannibalization_rule": c.cannibalization_rule, "notes": c.notes,
                    "status": c.status, "target_url": pages.get(c.target_page_id, ""), "target_page_id": c.target_page_id,
                    "count": len(ms), "buckets": buckets})
    return out


class ClusterPatch(BaseModel):
    name: Optional[str] = None
    pillar: Optional[str] = None
    cannibalization_rule: Optional[str] = None
    notes: Optional[str] = None
    target_page_id: Optional[int] = None


@router.patch("/projects/{project_id}/clusters/{cluster_id}")
def patch_cluster(project_id: int, cluster_id: int, body: ClusterPatch, db: Session = Depends(get_db), user: CurrentUser = Depends(need("keywords.edit"))):
    c = db.get(Cluster, cluster_id)
    if c is None or c.project_id != project_id:
        raise HTTPException(404, "A klaszter nem található.")
    diff = changes(c, body.model_dump(exclude_unset=True))
    if diff:
        c.status = "overridden"
        log(db, project_id, user.id, "cluster", c.id, "update", c.name, diff)
    db.commit()
    return {"id": c.id, "name": c.name, "pillar": c.pillar, "status": c.status}


class MergeIn(BaseModel):
    ids: list[int]
    into: int


@router.post("/projects/{project_id}/clusters/merge")
def merge_clusters(project_id: int, body: MergeIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("keywords.edit"))):
    target = db.get(Cluster, body.into)
    if target is None or target.project_id != project_id:
        raise HTTPException(404, "A cél klaszter nem található.")
    for cid in body.ids:
        if cid == target.id:
            continue
        c = db.get(Cluster, cid)
        if c is None or c.project_id != project_id:
            continue
        for a in db.scalars(select(KeywordAnalysis).where(KeywordAnalysis.cluster_id == cid)).all():
            a.cluster_id = target.id
        db.delete(c)
    target.status = "overridden"
    db.flush()
    analysis.refresh_cluster_totals(db, project_id)
    log(db, project_id, user.id, "cluster", target.id, "merge", f"Klaszterek összevonva: {target.name}")
    db.commit()
    return {"merged": len(body.ids)}


# ── Oldalstruktúra ───────────────────────────────────────


def page_payload(p: Page, kw: dict[int, Keyword], analyses: dict[int, KeywordAnalysis]) -> dict:
    def k_out(pk):
        k = kw.get(pk.keyword_id)
        a = analyses.get(pk.keyword_id)
        m = best_metric(k) if k else {"volume": None, "kd": None}
        return {"id": pk.keyword_id, "term": k.term if k else "", "role": pk.role, "volume": m["volume"], "kd": m["kd"],
                "intent": a.intent if a else "", "priority": a.priority if a else ""}

    keys = [k_out(pk) for pk in p.keywords]
    return {
        "id": p.id, "url": p.url, "page_type": p.page_type, "parent_id": p.parent_id, "location": p.location, "service": p.service,
        "title": p.title, "seo_title": p.seo_title, "h1": p.h1, "meta_description": p.meta_description, "intent": p.intent,
        "seo_goal": p.seo_goal, "cta_label": p.cta_label, "cta_url": p.cta_url, "priority": p.priority, "lifecycle": p.lifecycle,
        "redirect_to": p.redirect_to, "schema_types": p.schema_types, "word_count_min": p.word_count_min, "word_count_max": p.word_count_max,
        "local_variation": p.local_variation, "notes": p.notes, "sort": p.sort, "status": p.status, "ai": p.ai,
        "primary": next((k for k in keys if k["role"] == "primary"), None),
        "secondary": [k for k in keys if k["role"] != "primary"],
    }


def load_pages(db: Session, project_id: int) -> list[dict]:
    pages = db.scalars(select(Page).where(Page.project_id == project_id).options(selectinload(Page.keywords)).order_by(Page.sort, Page.url)).all()
    ids = {pk.keyword_id for p in pages for pk in p.keywords}
    kw = {k.id: k for k in db.scalars(select(Keyword).where(Keyword.id.in_(ids)).options(selectinload(Keyword.metrics))).all()} if ids else {}
    an = {a.keyword_id: a for a in db.scalars(select(KeywordAnalysis).where(KeywordAnalysis.keyword_id.in_(ids))).all()} if ids else {}
    return [page_payload(p, kw, an) for p in pages]


@router.post("/projects/{project_id}/structure/generate")
def generate_structure(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("ai.run"))):
    get_project(db, project_id)
    return start_job(db, "generate_structure", project_id, {}, user)


@router.get("/projects/{project_id}/pages")
def list_pages(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("structure.view"))):
    project = get_project(db, project_id)
    links = db.scalars(select(InternalLink).where(InternalLink.project_id == project_id)).all()
    return {
        "pages": load_pages(db, project_id),
        "links": [{"id": lk.id, "from_page_id": lk.from_page_id, "to_page_id": lk.to_page_id, "to_url": lk.to_url, "anchor": lk.anchor,
                   "placement": lk.placement, "link_type": lk.link_type, "note": lk.note, "source": lk.source} for lk in links],
        "warnings": planning.structure_warnings(db, project),
    }


class PageIn(BaseModel):
    url: Optional[str] = None
    page_type: Optional[str] = None
    parent_id: Optional[int] = None
    location: Optional[str] = None
    service: Optional[str] = None
    title: Optional[str] = None
    seo_title: Optional[str] = None
    h1: Optional[str] = None
    meta_description: Optional[str] = None
    intent: Optional[str] = None
    seo_goal: Optional[str] = None
    cta_label: Optional[str] = None
    cta_url: Optional[str] = None
    priority: Optional[str] = None
    lifecycle: Optional[str] = None
    redirect_to: Optional[str] = None
    schema_types: Optional[list[str]] = None
    word_count_min: Optional[int] = None
    word_count_max: Optional[int] = None
    local_variation: Optional[str] = None
    notes: Optional[str] = None
    status: Optional[str] = None


def normalize_url(u: str) -> str:
    u = "/" + u.strip().strip("/") + "/" if u.strip().strip("/") else "/"
    return u.replace("//", "/")


@router.post("/projects/{project_id}/pages", status_code=201)
def create_page(project_id: int, body: PageIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("structure.edit"))):
    get_project(db, project_id)
    data = body.model_dump(exclude_none=True)
    if not data.get("url") or data.get("page_type") not in PAGE_TYPES:
        raise HTTPException(422, "Az URL és az oldaltípus kötelező.")
    data["url"] = normalize_url(data["url"])
    data.setdefault("status", "accepted")
    p = Page(project_id=project_id, **data)
    db.add(p)
    try:
        db.flush()
    except IntegrityError:
        db.rollback()
        raise HTTPException(409, "Ez az URL már szerepel a struktúrában.")
    log(db, project_id, user.id, "page", p.id, "create", p.url)
    db.commit()
    return {"id": p.id}


@router.patch("/projects/{project_id}/pages/{page_id}")
def patch_page(project_id: int, page_id: int, body: PageIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("structure.edit"))):
    p = db.get(Page, page_id)
    if p is None or p.project_id != project_id:
        raise HTTPException(404, "Az oldal nem található.")
    data = body.model_dump(exclude_unset=True)
    if "url" in data and data["url"]:
        data["url"] = normalize_url(data["url"])
    if "page_type" in data and data["page_type"] not in PAGE_TYPES:
        raise HTTPException(422, "Ismeretlen oldaltípus.")
    if "lifecycle" in data and data["lifecycle"] not in PAGE_LIFECYCLE:
        raise HTTPException(422, "Ismeretlen állapot.")
    status = data.pop("status", None)
    diff = changes(p, data)
    if status in ("accepted", "suggested", "overridden"):
        p.status = status
    elif diff:
        p.status = "overridden"
    try:
        db.flush()
    except IntegrityError:
        db.rollback()
        raise HTTPException(409, "Ez az URL már szerepel a struktúrában.")
    if diff:
        log(db, project_id, user.id, "page", p.id, "update", p.url, diff)
    db.commit()
    return next(x for x in load_pages(db, project_id) if x["id"] == page_id)


@router.delete("/projects/{project_id}/pages/{page_id}")
def delete_page(project_id: int, page_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("structure.edit"))):
    p = db.get(Page, page_id)
    if p is None or p.project_id != project_id:
        raise HTTPException(404, "Az oldal nem található.")
    log(db, project_id, user.id, "page", p.id, "delete", p.url)
    db.delete(p)
    db.commit()
    return {"deleted": True}


class PageKeywordsIn(BaseModel):
    primary: Optional[int] = None
    secondary: list[int] = []


@router.put("/projects/{project_id}/pages/{page_id}/keywords")
def put_page_keywords(project_id: int, page_id: int, body: PageKeywordsIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("structure.edit"))):
    p = db.get(Page, page_id)
    if p is None or p.project_id != project_id:
        raise HTTPException(404, "Az oldal nem található.")
    ids = set(body.secondary) | ({body.primary} if body.primary else set())
    valid = set(db.scalars(select(Keyword.id).where(Keyword.project_id == project_id, Keyword.id.in_(ids))).all()) if ids else set()
    if ids - valid:
        raise HTTPException(422, "Ismeretlen kulcsszó.")
    if body.primary:
        other = db.execute(
            select(Page.url).join(PageKeyword, PageKeyword.page_id == Page.id)
            .where(PageKeyword.keyword_id == body.primary, PageKeyword.role == "primary", Page.id != page_id)
        ).scalar()
        if other:
            raise HTTPException(409, f"Ez a kulcsszó már a(z) {other} oldal elsődleges kulcsszava. Egy elsődleges kulcsszó = egy URL.")
    # A másodlagosak más oldalról átkerülnek ide.
    db.query(PageKeyword).filter(PageKeyword.keyword_id.in_(body.secondary), PageKeyword.role != "primary").delete(synchronize_session=False)
    db.query(PageKeyword).filter(PageKeyword.page_id == page_id).delete(synchronize_session=False)
    db.flush()
    if body.primary:
        db.add(PageKeyword(page_id=page_id, keyword_id=body.primary, role="primary"))
    for kid in body.secondary:
        if kid != body.primary:
            db.add(PageKeyword(page_id=page_id, keyword_id=kid, role="secondary"))
    p.status = "overridden"
    try:
        db.flush()
    except IntegrityError:
        db.rollback()
        raise HTTPException(409, "Kannibalizáció: a kulcsszó már egy másik oldal elsődleges kulcsszava.")
    log(db, project_id, user.id, "page", p.id, "keywords", p.url)
    db.commit()
    return next(x for x in load_pages(db, project_id) if x["id"] == page_id)


class LinkIn(BaseModel):
    from_page_id: int
    to_page_id: Optional[int] = None
    to_url: str = ""
    anchor: str = ""
    placement: str = ""
    link_type: str = "text"
    note: str = ""


@router.post("/projects/{project_id}/links", status_code=201)
def create_link(project_id: int, body: LinkIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("structure.edit"))):
    frm = db.get(Page, body.from_page_id)
    if frm is None or frm.project_id != project_id or body.link_type not in LINK_TYPES:
        raise HTTPException(422, "Hibás link.")
    to = db.get(Page, body.to_page_id) if body.to_page_id else None
    link = InternalLink(project_id=project_id, source="manual", **{**body.model_dump(), "to_url": to.url if to else body.to_url})
    db.add(link)
    db.commit()
    return {"id": link.id}


@router.delete("/projects/{project_id}/links/{link_id}")
def delete_link(project_id: int, link_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("structure.edit"))):
    link = db.get(InternalLink, link_id)
    if link is None or link.project_id != project_id:
        raise HTTPException(404, "A link nem található.")
    db.delete(link)
    db.commit()
    return {"deleted": True}


@router.post("/projects/{project_id}/structure/accept")
def accept_structure(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("structure.edit"))):
    n = 0
    for p in db.scalars(select(Page).where(Page.project_id == project_id, Page.status == "suggested")).all():
        p.status = "accepted"
        n += 1
    log(db, project_id, user.id, "structure", None, "accept", f"{n} oldal elfogadva")
    db.commit()
    return {"accepted": n}


# ── Roadmap, mérési és paid terv ─────────────────────────


def roadmap_payload(i: RoadmapItem, users: dict[int, str], paid: dict[int, PaidPlanItem]) -> dict:
    pp = paid.get(i.id)
    return {
        "id": i.id, "month": i.month.isoformat(), "priority": i.priority, "page_id": i.page_id, "cluster_id": i.cluster_id,
        "keyword_id": i.keyword_id, "content_type": i.content_type, "pillar": i.pillar, "title": i.title, "url": i.url,
        "related_keywords": i.related_keywords, "location_context": i.location_context, "content_direction": i.content_direction,
        "cta": i.cta, "internal_links": i.internal_links, "social_hook": i.social_hook, "cannibalization_rule": i.cannibalization_rule,
        "client_input": i.client_input, "status": i.status, "assignee_id": i.assignee_id, "assignee": users.get(i.assignee_id, ""),
        "due_date": i.due_date.isoformat() if i.due_date else None, "review": i.review,
        "paid": {"id": pp.id, "seo_role": pp.seo_role, "meta_creative": pp.meta_creative, "paid_role": pp.paid_role,
                 "search_target": pp.search_target, "remarketing_next": pp.remarketing_next, "kpi": pp.kpi} if pp else None,
    }


@router.post("/projects/{project_id}/roadmap/generate")
def generate_roadmap(project_id: int, include_paid: bool = True, db: Session = Depends(get_db), user: CurrentUser = Depends(need("ai.run"))):
    get_project(db, project_id)
    return start_job(db, "generate_roadmap", project_id, {"include_paid": include_paid}, user)


@router.get("/projects/{project_id}/roadmap")
def get_roadmap(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("strategy.view"))):
    get_project(db, project_id)
    items = db.scalars(select(RoadmapItem).where(RoadmapItem.project_id == project_id).order_by(RoadmapItem.month, RoadmapItem.sort, RoadmapItem.id)).all()
    users = {u.id: u.display_name for u in db.scalars(select(User)).all()}
    paid = {p.roadmap_item_id: p for p in db.scalars(select(PaidPlanItem).where(PaidPlanItem.project_id == project_id)).all()}
    measurement = db.scalars(select(MeasurementItem).where(MeasurementItem.project_id == project_id).order_by(MeasurementItem.sort)).all()
    parked = db.scalars(select(ParkedTopic).where(ParkedTopic.project_id == project_id).order_by(ParkedTopic.id)).all()
    kws = {k.id: k for k in db.scalars(select(Keyword).where(Keyword.id.in_([p.keyword_id for p in parked if p.keyword_id] + [i.keyword_id for i in items if i.keyword_id]))
                                       .options(selectinload(Keyword.metrics))).all()}
    return {
        "items": [dict(roadmap_payload(i, users, paid), volume=best_metric(kws[i.keyword_id])["volume"] if i.keyword_id in kws else None,
                       keyword=kws[i.keyword_id].term if i.keyword_id in kws else "") for i in items],
        "measurement": [{"id": m.id, "period": m.period, "focus": m.focus, "what": m.what, "where_measured": m.where_measured,
                         "success_signal": m.success_signal, "decision": m.decision, "content_scope": m.content_scope, "owner": m.owner,
                         "status": m.status, "note": m.note} for m in measurement],
        "parked": [{"id": p.id, "term": p.term, "pillar": p.pillar, "reason": p.reason, "recommended_handling": p.recommended_handling,
                    "volume": best_metric(kws[p.keyword_id])["volume"] if p.keyword_id in kws else None,
                    "kd": best_metric(kws[p.keyword_id])["kd"] if p.keyword_id in kws else None} for p in parked],
    }


class RoadmapPatch(BaseModel):
    month: Optional[date] = None
    priority: Optional[str] = None
    content_type: Optional[str] = None
    pillar: Optional[str] = None
    title: Optional[str] = None
    url: Optional[str] = None
    location_context: Optional[str] = None
    content_direction: Optional[str] = None
    cta: Optional[str] = None
    internal_links: Optional[list[str]] = None
    social_hook: Optional[str] = None
    cannibalization_rule: Optional[str] = None
    client_input: Optional[str] = None
    status: Optional[str] = None
    assignee_id: Optional[int] = None
    due_date: Optional[date] = None
    review: Optional[str] = None


@router.patch("/projects/{project_id}/roadmap/{item_id}")
def patch_roadmap(project_id: int, item_id: int, body: RoadmapPatch, db: Session = Depends(get_db), user: CurrentUser = Depends(need("strategy.status"))):
    i = db.get(RoadmapItem, item_id)
    if i is None or i.project_id != project_id:
        raise HTTPException(404, "A tétel nem található.")
    data = body.model_dump(exclude_unset=True)
    # A content manager csak az állapotot, a felelőst és a határidőt állíthatja.
    if not user.can("strategy.edit"):
        extra = set(data) - {"status", "assignee_id", "due_date"}
        if extra:
            raise HTTPException(403, "Csak az állapot, a felelős és a határidő módosítható.")
    if "status" in data and data["status"] not in ROADMAP_STATUSES:
        raise HTTPException(422, "Ismeretlen állapot.")
    if data.get("month"):
        data["month"] = data["month"].replace(day=1)
    review = data.pop("review", None)
    diff = changes(i, data)
    if review in ("accepted", "suggested"):
        i.review = review
    elif diff and set(diff) - {"status", "assignee_id", "due_date"}:
        i.review = "overridden"
    if diff:
        log(db, project_id, user.id, "roadmap", i.id, "update", i.title, diff)
    db.commit()
    users = {u.id: u.display_name for u in db.scalars(select(User)).all()}
    paid = {p.roadmap_item_id: p for p in db.scalars(select(PaidPlanItem).where(PaidPlanItem.roadmap_item_id == i.id)).all()}
    return roadmap_payload(i, users, paid)


@router.post("/projects/{project_id}/roadmap/accept")
def accept_roadmap(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("strategy.edit"))):
    n = 0
    for i in db.scalars(select(RoadmapItem).where(RoadmapItem.project_id == project_id, RoadmapItem.review == "suggested")).all():
        i.review = "accepted"
        n += 1
    log(db, project_id, user.id, "roadmap", None, "accept", f"{n} roadmap-tétel elfogadva")
    db.commit()
    return {"accepted": n}


@router.delete("/projects/{project_id}/roadmap/{item_id}")
def delete_roadmap(project_id: int, item_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("strategy.edit"))):
    i = db.get(RoadmapItem, item_id)
    if i is None or i.project_id != project_id:
        raise HTTPException(404, "A tétel nem található.")
    db.delete(i)
    db.commit()
    return {"deleted": True}


class GenericPatch(BaseModel):
    model_config = {"extra": "allow"}


@router.patch("/projects/{project_id}/measurement/{item_id}")
def patch_measurement(project_id: int, item_id: int, body: dict[str, Any], db: Session = Depends(get_db), user: CurrentUser = Depends(need("strategy.edit"))):
    m = db.get(MeasurementItem, item_id)
    if m is None or m.project_id != project_id:
        raise HTTPException(404, "A tétel nem található.")
    allowed = {"period", "focus", "what", "where_measured", "success_signal", "decision", "content_scope", "owner", "status", "note"}
    changes(m, {k: str(v) for k, v in body.items() if k in allowed})
    db.commit()
    return {"id": m.id}


@router.patch("/projects/{project_id}/paid/{item_id}")
def patch_paid(project_id: int, item_id: int, body: dict[str, Any], db: Session = Depends(get_db), user: CurrentUser = Depends(need("strategy.edit"))):
    p = db.get(PaidPlanItem, item_id)
    if p is None or p.project_id != project_id:
        raise HTTPException(404, "A tétel nem található.")
    allowed = {"seo_role", "meta_creative", "paid_role", "search_target", "remarketing_next", "kpi"}
    changes(p, {k: str(v) for k, v in body.items() if k in allowed})
    db.commit()
    return {"id": p.id}


@router.delete("/projects/{project_id}/parked/{item_id}")
def delete_parked(project_id: int, item_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("strategy.edit"))):
    p = db.get(ParkedTopic, item_id)
    if p is None or p.project_id != project_id:
        raise HTTPException(404, "A tétel nem található.")
    db.delete(p)
    db.commit()
    return {"deleted": True}


# ── Wireframe-ek ─────────────────────────────────────────


def wireframe_payload(w: Wireframe, page: Optional[Page] = None) -> dict:
    return {
        "id": w.id, "page_id": w.page_id, "roadmap_item_id": w.roadmap_item_id, "version": w.version, "label": w.label,
        "essence": w.essence, "flow": w.flow, "word_count_min": w.word_count_min, "word_count_max": w.word_count_max,
        "sections": w.sections, "links": w.links, "proof_requirements": w.proof_requirements, "must_have": w.must_have,
        "forbidden": w.forbidden, "visual_sequence": w.visual_sequence, "inputs": w.inputs, "ux_notes": w.ux_notes,
        "status": w.status, "method": w.method, "approved_at": w.approved_at.isoformat() if w.approved_at else None,
        "updated_at": w.updated_at.isoformat() if w.updated_at else None,
        "page": {"url": page.url, "page_type": page.page_type, "h1": page.h1, "title": page.title} if page else None,
    }


class WireframeGen(BaseModel):
    page_ids: list[int] = []


@router.post("/projects/{project_id}/wireframes/generate")
def generate_wireframes(project_id: int, body: WireframeGen, db: Session = Depends(get_db), user: CurrentUser = Depends(need("wireframes.edit"))):
    get_project(db, project_id)
    return start_job(db, "generate_wireframes", project_id, {"page_ids": body.page_ids}, user)


@router.get("/projects/{project_id}/wireframes")
def list_wireframes(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("wireframes.view"))):
    get_project(db, project_id)
    latest = {}
    for w in db.scalars(select(Wireframe).where(Wireframe.project_id == project_id).order_by(Wireframe.version)).all():
        latest[w.page_id] = w
    pages = db.scalars(select(Page).where(Page.project_id == project_id).order_by(Page.sort, Page.url)).all()
    roadmap = {i.page_id: i for i in db.scalars(select(RoadmapItem).where(RoadmapItem.project_id == project_id)).all() if i.page_id}
    return [
        {"page_id": p.id, "url": p.url, "page_type": p.page_type, "h1": p.h1, "title": p.title, "priority": p.priority,
         "month": roadmap[p.id].month.isoformat() if p.id in roadmap else None,
         "wireframe": {"id": latest[p.id].id, "version": latest[p.id].version, "status": latest[p.id].status, "method": latest[p.id].method,
                       "updated_at": latest[p.id].updated_at.isoformat()} if p.id in latest else None}
        for p in pages
    ]


@router.get("/projects/{project_id}/wireframes/{wireframe_id}")
def get_wireframe(project_id: int, wireframe_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("wireframes.view"))):
    w = db.get(Wireframe, wireframe_id)
    if w is None or w.project_id != project_id:
        raise HTTPException(404, "A wireframe nem található.")
    page = db.get(Page, w.page_id)
    out = wireframe_payload(w, page)
    ctx_keywords = db.execute(select(Keyword, PageKeyword.role).join(PageKeyword, PageKeyword.keyword_id == Keyword.id)
                              .where(PageKeyword.page_id == w.page_id).options(selectinload(Keyword.metrics))).all()
    out["keywords"] = [{"term": k.term, "role": role, **best_metric(k)} for k, role in ctx_keywords]
    out["versions"] = [{"id": x.id, "version": x.version, "status": x.status} for x in
                       db.scalars(select(Wireframe).where(Wireframe.page_id == w.page_id).order_by(Wireframe.version.desc())).all()]
    return out


class WireframePatch(BaseModel):
    essence: Optional[str] = None
    flow: Optional[list[str]] = None
    word_count_min: Optional[int] = None
    word_count_max: Optional[int] = None
    sections: Optional[list[dict]] = None
    links: Optional[list[dict]] = None
    proof_requirements: Optional[str] = None
    must_have: Optional[list[str]] = None
    forbidden: Optional[list[str]] = None
    visual_sequence: Optional[str] = None
    inputs: Optional[list[dict]] = None
    ux_notes: Optional[str] = None
    status: Optional[str] = None


@router.patch("/projects/{project_id}/wireframes/{wireframe_id}")
def patch_wireframe(project_id: int, wireframe_id: int, body: WireframePatch, db: Session = Depends(get_db), user: CurrentUser = Depends(need("wireframes.edit"))):
    w = db.get(Wireframe, wireframe_id)
    if w is None or w.project_id != project_id:
        raise HTTPException(404, "A wireframe nem található.")
    data = body.model_dump(exclude_unset=True)
    status = data.pop("status", None)
    if w.status == "approved" and data:
        raise HTTPException(409, "Jóváhagyott wireframe nem szerkeszthető. Készíts új verziót.")
    changes(w, data)
    if status in ("draft", "review", "approved"):
        if status == "approved":
            if not user.can("approve.internal"):
                raise HTTPException(403, "A wireframe-et a SEO manager hagyja jóvá.")
            w.approved_by, w.approved_at = user.id or None, utcnow()
        w.status = status
    log(db, project_id, user.id, "wireframe", w.id, "update", w.label)
    db.commit()
    return wireframe_payload(w, db.get(Page, w.page_id))


# ── Vezérlőpult: e havi tartalmak ─────────────────────────


@dashboard.widget
def roadmap_widget(db: Session, user: CurrentUser) -> dict:
    today = date.today()
    month = today.replace(day=1)
    from ..models import Project

    rows = db.execute(
        select(RoadmapItem, Project.name).join(Project, Project.id == RoadmapItem.project_id)
        .where(RoadmapItem.month == month, RoadmapItem.status != "published", Project.archived_at.is_(None))
        .order_by(Project.name, RoadmapItem.sort)
    ).all()
    return {"roadmap_due": [{"id": i.id, "project_id": i.project_id, "project": name, "title": i.title,
                             "status_label": ROADMAP_STATUSES.get(i.status, i.status)} for i, name in rows[:15]]}
