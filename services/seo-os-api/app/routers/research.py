"""Kutatás: fájlfeltöltés és import, kulcsszó-adatbázis, kulcsszókutatás XLSX, stílusbeli irányelvek, háttérfeladatok."""

from typing import Any, Optional
from urllib.parse import unquote

from fastapi import APIRouter, Body, Depends, HTTPException, Request
from pydantic import BaseModel, Field
from sqlalchemy import func, select
from sqlalchemy.orm import Session, selectinload

from .. import meta
from ..auth import CurrentUser, need
from ..db import get_db, utcnow
from ..jobs import runner
from ..models import IMPORT_SOURCES, STYLE_QUESTIONS, Import, IntakeItem, Job, Keyword, StyleGuide
from ..services import importers, research, storage, styleguide, xlsx
from ..services.activity import log
from ..services.files import file_response, slug
from ..services.projects import get_project

router = APIRouter(tags=["research"])
meta.extend("import_sources", IMPORT_SOURCES)


def import_payload(imp: Import) -> dict[str, Any]:
    return {
        "id": imp.id,
        "source": imp.source,
        "source_label": IMPORT_SOURCES.get(imp.source, imp.source),
        "sheet": imp.sheet,
        "header_row": imp.header_row,
        "location": imp.location,
        "column_map": imp.column_map,
        "competitor_map": imp.competitor_map,
        "row_count": imp.row_count,
        "stats": imp.stats,
        "status": imp.status,
        "error": imp.error,
        "filename": imp.file.filename if imp.file else "",
        "created_at": imp.created_at.isoformat(),
    }


def detect_for(db: Session, imp: Import, project, sheet_name: Optional[str] = None) -> dict:
    sheets = importers.read_table(storage.path_of(imp.file), imp.file.filename)
    if sheet_name:
        sheets = [s for s in sheets if s.name == sheet_name] or sheets
    det = importers.detect(sheets, imp.file.filename, project.locations or [])
    imp.source, imp.sheet, imp.header_row = det.source, det.sheet, det.header_row
    imp.column_map, imp.competitor_map, imp.location, imp.row_count = det.column_map, det.competitor_map, det.location, det.row_count
    out = det.as_dict()
    out["sheets"] = [s.name for s in importers.read_table(storage.path_of(imp.file), imp.file.filename)]
    return out


@router.post("/projects/{project_id}/imports", status_code=201)
async def upload_import(project_id: int, request: Request, db: Session = Depends(get_db), user: CurrentUser = Depends(need("research.edit"))):
    project = get_project(db, project_id)
    filename = unquote(request.headers.get("x-filename", "")) or "import.xlsx"
    data = await request.body()
    if not data:
        raise HTTPException(422, "Üres fájl.")
    f = storage.save(db, data, filename, "import", project.id, user.id, request.headers.get("content-type", ""))
    imp = Import(project_id=project.id, file_id=f.id, source="manual", created_by=user.id or None)
    imp.file = f
    db.add(imp)
    try:
        detection = detect_for(db, imp, project)
    except ValueError as e:
        db.rollback()
        raise HTTPException(422, str(e))
    db.commit()
    return {"import": import_payload(imp), "detection": detection}


class ImportPatch(BaseModel):
    source: Optional[str] = None
    sheet: Optional[str] = None
    header_row: Optional[int] = Field(default=None, ge=0)
    location: Optional[str] = None
    column_map: Optional[dict[str, str]] = None
    competitor_map: Optional[dict[str, dict[str, str]]] = None


@router.patch("/projects/{project_id}/imports/{import_id}")
def patch_import(project_id: int, import_id: int, body: ImportPatch, db: Session = Depends(get_db), user: CurrentUser = Depends(need("research.edit"))):
    project = get_project(db, project_id)
    imp = db.get(Import, import_id)
    if imp is None or imp.project_id != project.id:
        raise HTTPException(404, "Az import nem található.")
    if imp.status not in ("preview", "failed"):
        raise HTTPException(409, "A lefutott import már nem módosítható.")
    detection = None
    if body.sheet and body.sheet != imp.sheet:
        detection = detect_for(db, imp, project, body.sheet)
    data = body.model_dump(exclude_unset=True, exclude={"sheet"})
    if data.get("source") and data["source"] not in IMPORT_SOURCES:
        raise HTTPException(422, "Ismeretlen forrás.")
    for k, v in data.items():
        if v is not None:
            setattr(imp, k, v)
    db.commit()
    return {"import": import_payload(imp), "detection": detection}


@router.get("/projects/{project_id}/imports/{import_id}")
def get_import(project_id: int, import_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("research.view"))):
    """Import újranyitása: a mentett hozzárendelés + friss előnézet a kiválasztott munkalapból."""
    project = get_project(db, project_id)
    imp = db.get(Import, import_id)
    if imp is None or imp.project_id != project.id or imp.file is None:
        raise HTTPException(404, "Az import nem található.")
    sheets = importers.read_table(storage.path_of(imp.file), imp.file.filename)
    sheet = next((s for s in sheets if s.name == imp.sheet), sheets[0])
    headers = [str(c) if c is not None else "" for c in sheet.rows[imp.header_row]] if sheet.rows else []
    rows = [r for r in sheet.rows[imp.header_row + 1 :] if any(c not in (None, "") for c in r)]
    preview = [{headers[i]: importers._plain(r[i]) for i in range(min(len(headers), len(r))) if headers[i]} for r in rows[:8]]
    return {
        "import": import_payload(imp),
        "detection": {"columns": headers, "preview": preview, "row_count": len(rows), "sheets": [s.name for s in sheets],
                      "source": imp.source, "sf_issue": ""},
    }


@router.post("/projects/{project_id}/imports/{import_id}/run")
def run_import(project_id: int, import_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("research.edit"))):
    get_project(db, project_id)
    imp = db.get(Import, import_id)
    if imp is None or imp.project_id != project_id:
        raise HTTPException(404, "Az import nem található.")
    if imp.source == "screaming_frog":
        raise HTTPException(409, "A Screaming Frog exportot a Technikai audit fülön lehet feldolgozni.")
    if "term" not in (imp.column_map or {}):
        raise HTTPException(422, "Válaszd ki, melyik oszlopban vannak a kulcsszavak.")
    imp.status, imp.error = "queued", ""
    db.commit()
    job = runner.enqueue(db, "import_keywords", project_id, {"import_id": imp.id}, user.id)
    db.refresh(imp)
    return {"job": job_payload(job), "import": import_payload(imp)}


@router.get("/projects/{project_id}/imports")
def list_imports(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("research.view"))):
    get_project(db, project_id)
    rows = db.scalars(select(Import).where(Import.project_id == project_id).order_by(Import.id.desc())).all()
    return [import_payload(i) for i in rows]


@router.delete("/projects/{project_id}/imports/{import_id}")
def delete_import(project_id: int, import_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("research.edit"))):
    imp = db.get(Import, import_id)
    if imp is None or imp.project_id != project_id:
        raise HTTPException(404, "Az import nem található.")
    if imp.file:
        storage.delete(imp.file)
        db.delete(imp.file)
    db.delete(imp)
    db.commit()
    return {"deleted": True}


# ── Kulcsszavak ──────────────────────────────────────────


def load_keywords(db: Session, project_id: int) -> list[Keyword]:
    return db.scalars(
        select(Keyword)
        .where(Keyword.project_id == project_id)
        .options(selectinload(Keyword.metrics), selectinload(Keyword.rankings))
        .order_by(Keyword.id)
    ).all()


# A 3. ütem ezzel egészíti ki a sorokat (szándék, klaszter, prioritás, URL).
ROW_EXTENDERS: list = []


@router.get("/projects/{project_id}/keywords")
def list_keywords(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("keywords.view"))):
    project = get_project(db, project_id)
    kws = load_keywords(db, project_id)
    rows = [research.keyword_row(k, project.domain) for k in kws]
    for ext in ROW_EXTENDERS:
        ext(db, project, rows)
    return {
        "keywords": rows,
        "locations": sorted({loc for r in rows for loc in r["locations"]}),
        "competitors": sorted({c["domain"] for r in rows for c in r["competitors"]}),
    }


class KeywordsAdd(BaseModel):
    terms: list[str]


@router.post("/projects/{project_id}/keywords", status_code=201)
def add_keywords(project_id: int, body: KeywordsAdd, db: Session = Depends(get_db), user: CurrentUser = Depends(need("keywords.edit"))):
    project = get_project(db, project_id)
    cache = research.keyword_cache(db, project_id)
    excluded = research.exclusion_terms(project)
    seeds = {importers.normalize_term(s.keyword) for s in project.seed_keywords if s.kind != "excluded"}
    created = 0
    for t in body.terms:
        if t.strip():
            _, c = research.upsert_keyword(db, project, cache, t, "manual", None, excluded, seeds)
            created += int(c)
    log(db, project_id, user.id, "keywords", None, "add", f"Kézzel hozzáadva: {created} kulcsszó")
    db.commit()
    return {"created": created}


@router.post("/projects/{project_id}/keywords/from-seeds")
def keywords_from_seeds(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("keywords.edit"))):
    project = get_project(db, project_id)
    terms = [s.keyword for s in project.seed_keywords if s.kind not in ("excluded", "location")]
    return add_keywords(project_id, KeywordsAdd(terms=terms), db, user)


class KeywordPatch(BaseModel):
    translation: Optional[str] = None
    notes: Optional[str] = None
    category: Optional[str] = None
    parent_topic: Optional[str] = None
    is_excluded: Optional[bool] = None
    exclusion_reason: Optional[str] = None


@router.patch("/projects/{project_id}/keywords/{keyword_id}")
def patch_keyword(project_id: int, keyword_id: int, body: KeywordPatch, db: Session = Depends(get_db), user: CurrentUser = Depends(need("keywords.edit"))):
    project = get_project(db, project_id)
    kw = db.get(Keyword, keyword_id)
    if kw is None or kw.project_id != project_id:
        raise HTTPException(404, "A kulcsszó nem található.")
    data = body.model_dump(exclude_unset=True)
    for k, v in data.items():
        if v is not None:
            setattr(kw, k, v)
    if data.get("is_excluded") is False:
        kw.exclusion_reason = ""
    elif data.get("is_excluded") and not kw.exclusion_reason:
        kw.exclusion_reason = "Kézzel kizárva"
    db.commit()
    row = research.keyword_row(kw, project.domain)
    for ext in ROW_EXTENDERS:
        ext(db, project, [row])
    return row


class Bulk(BaseModel):
    ids: list[int]
    action: str
    value: Optional[Any] = None


@router.post("/projects/{project_id}/keywords/bulk")
def bulk_keywords(project_id: int, body: Bulk, db: Session = Depends(get_db), user: CurrentUser = Depends(need("keywords.edit"))):
    get_project(db, project_id)
    if not body.ids:
        return {"changed": 0}
    if body.action == "delete":
        n = research.delete_keywords(db, project_id, body.ids)
        log(db, project_id, user.id, "keywords", None, "delete", f"{n} kulcsszó törölve")
        db.commit()
        return {"changed": n}
    kws = db.scalars(select(Keyword).where(Keyword.project_id == project_id, Keyword.id.in_(body.ids))).all()
    for kw in kws:
        if body.action == "exclude":
            kw.is_excluded, kw.exclusion_reason = True, str(body.value or "Kézzel kizárva")
        elif body.action == "include":
            kw.is_excluded, kw.exclusion_reason = False, ""
        elif body.action == "category":
            kw.category = str(body.value or "")
        else:
            raise HTTPException(422, "Ismeretlen művelet.")
    log(db, project_id, user.id, "keywords", None, body.action, f"{len(kws)} kulcsszó: {body.action}")
    db.commit()
    return {"changed": len(kws)}


@router.post("/projects/{project_id}/keywords/reapply-exclusions")
def reapply(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("keywords.edit"))):
    project = get_project(db, project_id)
    n = research.reapply_exclusions(db, project)
    db.commit()
    return {"changed": n}


@router.get("/projects/{project_id}/research/summary")
def research_summary(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    get_project(db, project_id)
    total = db.scalar(select(func.count()).select_from(Keyword).where(Keyword.project_id == project_id)) or 0
    excluded = db.scalar(select(func.count()).select_from(Keyword).where(Keyword.project_id == project_id, Keyword.is_excluded)) or 0
    imports = db.scalar(select(func.count()).select_from(Import).where(Import.project_id == project_id, Import.status == "done")) or 0
    return {"keywords": total, "excluded": excluded, "active": total - excluded, "imports": imports}


# XLSX-hez szükséges elemzési adatok (a 3. ütem tölti fel).
ANALYSIS_FOR_EXPORT: list = []


@router.get("/projects/{project_id}/export/keyword-research.xlsx")
def export_keyword_research(project_id: int, translation: bool = False, db: Session = Depends(get_db), user: CurrentUser = Depends(need("keywords.view"))):
    project = get_project(db, project_id)
    kws = [k for k in load_keywords(db, project_id) if not k.is_excluded]
    analysis: dict[int, dict] = {}
    for fn in ANALYSIS_FOR_EXPORT:
        analysis.update(fn(db, project))
    competitors = [c.domain for c in project.competitors if c.is_active]
    data = xlsx.keyword_research(project, kws, competitors, analysis, include_translation=translation)
    return file_response(data, f"Kulcsszókutatás - {slug(project.domain)}.xlsx")


# ── Stílusbeli irányelvek ─────────────────────────────────


@router.get("/projects/{project_id}/style-guide")
def get_style_guide(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    project = get_project(db, project_id)
    sg = db.get(StyleGuide, project_id)
    return {
        "questions": [{"key": k, "question": q, "hint": h} for k, q, h in STYLE_QUESTIONS],
        "brand_name": sg.brand_name if sg else project.client.name,
        "answers": sg.answers if sg else {},
        "received_at": sg.received_at.isoformat() if sg and sg.received_at else None,
    }


class StyleGuideIn(BaseModel):
    brand_name: Optional[str] = None
    answers: dict[str, str] = {}
    mark_received: bool = False


@router.put("/projects/{project_id}/style-guide")
def put_style_guide(project_id: int, body: StyleGuideIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("strategy.status"))):
    project = get_project(db, project_id)
    sg = db.get(StyleGuide, project_id) or StyleGuide(project_id=project_id)
    db.add(sg)
    keys = {k for k, _, _ in STYLE_QUESTIONS}
    sg.answers = {k: v for k, v in body.answers.items() if k in keys}
    if body.brand_name is not None:
        sg.brand_name = body.brand_name
    if body.mark_received:
        sg.received_at = utcnow()
        item = db.scalar(select(IntakeItem).where(IntakeItem.project_id == project_id, IntakeItem.key == "style_guide"))
        if item:
            item.status = "received"
    log(db, project_id, user.id, "style_guide", None, "update", "Stílusbeli irányelvek frissítve")
    db.commit()
    return get_style_guide(project_id, db, user)


@router.get("/projects/{project_id}/style-guide.docx")
def style_guide_docx(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    project = get_project(db, project_id)
    sg = db.get(StyleGuide, project_id)
    brand = (sg.brand_name if sg and sg.brand_name else project.client.name) or project.domain
    data = styleguide.build_docx(project.domain, brand, sg.answers if sg else {})
    return file_response(data, f"Stílusbeli irányelvek - {slug(project.domain)}.docx")


# ── Háttérfeladatok ───────────────────────────────────────


def job_payload(job: Job) -> dict[str, Any]:
    return {
        "id": job.id,
        "type": job.type,
        "status": job.status,
        "progress": float(job.progress or 0),
        "message": job.message,
        "result": job.result,
        "error": job.error,
        "created_at": job.created_at.isoformat() if job.created_at else None,
        "finished_at": job.finished_at.isoformat() if job.finished_at else None,
    }


@router.get("/jobs/{job_id}")
def get_job(job_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    job = db.get(Job, job_id)
    if job is None:
        raise HTTPException(404, "A feladat nem található.")
    return job_payload(job)


@router.get("/projects/{project_id}/jobs")
def project_jobs(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("project.view"))):
    rows = db.scalars(select(Job).where(Job.project_id == project_id).order_by(Job.id.desc()).limit(30)).all()
    return [job_payload(j) for j in rows]
