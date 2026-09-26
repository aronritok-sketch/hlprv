"""Technikai audit: Screaming Frog exportok feldolgozása, helyszíni ellenőrzések, fejlesztői feladatok, XLSX export.

Bemenet: a crawl futás fájljai (az ügynök ZIP-je, vagy kézzel feltöltött CSV / XLSX / ZIP). Minden táblát a fájl- vagy
munkalapneve alapján sorolunk megállapításhoz (audit_rules). Ha van „Internal – All” export, abból összesítünk, és a
legfontosabb hibákat akkor is kiszámoljuk, ha a külön szűrt export hiányzik.
"""

import io
import re
import zipfile
from collections import defaultdict
from pathlib import Path
from typing import Any, Iterable, Optional
from urllib.parse import urlsplit

import httpx
from openpyxl import Workbook
from sqlalchemy import select
from sqlalchemy.orm import Session

from ..db import utcnow
from ..jobs.runner import JobError, Progress, handler
from ..models import AuditFinding, AuditTopic, CrawlRun, Job, ProductionTask, Project, StoredFile
from . import audit_rules as R
from . import importers, storage, xlsx
from .activity import log

MAX_URLS = 2000
_transport: Optional[httpx.BaseTransport] = None  # tesztekhez


# ── Táblák olvasása ──────────────────────────────────────


def _tables(name: str, data: bytes) -> Iterable[tuple[str, str, list[list[Any]]]]:
    """(fájlnév, munkalapnév, sorok) – ZIP-en belül is."""
    lower = name.lower()
    if lower.endswith(".zip"):
        with zipfile.ZipFile(io.BytesIO(data)) as z:
            for info in z.infolist():
                if info.is_dir() or info.file_size > 200 * 1024 * 1024:
                    continue
                inner = Path(info.filename).name
                if inner.lower().endswith((".csv", ".xlsx", ".tsv")) and not inner.startswith("."):
                    yield from _tables(inner, z.read(info))
        return
    tmp = storage.root() / "tmp"
    tmp.mkdir(parents=True, exist_ok=True)
    path = tmp / f"audit-{abs(hash((name, len(data))))}{Path(lower).suffix}"
    path.write_bytes(data)
    try:
        for sheet in importers.read_table(path, name):
            yield name, sheet.name, sheet.rows
    finally:
        path.unlink(missing_ok=True)


def _records(rows: list[list[Any]]) -> tuple[list[str], list[dict[str, Any]]]:
    header_idx = None
    for i, r in enumerate(rows[:6]):
        cells = [str(c or "").strip() for c in r]
        if "Address" in cells or ("From" in cells and "To" in cells):
            header_idx = i
            break
    if header_idx is None:
        return [], []
    headers = [str(c or "").strip() for c in rows[header_idx]]
    out = []
    for r in rows[header_idx + 1:]:
        if not any(c not in (None, "") for c in r):
            continue
        out.append({headers[i]: r[i] for i in range(min(len(headers), len(r))) if headers[i]})
    return headers, out


def _num(v) -> Optional[float]:
    try:
        return float(v)
    except (TypeError, ValueError):
        return None


def _detail(rec: dict, cols: list[str]) -> str:
    parts = []
    for c in cols:
        v = rec.get(c)
        if v not in (None, ""):
            if isinstance(v, float) and v.is_integer():
                v = int(v)
            parts.append(f"{c}: {v}")
    return " · ".join(parts)[:300]


class Collector:
    def __init__(self):
        self.urls: dict[str, dict[str, dict]] = defaultdict(dict)
        self.covered: set[str] = set()
        self.stats: dict[str, Any] = {}
        self.files: list[dict] = []

    def add(self, key: str, url: str, detail: str = "", source: str = "") -> None:
        if url and url not in self.urls[key]:
            self.urls[key][url] = {"url": url, "detail": detail, "from": source}

    def table(self, filename: str, sheet: str, rows: list[list[Any]]) -> dict:
        key, kind = R.match(filename, sheet)
        headers, recs = _records(rows)
        info = {"name": filename, "sheet": sheet, "issue_key": key, "rows": len(recs)}
        if kind == "internal_all":
            self.internal_all(recs)
        elif kind == "issue":
            rule = R.RULES_BY_KEY[key]
            self.covered.add(key)
            inlinks = "Address" not in headers and "To" in headers
            for rec in recs:
                if inlinks:
                    if key.startswith("images") and rec.get("Type") not in (None, "", "Image"):
                        continue
                    self.add(key, str(rec.get("To") or ""), _detail(rec, rule.detail + ["Alt Text"]), str(rec.get("From") or ""))
                else:
                    self.add(key, str(rec.get("Address") or ""), _detail(rec, rule.detail))
                if key == "status_3xx" and _num(rec.get("Status Code")) == 302:
                    self.covered.add("status_302")
                    self.add("status_302", str(rec.get("Address") or ""), _detail(rec, ["Redirect URL"]))
        return info

    def internal_all(self, recs: list[dict]) -> None:
        html = [r for r in recs if "html" in str(r.get("Content Type") or r.get("Content") or "").lower()]
        indexable = [r for r in html if str(r.get("Indexability") or "").lower() == "indexable"]
        self.stats.update({"urls": len(recs), "html": len(html), "indexable": len(indexable)})
        derived = defaultdict(list)
        for r in recs:
            code = _num(r.get("Status Code"))
            addr = str(r.get("Address") or "")
            if code is None:
                continue
            if 400 <= code < 500:
                derived["status_4xx"].append((addr, f"Status Code: {int(code)}"))
            elif code >= 500:
                derived["status_5xx"].append((addr, f"Status Code: {int(code)}"))
            elif 300 <= code < 400:
                derived["status_3xx"].append((addr, f"Status Code: {int(code)} → {r.get('Redirect URL') or ''}"))
                if code == 302:
                    derived["status_302"].append((addr, f"→ {r.get('Redirect URL') or ''}"))
        seen = defaultdict(lambda: defaultdict(list))
        for r in indexable:
            addr = str(r.get("Address") or "")
            for fld, miss_key, dup_key in (("Title 1", "title_missing", "title_duplicate"), ("Meta Description 1", "meta_missing", "meta_duplicate"), ("H1-1", "h1_missing", "h1_duplicate")):
                v = str(r.get(fld) or "").strip()
                if fld in r or fld == "Title 1":
                    if not v:
                        derived[miss_key].append((addr, ""))
                    else:
                        seen[dup_key][v.lower()].append(addr)
            if "Canonical Link Element 1" in r and not str(r.get("Canonical Link Element 1") or "").strip():
                derived["canonical_missing"].append((addr, ""))
            path = urlsplit(addr).path
            if re.search(r"[A-Z]", path):
                derived["url_uppercase"].append((addr, ""))
            if "_" in path:
                derived["url_underscores"].append((addr, ""))
            if re.search(r"[^\x00-\x7f]|%[0-9a-fA-F]{2}", path):
                derived["url_non_ascii"].append((addr, ""))
        for dup_key, groups in seen.items():
            for value, addrs in groups.items():
                if len(addrs) > 1:
                    for a in addrs:
                        derived[dup_key].append((a, value[:120]))
        for key, items in derived.items():
            if key in self.covered and key not in ("status_302",):
                continue  # a külön szűrt export pontosabb
            self.covered.add(key)
            for addr, detail in items:
                self.add(key, addr, detail)
        # Ami az Internal – All alapján ellenőrizhető és nincs benne, az 0 – vagyis javítva.
        for key in ("status_4xx", "status_5xx", "status_3xx", "status_302", "title_missing", "title_duplicate", "url_uppercase", "url_underscores", "url_non_ascii"):
            self.covered.add(key)


def collect(db: Session, run: CrawlRun, progress: Optional[Progress] = None) -> Collector:
    c = Collector()
    tables = []
    for i, f in enumerate(run.files):
        stored = db.get(StoredFile, f["file_id"])
        if stored is None:
            continue
        tables.extend(_tables(stored.filename, storage.read(stored)))
        if progress:
            progress(0.1 + 0.5 * (i + 1) / max(1, len(run.files)), f"{i + 1} / {len(run.files)} fájl beolvasva")
    # Előbb a szűrt exportok (pontosabbak), végül az Internal – All, ami csak a hiányzókat pótolja.
    tables.sort(key=lambda t: R.match(t[0], t[1])[1] == "internal_all")
    for name, sheet, rows in tables:
        c.files.append(c.table(name, sheet, rows))
    return c


def recognize(name: str, data: bytes) -> list[dict]:
    """Feltöltéskor: mit ismertünk fel a fájlban (a felület ezt mutatja)."""
    out = []
    for fname, sheet, rows in _tables(name, data):
        key, kind = R.match(fname, sheet)
        _, recs = _records(rows)
        out.append({"name": fname, "sheet": sheet, "issue_key": key,
                    "label": "Internal – All (összesítés)" if kind == "internal_all" else (R.label_for(key, len(recs)) if key else "Nem ismert export – kihagyjuk"),
                    "rows": len(recs)})
    return out


# ── Megállapítások mentése ───────────────────────────────


def ensure_topics(db: Session, project_id: int) -> dict[str, AuditTopic]:
    have = {t.topic: t for t in db.scalars(select(AuditTopic).where(AuditTopic.project_id == project_id)).all()}
    for key, t in R.TOPICS.items():
        if key not in have:
            have[key] = AuditTopic(project_id=project_id, topic=key, size=t["size"])
            db.add(have[key])
    db.flush()
    return have


def save_findings(db: Session, project_id: int, run_id: Optional[int], found: dict[str, list[dict]], covered: set[str]) -> dict[str, int]:
    existing = {f.issue_key: f for f in db.scalars(select(AuditFinding).where(AuditFinding.project_id == project_id)).all()}
    counts = {}
    for key in covered | set(found):
        urls = found.get(key, [])
        n = len(urls)
        f = existing.get(key)
        if f is None:
            if not n:
                continue
            f = AuditFinding(project_id=project_id, issue_key=key, topic=R.topic_for(key))
            db.add(f)
        else:
            f.previous_count = f.count
        f.run_id, f.count, f.urls = run_id, n, urls[:MAX_URLS]
        if n == 0 and f.status in ("open", "in_progress"):
            f.status = "fixed"
        elif n > 0 and f.status == "fixed":
            f.status = "open"
        counts[key] = n
    db.flush()
    return counts


@handler("import_crawl")
def job_import_crawl(db: Session, job: Job, progress: Progress):
    run = db.get(CrawlRun, job.payload["run_id"])
    if run is None:
        raise JobError("A crawl nem található.")
    if not run.files:
        raise JobError("Nincs feltöltött export.")
    progress(0.05, "Exportok beolvasása…")
    try:
        c = collect(db, run, progress)
    except (zipfile.BadZipFile, ValueError) as e:
        raise JobError(f"Az export nem olvasható: {e}")
    if not c.urls and not c.stats:
        raise JobError("Egyik fájlt sem ismertük fel Screaming Frog exportként (Address oszlop / „Fül – Szűrő” fájlnév).")
    ensure_topics(db, run.project_id)
    counts = save_findings(db, run.project_id, run.id, {k: list(v.values()) for k, v in c.urls.items()}, c.covered)
    run.stats = {**c.stats, "findings": {k: v for k, v in counts.items() if v}, "files": c.files}
    run.status, run.finished_at, run.error = "done", utcnow(), ""
    run.message = f"{sum(1 for v in counts.values() if v)} megállapítás"
    log(db, run.project_id, job.created_by, "crawl", run.id, "imported", run.message)
    if run.source == "agent" and run.created_by:
        from .collab import notify

        notify(db, [run.created_by], "crawl", f"A Screaming Frog crawl elkészült ({run.message})", body=run.start_url,
               link=f"#/projects/{run.project_id}?tab=audit", project_id=run.project_id)
    return {"run_id": run.id, "findings": len([v for v in counts.values() if v]), "urls": c.stats.get("urls")}


# ── Helyszíni ellenőrzések ───────────────────────────────


def _client() -> httpx.Client:
    return httpx.Client(timeout=10, follow_redirects=False, transport=_transport,
                        headers={"User-Agent": "HelloProVision SEO OS audit (+https://helloprovision.com)"})


def site_checks(db: Session, project: Project) -> dict[str, Any]:
    domain = project.domain
    found: dict[str, list[dict]] = {}
    results = {}
    with _client() as c:
        def get(url):
            try:
                return c.get(url)
            except httpx.HTTPError:
                return None

        r = get(f"http://{domain}/")
        forced = r is not None and r.status_code in (301, 308) and r.headers.get("location", "").startswith("https://")
        results["https_redirect"] = forced
        if not forced:
            found["https_not_forced"] = [{"url": f"http://{domain}/", "detail": f"Válasz: {r.status_code if r is not None else 'nem elérhető'}", "from": ""}]
        sec = get(f"https://{domain}/.well-known/security.txt") or None
        has_sec = sec is not None and sec.status_code == 200 and "contact" in sec.text.lower()
        results["security_txt"] = has_sec
        if not has_sec:
            found["security_txt_missing"] = [{"url": f"https://{domain}/.well-known/security.txt", "detail": "", "from": ""}]
        rob = get(f"https://{domain}/robots.txt")
        has_robots = rob is not None and rob.status_code == 200
        results["robots_txt"] = has_robots
        sitemaps = re.findall(r"(?im)^\s*sitemap:\s*(\S+)", rob.text) if has_robots else []
        if not has_robots:
            found["robots_missing"] = [{"url": f"https://{domain}/robots.txt", "detail": "", "from": ""}]
        has_sitemap = False
        for u in (sitemaps or [f"https://{domain}/sitemap.xml", f"https://{domain}/sitemap_index.xml", f"https://{domain}/wp-sitemap.xml"]):
            s = get(u)
            if s is not None and s.status_code == 200 and "<" in s.text[:200]:
                has_sitemap = True
                results["sitemap_url"] = u
                break
        results["sitemap"] = has_sitemap
        if not has_sitemap:
            found["sitemap_missing"] = [{"url": f"https://{domain}/sitemap.xml", "detail": "", "from": ""}]
    ensure_topics(db, project.id)
    save_findings(db, project.id, None, found, {"https_not_forced", "security_txt_missing", "robots_missing", "sitemap_missing"})
    return results


# ── Áttekintés, feladatok, export ────────────────────────


def overview(db: Session, project_id: int) -> dict[str, Any]:
    topics = ensure_topics(db, project_id)
    findings = db.scalars(select(AuditFinding).where(AuditFinding.project_id == project_id)).all()
    by_topic = defaultdict(list)
    for f in findings:
        by_topic[f.topic].append(f)
    out = []
    for key in R.TOPIC_ORDER:
        t, meta = topics[key], R.TOPICS[key]
        fs = sorted(by_topic.get(key, []), key=lambda f: (f.count == 0, -f.count))
        out.append({
            "topic": key, "title": meta["title"], "size": t.size, "default_size": meta["size"], "included": t.included,
            "observation": t.observation, "recommendation": t.recommendation, "default_recommendation": meta["recommendation"],
            "metrics": t.metrics or {}, "intro": meta["intro"], "why": meta["why"], "ideal": meta["ideal"],
            "findings": [finding_payload(f) for f in fs],
            "open": sum(1 for f in fs if f.count and f.status in ("open", "in_progress")),
        })
    return {"topics": out}


def finding_payload(f: AuditFinding, full: bool = False) -> dict[str, Any]:
    return {
        "id": f.id, "topic": f.topic, "issue_key": f.issue_key, "label": R.label_for(f.issue_key, f.count), "count": f.count,
        "previous_count": f.previous_count, "status": f.status, "notes": f.notes, "task_id": f.task_id,
        "sample": f.urls[:5], **({"urls": f.urls} if full else {}),
        "updated_at": f.updated_at.isoformat() if f.updated_at else None,
    }


def generate_tasks(db: Session, project: Project) -> dict[str, int]:
    topics = ensure_topics(db, project.id)
    findings = db.scalars(select(AuditFinding).where(AuditFinding.project_id == project.id, AuditFinding.count > 0,
                                                     AuditFinding.status.in_(["open", "in_progress"]))).all()
    existing = {t.key: t for t in db.scalars(select(ProductionTask).where(ProductionTask.project_id == project.id, ProductionTask.key.like("audit:%"))).all()}
    created = updated = 0
    for f in findings:
        topic = topics.get(f.topic)
        if topic is not None and not topic.included:
            continue
        meta = R.TOPICS[f.topic]
        key = f"audit:{f.issue_key}"
        urls = "\n".join(u["url"] + (f"  ({u['detail']})" if u.get("detail") else "") for u in f.urls[:30])
        more = f"\n… és további {f.count - 30} URL (lásd az audit XLSX exportot)." if f.count > 30 else ""
        data = {
            "role": "developer", "priority": topic.size if topic else meta["size"], "title": f"[Audit] {R.label_for(f.issue_key, f.count)}",
            "action": (topic.recommendation if topic and topic.recommendation else meta["recommendation"]) + "\n\nÉrintett URL-ek:\n" + urls + more,
            "done_when": meta["done_when"], "key": key,
        }
        t = existing.get(key)
        if t is None:
            t = ProductionTask(project_id=project.id, **data)
            db.add(t)
            created += 1
        elif t.status == "todo" and not t.crm_task_id:
            for k, v in data.items():
                setattr(t, k, v)
            updated += 1
        db.flush()
        f.task_id = t.id
    return {"created": created, "updated": updated}


def export_xlsx(db: Session, project: Project) -> bytes:
    ov = overview(db, project.id)
    wb = Workbook()
    ws = wb.active
    ws.title = "Összefoglaló"
    rows = []
    for t in ov["topics"]:
        for f in t["findings"]:
            rows.append([t["size"], t["title"], f["label"], f["count"], f["previous_count"], {"open": "Nyitott", "in_progress": "Javítás alatt", "fixed": "Javítva", "wontfix": "Nem javítjuk"}[f["status"]]])
    xlsx.write_table(ws, 1, ["Méret", "Téma", "Megállapítás", "Darab", "Előző futás", "Állapot"], rows, widths=[8, 26, 70, 10, 12, 16], wrap_cols=[3])
    used = {"Összefoglaló"}
    for f in db.scalars(select(AuditFinding).where(AuditFinding.project_id == project.id, AuditFinding.count > 0).order_by(AuditFinding.topic)).all():
        name = f.issue_key[:31]
        if name in used:
            continue
        used.add(name)
        sh = wb.create_sheet(name)
        sh["A1"] = R.label_for(f.issue_key, f.count)
        xlsx.write_table(sh, 3, ["URL", "Részlet", "Forrás oldal"], [[u["url"], u.get("detail", ""), u.get("from", "")] for u in f.urls], widths=[80, 60, 60])
    return xlsx.to_bytes(wb)
