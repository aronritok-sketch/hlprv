"""4. ütem végpontjai: dokumentumok (generálás, lista, szerkesztés, fájlok), referenciadokumentumok,
gyártási feladatok (generálás, lista, státusz, CRM-összekötés), exportok."""

from datetime import date
from typing import Any, Optional
from urllib.parse import unquote

from fastapi import APIRouter, Depends, HTTPException, Request
from pydantic import BaseModel, Field
from sqlalchemy import select
from sqlalchemy.orm import Session

from .. import meta
from ..auth import CurrentUser, need
from ..db import get_db, utcnow
from ..jobs import runner
from ..models import (
    DOC_STATUSES,
    DOC_TYPES,
    TASK_ROLES,
    TASK_STATUSES,
    Document,
    ProductionTask,
    Project,
    ReferenceDoc,
    StoredFile,
    User,
)
from ..permissions import can_update_task, can_view_doc, has
from ..services import docfacts, documents as docs, storage, tasks as task_service
from ..services.activity import changes, log
from ..services.files import file_response
from ..services.projects import get_project
from . import dashboard
from .research import job_payload

router = APIRouter(tags=["documents"])

meta.extend("doc_types", {k: {"label": v[0], "audience": v[1], "formats": docs.FORMATS_BY_TYPE.get(k, [])} for k, v in DOC_TYPES.items()})
meta.extend("doc_statuses", DOC_STATUSES)
meta.extend("task_roles", TASK_ROLES)
meta.extend("task_statuses", TASK_STATUSES)

# Ki milyen dokumentumot generálhat.
WRITER_DOCS = {"writer_brief", "content_strategy", "roadmap", "seo_checklist"}


def can_generate(user: CurrentUser, doc_type: str) -> bool:
    return has(user.role, "documents.generate") or (doc_type in WRITER_DOCS and has(user.role, "documents.generate.writer"))


def doc_payload(d: Document, stale: Optional[bool] = None, full: bool = False) -> dict[str, Any]:
    out = {
        "id": d.id, "project_id": d.project_id, "doc_type": d.doc_type, "type_label": DOC_TYPES[d.doc_type][0], "audience": d.audience,
        "language": d.language, "version": d.version, "status": d.status, "status_label": DOC_STATUSES.get(d.status, d.status),
        "title": d.title, "method": d.method, "model": d.model, "created_at": d.created_at.isoformat() if d.created_at else None,
        "approved_at": d.approved_at.isoformat() if d.approved_at else None, "sent_at": d.sent_at.isoformat() if d.sent_at else None,
        "files": [{"format": f.format, "file_id": f.file_id} for f in d.files], "stale": stale,
    }
    if full:
        out["content"] = d.content
    return out


def get_doc(db: Session, doc_id: int, user: CurrentUser) -> Document:
    d = db.get(Document, doc_id)
    if d is None:
        raise HTTPException(404, "A dokumentum nem található.")
    if not can_view_doc(user.role, d.doc_type):
        raise HTTPException(403, "Ezt a dokumentumot nem láthatod.")
    return d


@router.get("/projects/{project_id}/documents")
def list_documents(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("documents.view"))):
    project = get_project(db, project_id)
    rows = db.scalars(select(Document).where(Document.project_id == project_id).order_by(Document.doc_type, Document.version.desc())).all()
    rows = [d for d in rows if can_view_doc(user.role, d.doc_type)]
    latest: dict[tuple[str, str], Document] = {}
    for d in rows:
        latest.setdefault((d.doc_type, d.language), d)
    # Elavultság: a legfrissebb változatok lenyomatát vetjük össze a mostani adatokkal (egyszeri adatbetöltés).
    stale: dict[int, bool] = {}
    if latest:
        data = docfacts.Data(db, project)
        for (dt, lang), d in latest.items():
            builder = docfacts.BUILDERS.get(dt)
            if builder and d.source_hash:
                try:
                    facts, _ = builder(data, lang)
                    stale[d.id] = docfacts.fingerprint(facts) != d.source_hash
                except Exception:  # noqa: BLE001 – ha az adatok hiányosak, a lista attól még jelenjen meg
                    stale[d.id] = True
    types = []
    for key, (label, audience) in DOC_TYPES.items():
        if not can_view_doc(user.role, key) or key not in docfacts.BUILDERS:
            continue
        types.append({"doc_type": key, "label": label, "audience": audience, "can_generate": can_generate(user, key),
                      "formats": docs.FORMATS_BY_TYPE.get(key, []), "default_language": docs.default_language(project, key),
                      "languages": ["hu"] if audience == "internal" or key in docs.HU_ONLY else ["hu", "en"]})
    return {
        "types": types,
        "documents": [doc_payload(d, stale.get(d.id, False) if latest.get((d.doc_type, d.language)) is d else None) for d in rows],
    }


class GenerateIn(BaseModel):
    doc_type: str
    language: Optional[str] = None
    formats: Optional[list[str]] = None
    period: Optional[str] = Field(default=None, pattern=r"^\d{4}-\d{2}$")  # havi riporthoz: ÉÉÉÉ-HH


@router.post("/projects/{project_id}/documents/generate")
def generate_document(project_id: int, body: GenerateIn, db: Session = Depends(get_db), user: CurrentUser = Depends(need("documents.view"))):
    get_project(db, project_id)
    if body.doc_type not in docfacts.BUILDERS:
        raise HTTPException(422, "Ismeretlen dokumentumtípus.")
    if not can_generate(user, body.doc_type) or not can_view_doc(user.role, body.doc_type):
        raise HTTPException(403, "Ezt a dokumentumot nem generálhatod.")
    if body.language and body.language not in ("hu", "en"):
        raise HTTPException(422, "A nyelv hu vagy en lehet.")
    formats = [f for f in (body.formats or []) if f in ("pdf", "docx", "xlsx")] or None
    job = runner.enqueue(db, "generate_document", project_id, {"doc_type": body.doc_type, "language": body.language, "formats": formats, "period": body.period}, user.id)
    return job_payload(job)


@router.get("/documents/{doc_id}")
def get_document(doc_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("documents.view"))):
    return doc_payload(get_doc(db, doc_id, user), full=True)


class DocPatch(BaseModel):
    status: Optional[str] = None
    title: Optional[str] = None
    content: Optional[dict[str, Any]] = None


@router.patch("/documents/{doc_id}")
def patch_document(doc_id: int, body: DocPatch, db: Session = Depends(get_db), user: CurrentUser = Depends(need("documents.view"))):
    d = get_doc(db, doc_id, user)
    if not can_generate(user, d.doc_type):
        raise HTTPException(403, "Ezt a dokumentumot nem szerkesztheted.")
    data = body.model_dump(exclude_unset=True)
    if "status" in data:
        if data["status"] not in DOC_STATUSES:
            raise HTTPException(422, "Ismeretlen státusz.")
        if data["status"] == "approved" and not has(user.role, "approve.internal"):
            raise HTTPException(403, "Jóváhagyni csak SEO manager vagy admin tud.")
        if data["status"] == "sent" and d.audience == "client" and d.status not in ("approved", "sent"):
            raise HTTPException(409, "Ügyféldokumentum csak jóváhagyás után küldhető ki.")
    if d.status in ("approved", "sent") and "content" in data:
        raise HTTPException(409, "Jóváhagyott dokumentum nem szerkeszthető; generálj új változatot.")
    if "content" in data:
        c = data["content"]
        if not isinstance(c.get("sections"), list):
            raise HTTPException(422, "Hibás tartalom.")
    diff = changes(d, data)
    if "status" in diff:
        if d.status == "approved":
            d.approved_by, d.approved_at = user.id or None, utcnow()
        if d.status == "sent":
            d.sent_at = utcnow()
    project = get_project(db, d.project_id)
    if "content" in diff or "title" in diff:
        if "title" in diff:
            d.content = {**d.content, "title": d.title}
        docs.render_files(db, project, d, [f.format for f in d.files] or docs.FORMATS_BY_TYPE.get(d.doc_type, ["pdf"]), user.id)
    if diff:
        log(db, d.project_id, user.id, "document", d.id, "updated", ", ".join(diff), {k: v for k, v in diff.items() if k != "content"})
    db.commit()
    db.refresh(d)
    return doc_payload(d, full=True)


@router.delete("/documents/{doc_id}", status_code=204)
def delete_document(doc_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("documents.generate"))):
    d = get_doc(db, doc_id, user)
    if d.status == "sent" and user.role != "admin":
        raise HTTPException(409, "Kiküldött dokumentumot csak admin törölhet.")
    file_ids = [f.file_id for f in d.files]
    log(db, d.project_id, user.id, "document", d.id, "deleted", d.title)
    db.delete(d)
    db.flush()
    for fid in file_ids:
        stored = db.get(StoredFile, fid)
        if stored:
            storage.delete(stored)
            db.delete(stored)
    db.commit()


@router.get("/documents/{doc_id}/files/{fmt}")
def download_document(doc_id: int, fmt: str, db: Session = Depends(get_db), user: CurrentUser = Depends(need("documents.view"))):
    d = get_doc(db, doc_id, user)
    f = next((x for x in d.files if x.format == fmt), None)
    if f is None:
        raise HTTPException(404, "Ebben a formátumban nincs fájl.")
    stored = db.get(StoredFile, f.file_id)
    return file_response(storage.read(stored), stored.filename, stored.mime)


@router.get("/documents/{doc_id}/preview")
def preview_document(doc_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("documents.view"))):
    """A PDF HTML-forrása – a felület ezt mutatja előnézetként (iframe srcdoc)."""
    d = get_doc(db, doc_id, user)
    from ..services import render

    return {"html": render.html(d.content, docs.doc_style(db, d.language), d.version)}


# ── Referenciadokumentumok ───────────────────────────────


def ref_payload(r: ReferenceDoc) -> dict[str, Any]:
    return {"id": r.id, "doc_type": r.doc_type, "type_label": DOC_TYPES.get(r.doc_type, ("Általános",))[0], "language": r.language, "title": r.title,
            "is_active": r.is_active, "chars": len(r.extracted_text or ""), "created_at": r.created_at.isoformat() if r.created_at else None}


@router.get("/reference-docs")
def list_refs(db: Session = Depends(get_db), user: CurrentUser = Depends(need("documents.generate"))):
    return [ref_payload(r) for r in db.scalars(select(ReferenceDoc).order_by(ReferenceDoc.doc_type, ReferenceDoc.id.desc())).all()]


@router.post("/reference-docs", status_code=201)
async def upload_ref(request: Request, doc_type: str = "general", language: str = "hu", db: Session = Depends(get_db),
                     user: CurrentUser = Depends(need("settings.manage"))):
    if doc_type != "general" and doc_type not in DOC_TYPES:
        raise HTTPException(422, "Ismeretlen dokumentumtípus.")
    name = unquote(request.headers.get("x-filename", "")) or "minta.docx"
    data = await request.body()
    if not data:
        raise HTTPException(422, "Üres fájl.")
    try:
        text = docs.extract_text(data, name)
    except ValueError as e:
        raise HTTPException(422, str(e))
    if not text.strip():
        raise HTTPException(422, "A fájlból nem sikerült szöveget kinyerni.")
    f = storage.save(db, data, name, "reference", None, user.id, request.headers.get("content-type", ""))
    r = ReferenceDoc(doc_type=doc_type, language=language if language in ("hu", "en") else "hu", title=name.rsplit(".", 1)[0][:255], file_id=f.id, extracted_text=text)
    db.add(r)
    db.commit()
    return ref_payload(r)


class RefPatch(BaseModel):
    doc_type: Optional[str] = None
    language: Optional[str] = None
    title: Optional[str] = None
    is_active: Optional[bool] = None


@router.patch("/reference-docs/{ref_id}")
def patch_ref(ref_id: int, body: RefPatch, db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))):
    r = db.get(ReferenceDoc, ref_id)
    if r is None:
        raise HTTPException(404, "Nem található.")
    data = body.model_dump(exclude_unset=True)
    if "doc_type" in data and data["doc_type"] != "general" and data["doc_type"] not in DOC_TYPES:
        raise HTTPException(422, "Ismeretlen dokumentumtípus.")
    changes(r, data)
    db.commit()
    return ref_payload(r)


@router.delete("/reference-docs/{ref_id}", status_code=204)
def delete_ref(ref_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("settings.manage"))):
    r = db.get(ReferenceDoc, ref_id)
    if r is None:
        raise HTTPException(404, "Nem található.")
    if r.file_id:
        stored = db.get(StoredFile, r.file_id)
        if stored:
            storage.delete(stored)
            db.delete(stored)
    db.delete(r)
    db.commit()


# ── Gyártási feladatok ───────────────────────────────────


def task_payload(t: ProductionTask, users: dict[int, User], project_name: str = "") -> dict[str, Any]:
    u = users.get(t.assignee_id) if t.assignee_id else None
    return {
        "id": t.id, "project_id": t.project_id, "project": project_name, "page_id": t.page_id, "roadmap_item_id": t.roadmap_item_id,
        "role": t.role, "role_label": TASK_ROLES.get(t.role, t.role), "priority": t.priority, "title": t.title,
        "source_url": t.source_url, "action": t.action, "target_url": t.target_url, "done_when": t.done_when, "notes": t.notes,
        "status": t.status, "status_label": TASK_STATUSES.get(t.status, t.status),
        "assignee_id": t.assignee_id, "assignee": u.display_name if u else "", "assignee_wp_id": u.wp_user_id if u else None,
        "due_date": t.due_date.isoformat() if t.due_date else None, "crm_task_id": t.crm_task_id,
    }


def _users(db: Session) -> dict[int, User]:
    return {u.id: u for u in db.scalars(select(User)).all()}


@router.post("/projects/{project_id}/tasks/generate")
def generate_tasks(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("tasks.edit"))):
    project = get_project(db, project_id)
    result = task_service.generate(db, project)
    log(db, project.id, user.id, "tasks", None, "generated", f"{result['created']} új, {result['updated']} frissítve, {result['removed']} törölve")
    db.commit()
    return result


@router.get("/projects/{project_id}/tasks")
def list_tasks(project_id: int, role: Optional[str] = None, status: Optional[str] = None, unpushed: bool = False,
               db: Session = Depends(get_db), user: CurrentUser = Depends(need("tasks.view"))):
    project = get_project(db, project_id)
    q = select(ProductionTask).where(ProductionTask.project_id == project_id)
    if role:
        q = q.where(ProductionTask.role == role)
    if status:
        q = q.where(ProductionTask.status == status)
    if unpushed:
        q = q.where(ProductionTask.crm_task_id.is_(None), ProductionTask.status != "done")
    rows = db.scalars(q.order_by(ProductionTask.due_date.nulls_first(), ProductionTask.priority, ProductionTask.role, ProductionTask.id)).all()
    users = _users(db)
    return [task_payload(t, users, project.name) for t in rows]


class TaskPatch(BaseModel):
    status: Optional[str] = None
    assignee_id: Optional[int] = None
    due_date: Optional[date] = None
    priority: Optional[str] = None
    notes: Optional[str] = None
    title: Optional[str] = None
    action: Optional[str] = None
    done_when: Optional[str] = None


@router.patch("/tasks/{task_id}")
def patch_task(task_id: int, body: TaskPatch, db: Session = Depends(get_db), user: CurrentUser = Depends(need("tasks.update"))):
    t = db.get(ProductionTask, task_id)
    if t is None:
        raise HTTPException(404, "A feladat nem található.")
    data = body.model_dump(exclude_unset=True)
    if not has(user.role, "tasks.edit"):
        # A gyártó kolléga csak a saját szerepkörének feladatain a státuszt és a megjegyzést módosíthatja.
        if not can_update_task(user.role, t.role):
            raise HTTPException(403, "Ezt a feladatot nem módosíthatod.")
        data = {k: v for k, v in data.items() if k in ("status", "notes")}
    if "status" in data and data["status"] not in TASK_STATUSES:
        raise HTTPException(422, "Ismeretlen státusz.")
    if "assignee_id" in data and data["assignee_id"] and db.get(User, data["assignee_id"]) is None:
        raise HTTPException(422, "Ismeretlen felhasználó.")
    diff = changes(t, data)
    if diff:
        log(db, t.project_id, user.id, "task", t.id, "updated", t.title, diff)
    if "assignee_id" in diff and t.assignee_id:
        from ..services import collab

        collab.notify(db, [t.assignee_id], "task_assigned", f"Új feladat: {t.title}", body=t.done_when and "Akkor kész, ha: " + t.done_when,
                      link=f"#/projects/{t.project_id}?tab=documents&sub=tasks", project_id=t.project_id, exclude=user.id)
    db.commit()
    return task_payload(t, _users(db))


class CrmLink(BaseModel):
    id: int
    crm_task_id: int


@router.post("/projects/{project_id}/tasks/crm-links")
def crm_links(project_id: int, body: list[CrmLink], db: Session = Depends(get_db), user: CurrentUser = Depends(need("tasks.edit"))):
    get_project(db, project_id)
    n = 0
    for link in body:
        t = db.get(ProductionTask, link.id)
        if t and t.project_id == project_id:
            t.crm_task_id = link.crm_task_id
            n += 1
    db.commit()
    return {"linked": n}


@router.get("/me/tasks")
def my_tasks(db: Session = Depends(get_db), user: CurrentUser = Depends(need("tasks.view"))):
    """A „Saját munkám” nézet: a hozzám rendelt, és a szerepkörömhöz tartozó, gazdátlan nyitott feladatok."""
    rows = db.execute(
        select(ProductionTask, Project.name).join(Project, Project.id == ProductionTask.project_id)
        .where(ProductionTask.status != "done", Project.archived_at.is_(None))
        .order_by(ProductionTask.due_date.nulls_last(), ProductionTask.priority, ProductionTask.id)
    ).all()
    users = _users(db)
    mine, open_for_role = [], []
    for t, name in rows:
        if t.assignee_id == user.id:
            mine.append(task_payload(t, users, name))
        elif t.assignee_id is None and can_update_task(user.role, t.role) and user.role not in ("admin", "seo_manager"):
            open_for_role.append(task_payload(t, users, name))
    return {"mine": mine, "open": open_for_role[:200]}


@router.get("/projects/{project_id}/exports/content-strategy.xlsx")
def export_content_strategy(project_id: int, db: Session = Depends(get_db), user: CurrentUser = Depends(need("strategy.view"))):
    project = get_project(db, project_id)
    facts, content, d = docfacts.build(db, project, "content_strategy", docs.default_language(project, "content_strategy"))
    if not d.roadmap:
        raise HTTPException(409, "Még nincs roadmap.")
    narrative = docs.template_narrative("content_strategy", docs.default_language(project, "content_strategy"), facts, content)
    from ..models import PaidPlanItem
    from ..services import render

    items = {r.id: r for r in d.roadmap}
    paid = db.scalars(select(PaidPlanItem).where(PaidPlanItem.project_id == project.id)).all()
    rows = [[items[p.roadmap_item_id].month.strftime("%Y.%m.") if p.roadmap_item_id in items else "", items[p.roadmap_item_id].title if p.roadmap_item_id in items else "",
             p.seo_role, p.meta_creative, p.paid_role, p.search_target, p.remarketing_next, p.kpi] for p in paid]
    data = render.content_strategy_xlsx(docs.merge_narrative(content, narrative), {"months": project.strategy_months, "paid": rows})
    return file_response(data, f"{project.domain} tartalomstratégia.xlsx", render.FORMATS["xlsx"]["mime"])


# ── Vezérlőpult ──────────────────────────────────────────


@dashboard.widget
def tasks_widget(db: Session, user: CurrentUser) -> dict:
    rows = db.execute(
        select(ProductionTask, Project.name).join(Project, Project.id == ProductionTask.project_id)
        .where(ProductionTask.assignee_id == user.id, ProductionTask.status != "done", Project.archived_at.is_(None))
        .order_by(ProductionTask.due_date.nulls_last(), ProductionTask.priority).limit(12)
    ).all()
    users = _users(db) if rows else {}
    return {"my_tasks": [task_payload(t, users, name) for t, name in rows]}


@dashboard.widget
def documents_widget(db: Session, user: CurrentUser) -> dict:
    if not has(user.role, "documents.view"):
        return {}
    rows = db.execute(
        select(Document, Project.name).join(Project, Project.id == Document.project_id)
        .where(Project.archived_at.is_(None)).order_by(Document.created_at.desc()).limit(40)
    ).all()
    rows = [(d, name) for d, name in rows if can_view_doc(user.role, d.doc_type)][:8]
    return {"documents": [{**doc_payload(d), "project": name} for d, name in rows]}
