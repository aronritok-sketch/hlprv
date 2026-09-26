"""3b. ütem: oldalstruktúra, kulcsszó → URL hozzárendelés, belső linkek, tartalmi roadmap, mérési és paid terv,
félretett témák, wireframe-ek.

A váz mindig a módszertan oldalmodelljeiből, determinisztikusan készül (főoldal, regionális szolgáltatási oldalak,
városi hubok, városi szolgáltatási landingek, blog hub, támogató oldalak). Az AI – ha van kulcs – a címeket, H1-eket,
célokat és a kulcsszóválasztást finomítja, de csak a meglévő kulcsszavak közül választhat, és az egyedi elsődleges
kulcsszó szabályát a kód és az adatbázis is betartatja.
"""

import json
import re
import unicodedata
from collections import defaultdict
from datetime import date
from typing import Any, Optional

from sqlalchemy import delete, select
from sqlalchemy.orm import Session, selectinload

from ..jobs.runner import JobError, Progress, handler
from ..models import (
    Cluster,
    InternalLink,
    Job,
    Keyword,
    KeywordAnalysis,
    MeasurementItem,
    Page,
    PageKeyword,
    PaidPlanItem,
    ParkedTopic,
    Project,
    RoadmapItem,
    Wireframe,
)
from . import heuristics, llm, prompts
from . import wireframe_templates as wt
from .activity import log
from .projects import add_months
from .research import best_metric

COMMERCIAL = ("commercial", "transactional", "commercial_investigation")

L10N = {
    "hu": {"blog": "/tudastar/", "blog_title": "Tudástár", "about": "/rolunk/", "about_title": "Rólunk", "contact": "/kapcsolat/",
           "contact_title": "Kapcsolat", "work": "/referenciak/", "work_title": "Referenciák", "cta": "Ajánlatot kérek",
           "explore": "Tovább: {x}", "case_title": "[Ügyfél/Projekt]: [Konkrét eredmény]", "case_cta": "Hasonló projektet szeretnék",
           "hub_h1": "{industry} – {city}", "city_h1": "{service} – {city}", "service_h1": "{service}"},
    "en": {"blog": "/insights/", "blog_title": "Insights", "about": "/about/", "about_title": "About", "contact": "/contact/",
           "contact_title": "Contact", "work": "/work/", "work_title": "Work", "cta": "Start a Project",
           "explore": "Explore {x}", "case_title": "[Client/Project]: [Specific Outcome]", "case_cta": "Start a Similar Project",
           "hub_h1": "{industry} for {city} Businesses", "city_h1": "{service} in {city}", "service_h1": "{service}"},
}


def lang(project: Project) -> dict:
    return L10N["hu" if (project.content_language or "").startswith("hu") else "en"]


def slugify(text: str) -> str:
    s = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode().lower()
    s = re.sub(r"[^a-z0-9]+", "-", s).strip("-")
    return s[:80] or "oldal"


def title_case(s: str) -> str:
    small = {"and", "or", "for", "of", "in", "the", "a", "an", "to", "és", "a", "az"}
    words = s.split()
    return " ".join(w if (i and w in small) else (w[:1].upper() + w[1:]) for i, w in enumerate(words))


def stems(s: str) -> set[str]:
    return {heuristics.stem(w) for w in heuristics.tokens(s)}


def matches_service(term: str, service: str) -> bool:
    st = stems(service)
    return bool(st) and st <= stems(term)


# ── Adatok betöltése ─────────────────────────────────────


def analysed(db: Session, project_id: int) -> list[tuple[KeywordAnalysis, Keyword]]:
    rows = db.execute(
        select(KeywordAnalysis, Keyword).join(Keyword, Keyword.id == KeywordAnalysis.keyword_id)
        .where(KeywordAnalysis.project_id == project_id, Keyword.is_excluded.is_(False))
        .options(selectinload(Keyword.metrics))
    ).all()
    return [(a, k) for a, k in rows]


def rank_key(a: KeywordAnalysis, k: Keyword):
    return (heuristics.INTENT_BASE.get(a.intent, 0.5), float(a.priority_score or 0), best_metric(k)["volume"] or 0)


# ── Oldalstruktúra ───────────────────────────────────────


def skeleton(project: Project, rows: list[tuple[KeywordAnalysis, Keyword]]) -> list[dict]:
    L = lang(project)
    services = [s.get("name", "").strip() for s in project.business_services or [] if s.get("name", "").strip()]
    if not services:
        # Szolgáltatáslista nélkül a legerősebb kereskedelmi klaszterek adják a szolgáltatási oldalakat.
        by_cluster = defaultdict(float)
        for a, k in rows:
            if a.intent in COMMERCIAL and a.cluster:
                by_cluster[a.cluster.name] += float(a.priority_score or 0)
        services = [c for c, _ in sorted(by_cluster.items(), key=lambda x: -x[1])[:6]]
    # Csak azok a városok kapnak oldalt, ahol a kutatás helyi keresést mutat.
    local_locs = {k.term_location for a, k in rows if k.term_location and a.intent in COMMERCIAL}
    locations = [loc for loc in project.locations or [] if loc in local_locs]
    industry = project.industry or (services[0] if services else "Services")
    brand = project.client.name
    pages: list[dict] = [{"key": "home", "page_type": "home", "url": "/", "title": "Főoldal", "sort": 0,
                          "seo_goal": "A márka és a teljes ajánlat bemutatása; útvonal a szolgáltatási és városi oldalak felé. Nem céloz egyetlen szolgáltatási vagy városi kulcsszót sem."}]
    multi_city = len(locations) >= 1 and len(services) >= 2
    for i, svc in enumerate(services):
        pages.append({
            "key": f"svc:{svc}", "page_type": "service_hub" if locations else "service", "url": f"/{slugify(svc)}/",
            "service": svc, "parent": "home", "title": svc, "sort": 10 + i,
            "h1": L["service_h1"].format(service=svc),
            "seo_goal": f"A(z) {svc} szolgáltatás regionális / általános keresési szándékának lefedése; városválasztó a helyi oldalakra.",
        })
    for j, loc in enumerate(locations):
        hub_key = None
        # Városi hub csak akkor, ha van rá keresés: helyi kereskedelmi kulcsszó, ami nem egy konkrét szolgáltatásé
        # (pl. „fort myers digital marketing”). Ha nincs, a városi landingek a regionális szolgáltatási oldal alá kerülnek.
        hub_evidence = any(
            k.term_location == loc and a.intent in COMMERCIAL and not any(matches_service(k.term, s) for s in services)
            for a, k in rows
        )
        if multi_city and hub_evidence:
            hub_key = f"hub:{loc}"
            # Ha az iparág neve egy szolgáltatásé is, a hub csak a város nevét kapja (ne ütközzön a városi landinggel).
            hub_slug = slugify(loc) if slugify(industry) in {slugify(s) for s in services} else f"{slugify(loc)}-{slugify(industry)}"
            pages.append({
                "key": hub_key, "page_type": "city_hub", "url": f"/{hub_slug}/", "location": loc,
                "parent": "home", "title": f"{loc} – {industry}", "sort": 100 + j * 10,
                "h1": L["hub_h1"].format(industry=industry, city=loc),
                "seo_goal": f"Városi hub: a(z) {loc} látogatót a helyi szolgáltatási oldalak felé irányítja. Nem szolgáltatási oldal.",
            })
        for i, svc in enumerate(services):
            pages.append({
                "key": f"city:{loc}:{svc}", "page_type": "city_service", "url": f"/{slugify(loc)}-{slugify(svc)}/",
                "location": loc, "service": svc, "parent": hub_key or f"svc:{svc}", "title": f"{svc} – {loc}", "sort": 101 + j * 10 + i,
                "h1": L["city_h1"].format(service=svc, city=loc),
                "seo_goal": f"A(z) {loc} + {svc} kereskedelmi keresések lefedése és ajánlatkérés generálása.",
            })
    if {"content_strategy", "content_mgmt", "wireframes_only"} & set(project.scope or []):
        pages.append({"key": "blog", "page_type": "pillar", "url": L["blog"], "parent": "home", "title": L["blog_title"], "sort": 900,
                      "seo_goal": "A cikkek központi listázása; a cikkek innen a szolgáltatási oldalak felé vezetnek."})
    for key, url, title, sort in (("work", L["work"], L["work_title"], 901), ("about", L["about"], L["about_title"], 902), ("contact", L["contact"], L["contact_title"], 903)):
        pages.append({"key": key, "page_type": "support", "url": url, "parent": "home", "title": title, "sort": sort,
                      "seo_goal": "Támogató oldal (bizalom, proof, konverzió); nem kap önálló SEO-fókuszt."})
    for p in pages:
        p.setdefault("location", "")
        p.setdefault("service", "")
        p.setdefault("h1", p["title"])
        p["seo_title"] = f"{p['h1']} | {brand}" if p["page_type"] != "home" else f"{industry} | {brand}"
        p["cta_label"] = L["cta"]
        p["cta_url"] = L["contact"]
        p["intent"] = "commercial" if p["page_type"] in ("service_hub", "service", "city_service", "city_hub") else ""
    return pages


def assign_keywords(pages: list[dict], rows: list[tuple[KeywordAnalysis, Keyword]], taken: set[int]) -> None:
    """Elsődleges és másodlagos kulcsszavak. Specifikus oldal előbb választ; egy kulcsszó csak egy oldalhoz tartozik."""
    order = {"city_service": 0, "service": 1, "service_hub": 1, "city_hub": 2}
    used = set(taken)
    for p in sorted(pages, key=lambda p: order.get(p["page_type"], 9)):
        if p["page_type"] not in order:
            p["primary"], p["secondary"] = None, []
            continue
        cands = []
        for a, k in rows:
            if k.id in used or a.intent not in COMMERCIAL:
                continue
            loc_ok = (k.term_location == p["location"]) if p["page_type"] in ("city_service", "city_hub") else (k.term_location == "")
            if not loc_ok:
                continue
            if p["page_type"] == "city_hub":
                if any(matches_service(k.term, s) for s in [x["service"] for x in pages if x["service"]]):
                    continue
            elif not matches_service(k.term, p["service"]):
                continue
            cands.append((a, k))
        cands.sort(key=lambda ak: rank_key(*ak), reverse=True)
        p["primary"] = cands[0][1].id if cands else None
        p["secondary"] = [k.id for _, k in cands[1:9]]
        used.update([p["primary"]] if p["primary"] else [])
        used.update(p["secondary"])


def _llm_refine_structure(db: Session, project: Project, pages: list[dict], rows, job: Job) -> None:
    by_id = {k.id: k for _, k in rows}
    by_term = {k.term_normalized: k.id for _, k in rows}
    payload = []
    for p in pages:
        cands = ([p["primary"]] if p.get("primary") else []) + p.get("secondary", [])
        payload.append({"key": p["key"], "page_type": p["page_type"], "url": p["url"], "location": p["location"], "service": p["service"],
                        "h1": p["h1"], "candidate_keywords": [by_id[c].term for c in cands if c in by_id]})
    user = prompts.project_context(project) + "\n\nVázlat (JSON):\n" + json.dumps(payload, ensure_ascii=False)
    data = llm.json_call(db, name="structure", version=prompts.STRUCTURE_VERSION, system=prompts.STRUCTURE_SYSTEM, user=user,
                         schema=prompts.STRUCTURE_SCHEMA, project_id=project.id, job_id=job.id)
    by_key = {p["key"]: p for p in pages}
    from .importers import normalize_term

    for it in data.get("pages", []):
        p = by_key.get(it["key"])
        if not p:
            continue
        if not it["keep"] and p["page_type"] == "city_service":
            p["drop"] = it.get("notes") or "Az AI szerint nem indokolt."
            continue
        allowed = set(([p["primary"]] if p.get("primary") else []) + p.get("secondary", []))
        prim = by_term.get(normalize_term(it["primary_keyword"]))
        if prim in allowed:
            p["secondary"] = [x for x in ([p["primary"]] if p.get("primary") else []) + p["secondary"] if x != prim]
            p["primary"] = prim
        url = "/" + slugify(it["url"].strip("/").split("/")[-1]) + "/" if it["url"].strip("/") else p["url"]
        if p["page_type"] not in ("home",) and it["url"].strip("/"):
            parts = [slugify(x) for x in it["url"].strip("/").split("/") if x]
            url = "/" + "/".join(parts) + "/"
        p.update({"url": url if p["page_type"] != "home" else "/", "seo_title": it["seo_title"] or p["seo_title"], "h1": it["h1"] or p["h1"],
                  "seo_goal": it["seo_goal"] or p["seo_goal"], "cta_label": it["cta_label"] or p["cta_label"], "notes": it["notes"]})


def build_links(db: Session, project: Project, pages_by_key: dict[str, Page], meta: dict[str, dict]) -> int:
    L = lang(project)
    db.execute(delete(InternalLink).where(InternalLink.project_id == project.id, InternalLink.source == "ai"))
    n = 0

    def add(frm: Page, to: Optional[Page], anchor: str, placement: str, link_type: str, note: str = "", to_url: str = ""):
        nonlocal n
        if frm is None or (to is None and not to_url) or (to is not None and to.id == frm.id):
            return
        db.add(InternalLink(project_id=project.id, from_page_id=frm.id, to_page_id=to.id if to else None, to_url=to_url or (to.url if to else ""),
                            anchor=anchor, placement=placement, link_type=link_type, note=note, source="ai"))
        n += 1

    home = pages_by_key.get("home")
    contact = pages_by_key.get("contact")
    work = pages_by_key.get("work")
    for key, p in pages_by_key.items():
        m = meta.get(key, {})
        if p.page_type in ("service_hub", "service"):
            add(home, p, L["explore"].format(x=p.title), "Szolgáltatási kártyák", "card")
            for k2, c in pages_by_key.items():
                if c.page_type == "city_service" and meta.get(k2, {}).get("service") == m.get("service"):
                    add(p, c, c.location, "Városválasztó", "text", "Kis városválasztó; ne legyen hosszú városlista.")
        if p.page_type == "city_hub":
            add(home, p, p.location, "Városválasztó / térkép", "text")
            for k2, c in pages_by_key.items():
                if c.page_type == "city_service" and meta.get(k2, {}).get("location") == m.get("location"):
                    add(p, c, c.title, "Szolgáltatási utak", "card")
            add(p, work, L["work_title"], "Selected work", "text")
        if p.page_type == "city_service":
            hub = pages_by_key.get(f"hub:{m.get('location')}")
            add(p, hub, p.location, "Kapcsolódó szolgáltatások", "text", "Vissza a városi hubra.")
            svc = pages_by_key.get(f"svc:{m.get('service')}")
            add(p, svc, m.get("service", ""), "Szolgáltatás bemutatása", "text")
            siblings = [c for k2, c in pages_by_key.items() if c.page_type == "city_service" and meta.get(k2, {}).get("location") == m.get("location") and c.id != p.id]
            for c in siblings[:3]:
                add(p, c, meta.get(next(k for k, v in pages_by_key.items() if v is c), {}).get("service", c.title), "Kapcsolódó helyi szolgáltatások", "card")
            add(p, work, L["work_title"], "Selected work", "text")
        if p.page_type in ("service_hub", "service", "city_service", "city_hub", "home") and contact:
            add(p, contact, p.cta_label or L["cta"], "Fő CTA (hero és záró)", "button")
    return n


@handler("generate_structure")
def job_structure(db: Session, job: Job, progress: Progress):
    project = db.get(Project, job.project_id)
    rows = analysed(db, project.id)
    if not rows:
        raise JobError("Előbb futtasd a kulcsszó-elemzést (Kulcsszavak fül).")
    progress(0.1, "Oldalmodellek összeállítása…")
    existing = db.scalars(select(Page).where(Page.project_id == project.id)).all()
    kept = {p.url: p for p in existing if p.status != "suggested" or p.ai.get("origin") == "roadmap"}
    for p in existing:
        if p.url not in kept:
            db.delete(p)
    db.flush()
    taken = set(db.scalars(select(PageKeyword.keyword_id).join(Page, Page.id == PageKeyword.page_id).where(Page.project_id == project.id)).all())
    sk = skeleton(project, rows)
    assign_keywords(sk, rows, taken)
    method = "heuristic"
    if llm.openai_available(db):
        progress(0.4, "Címek, H1-ek és célok finomítása (AI)…")
        try:
            _llm_refine_structure(db, project, sk, rows, job)
            method = "llm"
        except llm.LLMError as e:
            raise JobError(str(e))
    progress(0.7, "Mentés és belső linkek…")
    pages_by_key: dict[str, Page] = {}
    meta: dict[str, dict] = {}
    seen_urls = set(kept)
    dropped = []
    for p in sk:
        if p.get("drop"):
            dropped.append({"url": p["url"], "reason": p["drop"]})
            continue
        if p["url"] in kept:
            page = kept[p["url"]]
            page.ai = {**{k: p.get(k) for k in ("h1", "seo_title", "seo_goal", "cta_label")}, "origin": "structure"}
        else:
            if p["url"] in seen_urls:
                p["url"] = p["url"].rstrip("/") + "-2/"
            seen_urls.add(p["url"])
            page = Page(project_id=project.id, url=p["url"], page_type=p["page_type"], location=p["location"], service=p["service"],
                        title=p["title"], seo_title=p["seo_title"][:255], h1=p["h1"][:255], intent=p["intent"], seo_goal=p["seo_goal"],
                        cta_label=p["cta_label"], cta_url=p["cta_url"], sort=p["sort"], notes=p.get("notes", ""), status="suggested",
                        priority="hub" if p["page_type"] in ("home", "city_hub", "pillar") else ("support" if p["page_type"] == "support" else ""),
                        schema_types=schema_for(p["page_type"]), ai={"origin": "structure"})
            db.add(page)
            db.flush()
            if p.get("primary"):
                db.add(PageKeyword(page_id=page.id, keyword_id=p["primary"], role="primary"))
            for kid in p.get("secondary", []):
                db.add(PageKeyword(page_id=page.id, keyword_id=kid, role="secondary"))
        pages_by_key[p["key"]] = page
        meta[p["key"]] = p
    db.flush()
    for key, p in meta.items():
        parent = pages_by_key.get(p.get("parent", ""))
        if parent and pages_by_key[key].parent_id is None and parent.id != pages_by_key[key].id:
            pages_by_key[key].parent_id = parent.id
    # Page prioritás a primary kulcsszó prioritásából
    prio = {a.keyword_id: a.priority for a, _ in rows}
    for key, page in pages_by_key.items():
        pk = meta[key].get("primary")
        if pk and not page.priority:
            page.priority = prio.get(pk, "P2") if prio.get(pk) in ("P1", "P2") else "P2"
    # Klaszter → céloldal
    by_kw_cluster = {a.keyword_id: a.cluster_id for a, _ in rows}
    for key, page in pages_by_key.items():
        pk = meta[key].get("primary")
        cid = by_kw_cluster.get(pk)
        if cid:
            c = db.get(Cluster, cid)
            if c and c.target_page_id is None:
                c.target_page_id = page.id
    links = build_links(db, project, pages_by_key, meta)
    log(db, project.id, job.created_by, "structure", None, "generate", f"Oldalstruktúra: {len(pages_by_key)} oldal, {links} belső link ({method})")
    return {"pages": len(pages_by_key), "links": links, "dropped": dropped, "method": method}


def schema_for(page_type: str) -> list[str]:
    return {
        "home": ["Organization", "WebSite"],
        "service_hub": ["Service", "BreadcrumbList", "FAQPage"],
        "service": ["Service", "BreadcrumbList", "FAQPage"],
        "city_service": ["Service", "LocalBusiness", "BreadcrumbList", "FAQPage"],
        "city_hub": ["LocalBusiness", "BreadcrumbList", "FAQPage"],
        "article": ["Article", "BreadcrumbList"],
        "case_study": ["Article", "CreativeWork", "BreadcrumbList"],
        "pillar": ["CollectionPage", "BreadcrumbList"],
    }.get(page_type, ["BreadcrumbList"])


def structure_warnings(db: Session, project: Project) -> list[dict]:
    """Kannibalizációs és lefedettségi figyelmeztetések."""
    pages = db.scalars(select(Page).where(Page.project_id == project.id).options(selectinload(Page.keywords))).all()
    rows = analysed(db, project.id)
    by_id = {k.id: (a, k) for a, k in rows}
    out = []
    primary_of = {}
    for p in pages:
        prim = next((pk.keyword_id for pk in p.keywords if pk.role == "primary"), None)
        if p.page_type in ("service_hub", "service", "city_service", "city_hub", "article") and not prim:
            out.append({"page_id": p.id, "url": p.url, "kind": "no_primary", "message": f"{p.url}: nincs elsődleges kulcsszó."})
        if prim and prim in by_id:
            a, k = by_id[prim]
            primary_of[p.id] = k
            if p.page_type in ("article", "pillar") and a.intent in ("commercial", "transactional"):
                out.append({"page_id": p.id, "url": p.url, "kind": "intent_mismatch",
                            "message": f"{p.url}: a cikk elsődleges kulcsszava („{k.term}”) kereskedelmi szándékú – ezt landing oldal célozza."})
            if p.page_type in ("service_hub", "service", "city_service") and a.intent in ("informational", "problem"):
                out.append({"page_id": p.id, "url": p.url, "kind": "intent_mismatch",
                            "message": f"{p.url}: a szolgáltatási oldal elsődleges kulcsszava („{k.term}”) információs – cikkbe való."})
    seen: dict[str, Page] = {}
    for p in pages:
        k = primary_of.get(p.id)
        if not k:
            continue
        sig = " ".join(sorted(stems(k.term)))
        if sig in seen:
            out.append({"page_id": p.id, "url": p.url, "kind": "cannibalization",
                        "message": f"{p.url} és {seen[sig].url}: ugyanarra a keresési szándékra céloznak („{k.term}”)."})
        else:
            seen[sig] = p
    mapped = set(db.scalars(select(PageKeyword.keyword_id).join(Page, Page.id == PageKeyword.page_id).where(Page.project_id == project.id)).all())
    orphans = [k for a, k in rows if a.priority == "P1" and a.intent in ("commercial", "transactional") and k.id not in mapped]
    for k in orphans[:30]:
        out.append({"page_id": None, "url": "", "kind": "orphan", "message": f"P1 kereskedelmi kulcsszó oldal nélkül: „{k.term}”."})
    return out


# ── Tartalmi roadmap ─────────────────────────────────────

MEASUREMENT = [
    ("Indulás + 0–30 nap", "Indexelés és technikai alap", "Indexelt oldalak, sitemap, canonical, robots, 404/redirect, Core Web Vitals, GA4/GSC konverziós események",
     "Search Console, GA4, crawler", "A fontos oldalak és az első cikkek indexelődnek, nincs technikai blokk", "Technikai hibák javítása elsőbbséget élvez", "Core oldalak + első tartalmak"),
    ("31–60 nap", "Query discovery", "Impressions, új query-k, brand vs non-brand, mely cikkek kapnak első láthatóságot", "Search Console",
     "Megjelennek releváns szolgáltatási és információs query-k", "On-page finomhangolás és belső linkek erősítése", "Cikk + pillar párok"),
    ("61–90 nap", "Első organikus kattintások", "CTR, kattintások, landing sessions, engagement, CTA-kattintás", "GSC + GA4",
     "Nő a non-brand kattintás és a szolgáltatási oldalakra továbblépés", "Title/meta, intent és CTA finomítás", "P1 tartalmak"),
    ("4. hónap", "Topical authority és assisted path", "Cikk → szolgáltatási oldal kattintások, assisted conversion, case study megtekintés", "GA4 + GSC",
     "A cikkek szolgáltatási oldali továbbhaladást is adnak", "Kapcsolódó cikkek és proof erősítése", "Pillar klaszterek"),
    ("5. hónap", "Leadminőség", "Űrlapbeküldés, hívás, lead forrás, landing + tartalom útvonal", "GA4 + CRM",
     "Organikus látogatásból releváns érdeklődések", "Magas szándékú témák bővítése", "P1 + iparági tartalmak"),
    ("6. hónap", "Következő időszak döntése", "Nyertes/vesztes témák, query gap, város/szolgáltatás növekedés, konverzió", "GSC + GA4 + CRM",
     "5–10 nyertes téma és a következő content gap", "Kulcsszókutatás-frissítés vagy 2. féléves roadmap", "Teljes tartalomrendszer"),
]


def ensure_measurement(db: Session, project: Project) -> int:
    if db.scalar(select(MeasurementItem.id).where(MeasurementItem.project_id == project.id).limit(1)):
        return 0
    for i, row in enumerate(MEASUREMENT):
        period = row[0] if not (i == 5 and project.strategy_months != 6) else f"{project.strategy_months}. hónap"
        db.add(MeasurementItem(project_id=project.id, sort=i, period=period, focus=row[1], what=row[2], where_measured=row[3],
                               success_signal=row[4], decision=row[5], content_scope=row[6]))
    return len(MEASUREMENT)


def content_direction(bucket: str, term: str, target: Optional[Page]) -> str:
    tgt = target.url if target else "a releváns szolgáltatási oldal"
    if bucket == "problem":
        return f"Probléma felismerése → okok → mit lehet tenni → mikor kell szakember → hogyan segítünk ({tgt})."
    if bucket == "comparison":
        return f"Döntési szempontok → összehasonlítás → mit kérdezz, mielőtt választasz → checklist → következő lépés ({tgt})."
    return f"A kérdés megválaszolása → gyakorlati lépések → tipikus hibák → helyi példa, ha releváns → továbbvezetés ({tgt})."


def social_hook(bucket: str, term: str, content_language: str) -> str:
    hu = content_language.startswith("hu")
    t = term
    if bucket == "problem":
        return f"{'5 jel, hogy' if hu else '5 signs'} {t}" if hu else f"5 signs you need help with {t}"
    if bucket == "comparison":
        return f"{'7 kérdés, mielőtt döntesz:' if hu else '7 questions to ask before choosing'} {t}"
    return f"{'Gyors checklist:' if hu else 'Quick checklist:'} {t}"


@handler("generate_roadmap")
def job_roadmap(db: Session, job: Job, progress: Progress):
    project = db.get(Project, job.project_id)
    L = lang(project)
    rows = analysed(db, project.id)
    if not rows:
        raise JobError("Előbb futtasd a kulcsszó-elemzést (Kulcsszavak fül).")
    include_paid = bool(job.payload.get("include_paid", True))
    progress(0.05, "Korábbi javaslatok törlése…")
    old = db.scalars(select(RoadmapItem).where(RoadmapItem.project_id == project.id, RoadmapItem.review == "suggested")).all()
    old_pages = [i.page_id for i in old if i.page_id]
    for i in old:
        db.delete(i)
    db.flush()
    for pid in old_pages:
        p = db.get(Page, pid)
        if p is not None and p.status == "suggested" and p.ai.get("origin") == "roadmap":
            db.delete(p)
    db.execute(delete(ParkedTopic).where(ParkedTopic.project_id == project.id))
    db.flush()
    kept_items = db.scalars(select(RoadmapItem).where(RoadmapItem.project_id == project.id)).all()

    pages = db.scalars(select(Page).where(Page.project_id == project.id).options(selectinload(Page.keywords))).all()
    mapped = {pk.keyword_id for p in pages for pk in p.keywords}
    blog = next((p for p in pages if p.page_type == "pillar"), None)
    contact_url = next((p.url for p in pages if p.url == L["contact"]), L["contact"])
    commercial_pages = [p for p in pages if p.page_type in ("service_hub", "service", "city_service", "city_hub")]
    cluster_target = {}
    for p in commercial_pages:
        for pk in p.keywords:
            a = next((a for a, k in rows if k.id == pk.keyword_id), None)
            if a and a.cluster_id and a.cluster_id not in cluster_target:
                cluster_target[a.cluster_id] = p

    def target_for(a: KeywordAnalysis, k: Keyword) -> Optional[Page]:
        if a.cluster_id in cluster_target:
            return cluster_target[a.cluster_id]
        best = None
        for p in commercial_pages:
            if p.page_type in ("service_hub", "service") and p.service and (stems(p.service) & stems(k.term)):
                best = p
                break
        return best or next((p for p in commercial_pages if p.page_type in ("service_hub", "service")), None)

    # Témajelöltek: információs / összehasonlító / probléma keresések, amelyek még nincsenek oldalhoz rendelve.
    cands = [(a, k) for a, k in rows if k.id not in mapped and a.priority in ("P1", "P2")
             and (a.bucket in ("informational", "comparison", "problem") or (a.intent == "commercial_investigation" and not k.term_location))]
    cands.sort(key=lambda ak: (0 if ak[0].priority == "P1" else 1, -float(ak[0].priority_score or 0)))
    by_cluster: dict[Any, list] = defaultdict(list)
    for a, k in cands:
        by_cluster[a.cluster_id or f"k{k.id}"].append((a, k))
    loc_stems = set().union(*[stems(loc) for loc in project.locations or []]) if project.locations else set()
    topics, overflow = [], []
    for cid, members in by_cluster.items():
        # Altémák: a klaszter nevén és a lokáción túli szavak szerint („cost”, „ideas”, „timeline” külön cikk;
        # a közeli változatok egy cikkbe kerülnek kapcsolódó kulcsszóként).
        cname = stems(members[0][0].cluster.name) if members[0][0].cluster else set()
        groups: list[tuple[set, list]] = []
        for a, k in members:
            residual = stems(k.term) - cname - loc_stems
            home = next((g for g in groups if residual and (g[0] & residual)), None)
            if home is None and not residual:
                home = next((g for g in groups if not g[0]), None)
            if home is None:
                groups.append((set(residual), [(a, k)]))
            else:
                home[0].update(residual)
                home[1].append((a, k))
        for _, sub in groups:
            head = sub[0]
            topics.append((head, [k for _, k in sub[1:5]]))
            for a, k in sub[5:]:
                overflow.append((a, k, head[1]))
    topics.sort(key=lambda t: (0 if t[0][0].priority == "P1" else 1, -float(t[0][0].priority_score or 0)))

    start = project.start_date or date.today().replace(day=1)
    start = start.replace(day=1)
    months = max(1, project.strategy_months or 6)
    per_month = max(1, project.content_per_month or 2)
    case_per_month = 1 if per_month >= 4 and "case_study" not in {i.content_type for i in kept_items} else 0
    art_per_month = per_month - case_per_month
    capacity = art_per_month * months
    chosen, leftover = topics[:capacity], topics[capacity:]
    progress(0.3, f"{len(chosen)} téma ütemezése…")

    items: list[tuple[RoadmapItem, Optional[Page], KeywordAnalysis, Keyword]] = []
    used_primary = set(mapped)
    sort = 0
    for i, ((a, k), related) in enumerate(chosen):
        month = add_months(start, i // art_per_month)
        tgt = target_for(a, k)
        slug = slugify(k.term)
        url = (blog.url if blog else L["blog"]) + slug + "/"
        page = None
        if k.id not in used_primary:
            existing_url = next((p for p in pages if p.url == url), None)
            if existing_url is None:
                page = Page(project_id=project.id, url=url, page_type="article", parent_id=blog.id if blog else None, title=title_case(k.term),
                            h1=title_case(k.term), seo_title=title_case(k.term)[:200], intent=a.intent, priority=a.priority, cta_label=L["explore"].format(x=tgt.title) if tgt else L["cta"],
                            cta_url=tgt.url if tgt else contact_url, seo_goal=f"Információs/döntési kérdés megválaszolása, továbbvezetés: {tgt.url if tgt else contact_url}.",
                            schema_types=schema_for("article"), status="suggested", ai={"origin": "roadmap"}, sort=500 + i)
                db.add(page)
                db.flush()
                db.add(PageKeyword(page_id=page.id, keyword_id=k.id, role="primary"))
                for r in related:
                    if r.id not in used_primary:
                        db.add(PageKeyword(page_id=page.id, keyword_id=r.id, role="secondary"))
                        used_primary.add(r.id)
                used_primary.add(k.id)
                if tgt:
                    db.add(InternalLink(project_id=project.id, from_page_id=page.id, to_page_id=tgt.id, to_url=tgt.url, anchor=L["explore"].format(x=tgt.title),
                                        placement="Kontextuális link + záró CTA", link_type="text", source="ai"))
                if blog:
                    db.add(InternalLink(project_id=project.id, from_page_id=blog.id, to_page_id=page.id, to_url=page.url, anchor=page.h1,
                                        placement="Cikklista", link_type="card", source="ai"))
        cluster = a.cluster
        item = RoadmapItem(
            project_id=project.id, month=month, priority=a.priority, page_id=page.id if page else None, cluster_id=a.cluster_id,
            keyword_id=k.id, content_type="article", pillar=(cluster.pillar or cluster.name) if cluster else "", title=title_case(k.term),
            url=url, related_keywords=[r.term for r in related], location_context=", ".join(project.locations[:3]) if project.locations else "",
            content_direction=content_direction(a.bucket, k.term, tgt), cta=L["explore"].format(x=tgt.title) if tgt else L["cta"],
            internal_links=[tgt.url] if tgt else [], social_hook=social_hook(a.bucket, k.term, project.content_language),
            cannibalization_rule=(cluster.cannibalization_rule if cluster and cluster.cannibalization_rule else
                                  "A cikk információs vagy döntési kérdésre válaszol; nem másolja a szolgáltatási landing szövegét."),
            status="planned", sort=sort, review="suggested", ai={"origin": "roadmap"},
        )
        sort += 1
        db.add(item)
        items.append((item, page, a, k))
    if case_per_month:
        services = [s.get("name") for s in project.business_services or []] or [""]
        work = next((p for p in pages if p.url == L["work"]), None)
        for m in range(months):
            svc = services[m % len(services)]
            url = (work.url if work else L["work"]) + f"case-study-{m + 1}/"
            page = Page(project_id=project.id, url=url, page_type="case_study", parent_id=work.id if work else None, title=f"Case study {m + 1}",
                        h1=L["case_title"], seo_title=L["case_title"], cta_label=L["case_cta"], cta_url=contact_url, schema_types=schema_for("case_study"),
                        seo_goal="Proof: üzleti probléma, döntések, kivitelezés és ellenőrzött eredmény.", status="suggested", ai={"origin": "roadmap"}, sort=800 + m)
            db.add(page)
            db.flush()
            svc_page = next((p for p in commercial_pages if p.service == svc and p.page_type in ("service_hub", "service")), None)
            if svc_page:
                db.add(InternalLink(project_id=project.id, from_page_id=page.id, to_page_id=svc_page.id, to_url=svc_page.url, anchor=svc,
                                    placement="Related services", link_type="text", source="ai"))
            item = RoadmapItem(project_id=project.id, month=add_months(start, m), priority="P1", page_id=page.id, content_type="case_study",
                               pillar=svc, title=L["case_title"], url=url, content_direction="Project overview → Challenge → Objectives → Research/Strategy → "
                               "Structure/Wireframe → UX/UI Design → Development → SEO/Marketing csak ha releváns → Results → Client feedback ha jóváhagyott → Related services + CTA. "
                               "Csak valós, ellenőrzött proof.", cta=L["case_cta"], internal_links=[svc_page.url] if svc_page else [],
                               social_hook="Before/after: what changed", cannibalization_rule="Proof tartalom; nincs kötelező exact-match kulcsszó.",
                               client_input="Projektbrief, before állapot, jóváhagyott eredmények, testimonial engedéllyel.", status="planned",
                               sort=sort, review="suggested", ai={"origin": "roadmap"})
            sort += 1
            db.add(item)
            items.append((item, page, None, None))
    db.flush()

    # Félretett témák (a 6 hónapba nem fért bele / átfed / erős verseny).
    parked = 0
    for (a, k), related in leftover:
        db.add(ParkedTopic(project_id=project.id, keyword_id=k.id, term=k.term, pillar=a.cluster.name if a.cluster else "",
                           reason="Kapacitás: a mostani időszakba nem fért bele.", recommended_handling="Következő időszak / friss GSC-adat után."))
        parked += 1
    for a, k, head in overflow[:40]:
        m = best_metric(k)
        reason = f"Átfed a(z) „{head.term}” témával." if a.priority == "P2" else "Erős, de ugyanazt a döntési szándékot közelíti."
        handling = "Konszolidált follow-up vagy a fő cikkben kezelve."
        if (m["kd"] or 0) >= 50:
            reason, handling = f"Magasabb KD ({m['kd']}).", "Proof és belső linkek erősítése után."
        db.add(ParkedTopic(project_id=project.id, keyword_id=k.id, term=k.term, pillar=a.cluster.name if a.cluster else "", reason=reason,
                           recommended_handling=handling))
        parked += 1
    for a, k in rows:
        if a.priority == "P2" and a.intent in ("commercial", "transactional") and k.term_location and k.id not in used_primary and parked < 80:
            db.add(ParkedTopic(project_id=project.id, keyword_id=k.id, term=k.term, pillar=a.cluster.name if a.cluster else "",
                               reason="Lokális szolgáltatóválasztó szándék; elsődlegesen a városi landing feladata.",
                               recommended_handling="Ne külön bloggal célozzuk; városi landing + GBP/proof erősítés."))
            parked += 1
    ensure_measurement(db, project)

    if include_paid:
        for item, page, a, k in items:
            bucket = a.bucket if a else "proof"
            db.add(PaidPlanItem(
                project_id=project.id, roadmap_item_id=item.id,
                seo_role={"informational": "Információs / kereskedelmi rásegítés", "comparison": "Szolgáltatóválasztást támogató",
                          "problem": "Problémafelismerés", "proof": "Proof / bizalomépítés"}.get(bucket, "Információs"),
                meta_creative="Before/after carousel" if item.content_type == "case_study" else ("Checklist / carousel" if bucket != "problem" else "Mistakes carousel"),
                paid_role="Warm remarketing" if item.content_type == "case_study" else "Cold boost + engaged audience építés",
                search_target=(item.internal_links[0] if item.internal_links else contact_url),
                remarketing_next="/contact/" if item.content_type == "case_study" else "Case study → " + contact_url,
                kpi="case_study_view, form_submit" if item.content_type == "case_study" else "article_50%, service_click, form_start",
            ))

    method = "heuristic"
    if llm.openai_available(db) and items:
        progress(0.6, "Címek, tartalmi irányok és hookok (AI)…")
        try:
            _llm_refine_roadmap(db, project, items, job)
            method = "llm"
        except llm.LLMError as e:
            raise JobError(str(e))
    log(db, project.id, job.created_by, "roadmap", None, "generate",
        f"Tartalmi roadmap: {len(items)} tétel {months} hónapra, {parked} félretett téma ({method})")
    return {"items": len(items), "parked": parked, "months": months, "method": method}


def _llm_refine_roadmap(db: Session, project: Project, items, job: Job) -> None:
    for start in range(0, len(items), 20):
        chunk = items[start : start + 20]
        payload = [{"key": str(it.id), "month": it.month.isoformat(), "content_type": it.content_type, "primary_keyword": k.term if k else "",
                    "related": it.related_keywords, "target_url": (it.internal_links or [""])[0], "pillar": it.pillar,
                    "draft_direction": it.content_direction} for it, page, a, k in chunk]
        user = prompts.project_context(project) + "\n\nTételek (JSON):\n" + json.dumps(payload, ensure_ascii=False)
        data = llm.json_call(db, name="roadmap", version=prompts.ROADMAP_VERSION, system=prompts.ROADMAP_SYSTEM, user=user,
                             schema=prompts.ROADMAP_SCHEMA, project_id=project.id, job_id=job.id, temperature=0.5)
        by_key = {str(it.id): (it, page) for it, page, a, k in chunk}
        for r in data.get("items", []):
            it, page = by_key.get(r["key"], (None, None))
            if it is None:
                continue
            if it.content_type != "case_study":
                it.title = r["title"][:512] or it.title
                if page is not None:
                    page.h1 = it.title[:255]
                    page.seo_title = it.title[:200]
            it.content_direction = r["content_direction"] or it.content_direction
            it.cta = r["cta"] or it.cta
            it.social_hook = r["social_hook"] or it.social_hook
            it.cannibalization_rule = r["cannibalization_rule"] or it.cannibalization_rule
            it.ai = {**it.ai, **r}


# ── Wireframe ────────────────────────────────────────────


class _Safe(dict):
    def __missing__(self, key):
        return ""


def wireframe_context(db: Session, project: Project, page: Page) -> dict:
    L = lang(project)
    links = db.scalars(select(InternalLink).where(InternalLink.from_page_id == page.id)).all()
    kw = {pk.role: [] for pk in page.keywords}
    for pk in page.keywords:
        k = db.get(Keyword, pk.keyword_id)
        if k:
            kw.setdefault(pk.role, []).append(k)
    primary = (kw.get("primary") or [None])[0]
    target = next((lk for lk in links if lk.link_type == "text" and lk.to_page_id), None)
    all_pages = db.scalars(select(Page).where(Page.project_id == project.id)).all()
    svc_links = [p for p in all_pages if (p.page_type == "city_service" and p.location == page.location) or (page.page_type == "home" and p.page_type in ("service_hub", "service"))]
    city_links = [p for p in all_pages if p.page_type == "city_hub"]
    item = db.scalar(select(RoadmapItem).where(RoadmapItem.page_id == page.id).limit(1))
    m = best_metric(primary) if primary else {"volume": None, "kd": None}
    hu_content = project.content_language.startswith("hu")
    return _Safe(
        primary=primary.term if primary else "–",
        secondary="; ".join(k.term for k in kw.get("secondary", [])[:6]) or "–",
        volume=m["volume"], kd=m["kd"],
        summary=(item.content_direction if item else page.seo_goal) or "",
        target_url=target.to_url if target else (page.cta_url or L["contact"]),
        target_anchor=target.anchor if target else (page.cta_label or L["cta"]),
        contact_url=L["contact"], work_url=L["work"], cta=page.cta_label or L["cta"],
        location=page.location, service=page.service, title=page.h1 or page.title,
        location_context=(item.location_context if item else ", ".join(project.locations or [])) or "Helyi kontextus csak valós relevancia esetén",
        local_variation=page.local_variation or "Helyi eltérés: iparágak, problémák, példák, FAQ és proof városonként eltér.",
        service_links=", ".join(p.url for p in svc_links[:6]) or "–",
        city_links=", ".join(p.url for p in city_links[:6]) or "–",
        related_links=", ".join(lk.to_url for lk in links[:5]) or "–",
        language_note="" if hu_content else " Angolul kutass, és ha AI-jal fordítasz, amerikai angol legyen a fordítás.",
    ), links, item


def fill(text: str, ctx: dict) -> str:
    return text.format_map(ctx)


@handler("generate_wireframes")
def job_wireframes(db: Session, job: Job, progress: Progress):
    project = db.get(Project, job.project_id)
    ids = job.payload.get("page_ids") or []
    q = select(Page).where(Page.project_id == project.id).options(selectinload(Page.keywords))
    if ids:
        q = q.where(Page.id.in_(ids))
    pages = db.scalars(q.order_by(Page.sort, Page.id)).all()
    if not pages:
        raise JobError("Nincs oldal. Előbb készíts oldalstruktúrát.")
    use_llm = llm.openai_available(db)
    made = 0
    for i, page in enumerate(pages):
        progress(i / len(pages), f"Wireframe: {page.url}")
        build_wireframe(db, project, page, use_llm, job)
        made += 1
    log(db, project.id, job.created_by, "wireframes", None, "generate", f"{made} wireframe ({'AI' if use_llm else 'sablon'})")
    return {"wireframes": made, "method": "llm" if use_llm else "heuristic"}


def build_wireframe(db: Session, project: Project, page: Page, use_llm: bool, job: Optional[Job] = None) -> Wireframe:
    tpl = wt.BY_TYPE.get(page.page_type, wt.SUPPORT)
    ctx, links, item = wireframe_context(db, project, page)
    sections = [{"h2": fill(h, ctx), "goal": fill(g, ctx), "what_to_write": fill(w, ctx), "seo_usage": fill(s, ctx), "link_cta": fill(lk, ctx),
                 "length_min": a, "length_max": b} for h, g, w, s, lk, a, b in tpl["sections"]]
    link_rows = [{"placement": lk.placement, "anchor": lk.anchor, "target": lk.to_url, "note": lk.note or {"button": "Gomb", "card": "Kártya", "text": "Szövegbe illesztett link"}.get(lk.link_type, "")} for lk in links]
    data = {
        "essence": fill(tpl["essence"], ctx),
        "flow": [s["h2"] for s in sections],
        "word_count_min": tpl["word_count"][0],
        "word_count_max": tpl["word_count"][1],
        "sections": sections,
        "links": link_rows,
        "proof_requirements": tpl.get("proof", ""),
        "must_have": [fill(x, ctx) for x in tpl.get("must_have", [])],
        "forbidden": [fill(x, ctx) for x in tpl.get("forbidden", [])],
    }
    method = "heuristic"
    if use_llm and page.page_type not in ("support",):
        allowed = {lk["target"] for lk in link_rows} | {ctx["contact_url"], ctx["work_url"]}
        user = (prompts.project_context(project) + f"\n\nOldal: {page.url} ({page.page_type}), H1: {page.h1}\nElsődleges kulcsszó: {ctx['primary']}"
                f" (volumen {ctx['volume']}, KD {ctx['kd']})\nKapcsolódó kulcsszavak: {ctx['secondary']}\n"
                f"Tartalmi irány: {ctx['summary']}\nEngedélyezett belső URL-ek: {', '.join(sorted(allowed))}\n\nSablon (JSON):\n" + json.dumps(data, ensure_ascii=False))
        try:
            out = llm.json_call(db, name="wireframe", version=prompts.WIREFRAME_VERSION, system=prompts.WIREFRAME_SYSTEM, user=user,
                                schema=prompts.WIREFRAME_SCHEMA, project_id=project.id, job_id=job.id if job else None, temperature=0.4)
            out["links"] = [lk for lk in out.get("links", []) if lk.get("target") in allowed] or data["links"]
            data = out
            method = "llm"
        except llm.LLMError:
            method = "heuristic"
    prev = db.scalar(select(Wireframe).where(Wireframe.page_id == page.id).order_by(Wireframe.version.desc()).limit(1))
    wf = Wireframe(
        project_id=project.id, page_id=page.id, roadmap_item_id=item.id if item else None, version=(prev.version + 1) if prev else 1,
        label=f"{page.h1 or page.title}", essence=data["essence"], flow=data["flow"], word_count_min=data["word_count_min"],
        word_count_max=data["word_count_max"], sections=data["sections"], links=data["links"], proof_requirements=data["proof_requirements"],
        must_have=data["must_have"], forbidden=data["forbidden"], visual_sequence=tpl.get("visual", ""),
        inputs=[{"item": a, "description": b, "provided": False} for a, b in tpl.get("inputs", [])], status="draft", method=method,
    )
    db.add(wf)
    if item and item.status == "planned":
        item.status = "wireframe"
    db.flush()
    return wf
