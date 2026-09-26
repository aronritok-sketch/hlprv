"""Dokumentumok felépítése adatból.

Minden dokumentumtípusnak van egy építője: az adatbázisból determinisztikusan összeállítja a szakaszokat és a táblákat
(számok, URL-ek, kulcsszavak – ezeket a nyelvi modell sosem írja), valamint a „facts” összefoglalót, amiből a Claude a
magyarázó szöveget írja. A facts lenyomata dönti el, hogy egy dokumentum elavult-e.

Tartalomformátum:
  {"title", "subtitle", "chip", "sections": [{"key", "title", "narrative": bool, "blocks": [...]}]}
  blokk: paragraph{text} | bullets{items} | table{columns, rows} | callout{title, text} | kpis{items:[{label, value, hint}]}
         | cards{items:[{title, text}]} | steps{items:[{title, text}]}
"""

import hashlib
import json
from collections import Counter, defaultdict
from datetime import date, timedelta
from typing import Any, Optional

from sqlalchemy import select
from sqlalchemy.orm import Session, selectinload

from ..models import (
    BUCKETS,
    CONTENT_TYPES,
    INTENTS,
    PAGE_TYPES,
    Cluster,
    InternalLink,
    Keyword,
    KeywordAnalysis,
    MeasurementItem,
    Page,
    PaidPlanItem,
    ParkedTopic,
    Project,
    RoadmapItem,
    StyleGuide,
    Wireframe,
)
from ..models.research import STYLE_QUESTIONS
from .analysis import latest_profile
from .research import best_metric

HU_MONTHS = ["január", "február", "március", "április", "május", "június", "július", "augusztus", "szeptember", "október", "november", "december"]
EN_MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"]


EN_LABELS = {
    "intent": {"informational": "Informational", "commercial_investigation": "Commercial investigation", "commercial": "Commercial",
               "transactional": "Transactional", "problem": "Problem-aware", "navigational": "Navigational", "mixed": "Mixed"},
    "bucket": {"commercial": "Commercial", "local": "Local", "comparison": "Comparison", "problem": "Problem-based", "informational": "Informational"},
    "page_type": {"home": "Homepage", "service_hub": "Service (regional)", "city_hub": "City hub", "city_service": "City service landing",
                  "service": "Service page", "category": "Category page", "product": "Product page", "pillar": "Pillar / knowledge hub",
                  "article": "Article", "case_study": "Case study", "support": "Support page"},
    "content_type": {"article": "Article", "case_study": "Case study", "service": "Service page", "pillar": "Pillar page",
                     "category": "Category page", "listicle": "Listicle / checklist"},
}
HU_LABELS = {"intent": INTENTS, "bucket": BUCKETS, "page_type": PAGE_TYPES, "content_type": CONTENT_TYPES}


def lbl(kind: str, key: str, lang: str) -> str:
    return (EN_LABELS if lang == "en" else HU_LABELS)[kind].get(key, key or "")


def month_label(d: date, lang: str) -> str:
    return f"{d.year}. {HU_MONTHS[d.month - 1]}" if lang == "hu" else f"{EN_MONTHS[d.month - 1]} {d.year}"


def num(v) -> str:
    return f"{v:,}".replace(",", " ") if isinstance(v, int) else ("–" if v is None else str(v))


def fingerprint(facts: dict) -> str:
    return hashlib.sha256(json.dumps(facts, sort_keys=True, ensure_ascii=False, default=str).encode()).hexdigest()


def P(text: str) -> dict:
    return {"type": "paragraph", "text": text}


def T(columns: list[str], rows: list[list[Any]]) -> dict:
    return {"type": "table", "columns": columns, "rows": [[("" if c is None else c) for c in r] for r in rows]}


def B(items: list[str]) -> dict:
    return {"type": "bullets", "items": [i for i in items if i]}


def section(key: str, title: str, blocks: list[dict], narrative: bool = True) -> dict:
    return {"key": key, "title": title, "narrative": narrative, "blocks": blocks}


# ── Közös adatgyűjtés ────────────────────────────────────


class Data:
    def __init__(self, db: Session, project: Project):
        self.db, self.project = db, project
        self.profile = latest_profile(db, project.id)
        rows = db.execute(
            select(KeywordAnalysis, Keyword).join(Keyword, Keyword.id == KeywordAnalysis.keyword_id)
            .where(KeywordAnalysis.project_id == project.id, Keyword.is_excluded.is_(False))
            .options(selectinload(Keyword.metrics), selectinload(Keyword.rankings))
        ).all()
        self.analysed = [(a, k) for a, k in rows]
        self.kw = {k.id: k for _, k in self.analysed}
        self.an = {a.keyword_id: a for a, _ in self.analysed}
        self.clusters = db.scalars(select(Cluster).where(Cluster.project_id == project.id).order_by(Cluster.total_volume.desc())).all()
        self.pages = db.scalars(select(Page).where(Page.project_id == project.id).options(selectinload(Page.keywords)).order_by(Page.sort, Page.url)).all()
        self.page_by_id = {p.id: p for p in self.pages}
        self.links = db.scalars(select(InternalLink).where(InternalLink.project_id == project.id)).all()
        self.roadmap = db.scalars(select(RoadmapItem).where(RoadmapItem.project_id == project.id).order_by(RoadmapItem.month, RoadmapItem.sort)).all()
        self.paid = {p.roadmap_item_id: p for p in db.scalars(select(PaidPlanItem).where(PaidPlanItem.project_id == project.id)).all()}
        self.measurement = db.scalars(select(MeasurementItem).where(MeasurementItem.project_id == project.id).order_by(MeasurementItem.sort)).all()
        self.parked = db.scalars(select(ParkedTopic).where(ParkedTopic.project_id == project.id)).all()
        wfs = db.scalars(select(Wireframe).where(Wireframe.project_id == project.id).order_by(Wireframe.version)).all()
        self.wireframes = {}
        for w in wfs:
            self.wireframes[w.page_id] = w
        self.style = db.get(StyleGuide, project.id)
        # A mapping: kulcsszó → (oldal, szerep)
        self.page_of: dict[int, tuple[Page, str]] = {}
        for p in self.pages:
            for pk in p.keywords:
                if pk.keyword_id not in self.page_of or pk.role == "primary":
                    self.page_of[pk.keyword_id] = (p, pk.role)

    def primary(self, page: Page) -> Optional[Keyword]:
        pk = next((x for x in page.keywords if x.role == "primary"), None)
        return self.kw.get(pk.keyword_id) if pk else None

    def secondary(self, page: Page) -> list[Keyword]:
        return [self.kw[x.keyword_id] for x in page.keywords if x.role != "primary" and x.keyword_id in self.kw]

    def vol(self, k: Optional[Keyword]):
        return best_metric(k)["volume"] if k else None

    def kd(self, k: Optional[Keyword]):
        return best_metric(k)["kd"] if k else None

    def cluster_rows(self, limit=None):
        members = defaultdict(list)
        for a, k in self.analysed:
            if a.cluster_id:
                members[a.cluster_id].append((a, k))
        out = []
        for c in self.clusters:
            ms = sorted(members.get(c.id, []), key=lambda ak: -float(ak[0].priority_score or 0))
            if not ms:
                continue
            kds = [self.kd(k) for _, k in ms if self.kd(k) is not None]
            out.append({"cluster": c, "members": ms, "kd_range": f"{min(kds)}–{max(kds)}" if kds else "–",
                        "head": ms[0][1], "target": self.page_by_id.get(c.target_page_id)})
        return out[:limit] if limit else out


def lang_of(language: str) -> str:
    return "hu" if language.startswith("hu") else "en"


# ── Ügyféldokumentumok ───────────────────────────────────


def seo_strategy(d: Data, language: str) -> tuple[dict, dict]:
    p = d.project
    L = lang_of(language)
    prio = Counter(a.priority for a, _ in d.analysed)
    buckets = Counter(a.bucket for a, _ in d.analysed)
    clusters = d.cluster_rows(12)
    own = sorted([(k, r) for _, k in d.analysed for r in k.rankings if r.is_own and r.position], key=lambda x: x[1].position)
    comp = defaultdict(list)
    for _, k in d.analysed:
        for r in k.rankings:
            if not r.is_own and r.position and r.position <= 10:
                comp[r.domain].append((r.position, k.term, r.location))
    p1 = sorted([(a, k) for a, k in d.analysed if a.priority == "P1"], key=lambda ak: -float(ak[0].priority_score or 0))[:20]
    facts = {
        "project": p.name, "client": p.client.name, "domain": p.domain, "industry": p.industry, "locations": p.locations,
        "services": [s.get("name") for s in p.business_services or []], "goals": p.business_goals, "audience": p.target_audience,
        "profile": {"summary": d.profile.summary, "positioning": d.profile.positioning, "differentiators": d.profile.differentiators} if d.profile else None,
        "keywords": len(d.analysed), "priorities": dict(prio), "buckets": dict(buckets),
        "clusters": [{"name": c["cluster"].name, "volume": c["cluster"].total_volume, "kd": c["kd_range"], "intent": c["cluster"].intent, "priority": c["cluster"].priority} for c in clusters],
        "own_rankings": [{"keyword": k.term, "position": r.position, "url": r.url} for k, r in own[:10]],
        "competitors": {dom: sorted(v)[:3] for dom, v in sorted(comp.items(), key=lambda x: -len(x[1]))[:8]},
        "top_p1": [{"keyword": k.term, "volume": d.vol(k), "kd": d.kd(k), "intent": a.intent, "location": k.term_location} for a, k in p1],
        "pages": [{"url": pg.url, "type": pg.page_type, "primary": d.primary(pg).term if d.primary(pg) else ""} for pg in d.pages if pg.page_type != "article"],
        "roadmap": [{"month": r.month.isoformat(), "title": r.title, "type": r.content_type} for r in d.roadmap],
    }
    t_hu = L == "hu"
    content = {
        "title": ("SEO stratégia" if t_hu else "SEO Strategy"),
        "subtitle": f"{p.client.name} · {p.domain}",
        "chip": p.domain.upper(),
        "sections": [
            section("executive_summary", "Vezetői összefoglaló" if t_hu else "Executive summary", [
                {"type": "kpis", "items": [
                    {"label": "Elemzett kulcsszó" if t_hu else "Keywords analysed", "value": num(len(d.analysed))},
                    {"label": "P1 fókusz" if t_hu else "P1 focus", "value": num(prio.get("P1", 0))},
                    {"label": "Tervezett oldal" if t_hu else "Planned pages", "value": num(len([x for x in d.pages if x.page_type != 'article']))},
                    {"label": "Új tartalom" if t_hu else "New content", "value": num(len(d.roadmap)), "hint": f"{p.strategy_months} {'hónap' if t_hu else 'months'}"},
                ]},
            ]),
            section("current_situation", "Jelenlegi helyzet" if t_hu else "Current situation", [
                T(["Kulcsszó" if t_hu else "Keyword", "Pozíció" if t_hu else "Position", "URL"], [[k.term, r.position, r.url] for k, r in own[:10]])
            ] if own else []),
            section("market_opportunity", "Kereslet és verseny" if t_hu else "Demand and competition", [
                T(["Keresési klaszter" if t_hu else "Search cluster", "Havi keresés" if t_hu else "Monthly searches", "Nehézség" if t_hu else "Difficulty", "Stratégiai szerep" if t_hu else "Strategic role"],
                  [[c["cluster"].name, num(c["cluster"].total_volume), "KD " + c["kd_range"], (c["target"].url if c["target"] else lbl("intent", c["cluster"].intent, L))] for c in clusters]),
                P("A számok közeli kulcsszóváltozatokat is tartalmaznak; nem összeadható, garantált forgalmi ígéretek." if t_hu else
                  "Figures include close keyword variants; they are directional, not guaranteed traffic."),
            ]),
            section("competitors", "Versenytársak" if t_hu else "Competitors", [
                T(["Versenytárs" if t_hu else "Competitor", "Top 10 pozíciók a kutatásban" if t_hu else "Top-10 positions in research", "Példák" if t_hu else "Examples"],
                  [[dom, len(v), "; ".join(f"{t} #{pos}" for pos, t, loc in sorted(v)[:3])] for dom, v in sorted(comp.items(), key=lambda x: -len(x[1]))[:8]])
            ] if comp else []),
            section("search_analysis", "Keresési szándék" if t_hu else "Search intent", [
                {"type": "kpis", "items": [{"label": lbl("bucket", b, L), "value": num(n)} for b, n in buckets.most_common()]},
            ]),
            section("keyword_opportunities", "Kiemelt kulcsszólehetőségek" if t_hu else "Priority keyword opportunities", [
                T(["Kulcsszó" if t_hu else "Keyword", "Volumen" if t_hu else "Volume", "KD", "Szándék" if t_hu else "Intent", "Céloldal" if t_hu else "Target page"],
                  [[k.term, num(d.vol(k)), d.kd(k), lbl("intent", a.intent, L), (d.page_of[k.id][0].url if k.id in d.page_of else "–")] for a, k in p1])
            ]),
            section("website_structure", "Oldalstruktúra" if t_hu else "Website structure", [
                T(["URL", "Fókusz" if t_hu else "Focus", "Szerep" if t_hu else "Role", "Feladat" if t_hu else "Purpose"],
                  [[pg.url, (d.primary(pg).term if d.primary(pg) else "–"), lbl("page_type", pg.page_type, L), pg.seo_goal]
                   for pg in d.pages if pg.page_type not in ("article", "case_study")]),
            ]),
            section("content_roadmap", "Tartalmi roadmap" if t_hu else "Content roadmap", [
                T(["Hónap" if t_hu else "Month", "Tartalom" if t_hu else "Content", "Típus" if t_hu else "Type", "Elsődleges kulcsszó" if t_hu else "Primary keyword"],
                  [[month_label(r.month, L), r.title, lbl("content_type", r.content_type, L), (d.kw[r.keyword_id].term if r.keyword_id in d.kw else "–")] for r in d.roadmap])
            ] if d.roadmap else []),
            section("next_steps", "Következő lépések" if t_hu else "Next steps", [
                {"type": "steps", "items": [
                    {"title": "Kulcsszókutatás jóváhagyása" if t_hu else "Approve keyword research", "text": "Megjegyzések a szakmailag nem releváns kulcsszavakhoz." if t_hu else "Comment on keywords that are not relevant to your business."},
                    {"title": "Oldalstruktúra és Figma terv" if t_hu else "Site structure and design", "text": "A jóváhagyott struktúra alapján készül a terv." if t_hu else "The design is based on the approved structure."},
                    {"title": "Tartalomstratégia jóváhagyása" if t_hu else "Approve content strategy", "text": "Saját képek és stílusbeli irányelvek bekérése." if t_hu else "We collect your images and style guidelines."},
                    {"title": "Tartalomgyártás és monitoring" if t_hu else "Content production and monitoring", "text": "Havi riport az első hónap után." if t_hu else "Monthly reporting after the first month."},
                ]},
            ]),
        ],
    }
    content["sections"] = [s for s in content["sections"] if s["blocks"] or s["key"] in ("current_situation",)]
    return facts, content


def content_strategy(d: Data, language: str) -> tuple[dict, dict]:
    p = d.project
    L = lang_of(language)
    t_hu = L == "hu"
    pillars = Counter(r.pillar or "–" for r in d.roadmap if r.content_type != "case_study")
    arts = [r for r in d.roadmap if r.content_type != "case_study"]
    cases = [r for r in d.roadmap if r.content_type == "case_study"]
    clusters = d.cluster_rows()
    facts = {
        "project": p.name, "domain": p.domain, "months": p.strategy_months, "per_month": p.content_per_month, "locations": p.locations,
        "pillars": dict(pillars), "articles": len(arts), "case_studies": len(cases),
        "clusters": [{"name": c["cluster"].name, "primary": c["head"].term, "volume": c["cluster"].total_volume, "priority": c["cluster"].priority} for c in clusters[:20]],
        "roadmap": [{"month": r.month.isoformat(), "title": r.title, "keyword": d.kw[r.keyword_id].term if r.keyword_id in d.kw else "", "priority": r.priority} for r in d.roadmap],
        "parked": [{"term": x.term, "reason": x.reason} for x in d.parked[:20]],
    }
    months = sorted({r.month for r in d.roadmap})
    period = f"{month_label(months[0], L)} – {month_label(months[-1], L)}" if months else ""
    content = {
        "title": ("Tartalomstratégia" if t_hu else "Content Strategy"),
        "subtitle": f"{p.client.name} · {p.strategy_months} {'havi terv' if t_hu else 'month plan'} · {period}",
        "chip": ("TARTALOMSTRATÉGIA" if t_hu else "CONTENT STRATEGY"),
        "sections": [
            section("summary", "A stratégia lényege" if t_hu else "Strategy at a glance", [
                {"type": "kpis", "items": [
                    {"label": "Új cikk" if t_hu else "New articles", "value": num(len(arts))},
                    {"label": "Case study", "value": num(len(cases))},
                    {"label": "Havi ritmus" if t_hu else "Monthly cadence", "value": f"{p.content_per_month}"},
                    {"label": "Időtáv" if t_hu else "Timeframe", "value": f"{p.strategy_months} {'hónap' if t_hu else 'months'}"},
                ]},
                T(["Pillar", "Cikkek" if t_hu else "Articles"], [[k, v] for k, v in pillars.most_common()]),
                {"type": "cards", "items": [
                    {"title": "P1", "text": "Erős üzleti szándék + közvetlen belső link a legerősebb szolgáltatási / városi oldalakra." if t_hu else "Strong business intent + direct internal links to the strongest service/city pages."},
                    {"title": "P2", "text": "Támogató vagy szélesebb téma; proof és topical authority építés." if t_hu else "Supporting or broader topic; builds proof and topical authority."},
                    {"title": "Fő SEO-elv" if t_hu else "Core SEO principle", "text": "A cikk információs vagy döntési kérdést válaszol meg, majd a releváns szolgáltatási oldalra vezet; nem másolja a landinget." if t_hu else "Each article answers an informational or decision question and leads to the relevant service page; it never copies the landing page."},
                    {"title": "Lokális elv" if t_hu else "Local principle", "text": "A lokáció támogató kontextus; a város + szolgáltatás kulcsszavakat a dedikált landingek célozzák." if t_hu else "Location is supporting context; city + service keywords belong to the dedicated landing pages."},
                ]},
            ]),
            section("clusters", "Kulcsszóklaszterek" if t_hu else "Keyword clusters", [
                T(["Elsődleges kulcsszó" if t_hu else "Primary keyword", "Volume", "KD", "Szándék" if t_hu else "Intent", "Pillar", "Kapcsolódó kulcsszavak" if t_hu else "Related keywords", "Klaszter keresése" if t_hu else "Cluster volume", "Céloldal" if t_hu else "Target", "Prioritás" if t_hu else "Priority"],
                  [[c["head"].term, num(d.vol(c["head"])), d.kd(c["head"]), lbl("intent", d.an[c["head"].id].intent, L), c["cluster"].pillar or c["cluster"].name,
                    "; ".join(k.term for _, k in c["members"][1:5]), num(c["cluster"].total_volume), c["target"].url if c["target"] else "–", c["cluster"].priority] for c in clusters[:25]]),
            ], narrative=False),
            section("roadmap", f"{p.strategy_months} {'havi tartalmi roadmap' if t_hu else 'month content roadmap'}", [
                T(["Hónap" if t_hu else "Month", "Prioritás" if t_hu else "Priority", "Elsődleges kulcsszó" if t_hu else "Primary keyword", "URL", "Javasolt cím" if t_hu else "Proposed title", "Tartalmi irány" if t_hu else "Direction", "Fő CTA" if t_hu else "Main CTA"],
                  [[month_label(r.month, L), r.priority, d.kw[r.keyword_id].term if r.keyword_id in d.kw else "—", r.url, r.title, r.content_direction, r.cta] for r in d.roadmap]),
            ]),
            section("measurement", "Mérési terv" if t_hu else "Measurement plan", [
                T(["Időszak" if t_hu else "Period", "Fő fókusz" if t_hu else "Focus", "Mit nézzünk?" if t_hu else "What we check", "Sikerjel" if t_hu else "Success signal", "Döntés" if t_hu else "Decision"],
                  [[m.period, m.focus, m.what, m.success_signal, m.decision] for m in d.measurement]),
            ]),
            section("parked", "Következő témák" if t_hu else "Next topics", [
                T(["Kulcsszó" if t_hu else "Keyword", "Miért nincs most a roadmapben?" if t_hu else "Why not now?", "Javasolt kezelés" if t_hu else "Recommended handling"], [[x.term, x.reason, x.recommended_handling] for x in d.parked[:25]]),
            ] if d.parked else [], narrative=False),
        ],
    }
    content["sections"] = [s for s in content["sections"] if s["blocks"]]
    return facts, content


def roadmap_doc(d: Data, language: str) -> tuple[dict, dict]:
    p = d.project
    L = lang_of(language)
    t_hu = L == "hu"
    by_month = defaultdict(list)
    for r in d.roadmap:
        by_month[r.month].append(r)
    facts = {"project": p.name, "items": [{"month": r.month.isoformat(), "title": r.title, "type": r.content_type} for r in d.roadmap]}
    sections = [section("intro", "Ütemezés" if t_hu else "Schedule", [])]
    for m in sorted(by_month):
        sections.append(section(f"m{m.isoformat()}", month_label(m, L), [
            {"type": "cards", "items": [{"title": r.title, "text": lbl("content_type", r.content_type, L) + f" · {r.priority}" + (f" · {d.kw[r.keyword_id].term}" if r.keyword_id in d.kw else "")} for r in by_month[m]]}
        ], narrative=False))
    return facts, {"title": "Tartalmi roadmap" if t_hu else "Content roadmap", "subtitle": f"{p.client.name} · {p.domain}", "chip": "ROADMAP", "sections": sections}


def wireframe_deck(d: Data, language: str) -> tuple[dict, dict]:
    p = d.project
    t_hu = lang_of(language) == "hu"
    L = lang_of(language)
    pages = [pg for pg in d.pages if pg.id in d.wireframes]
    facts = {"project": p.name, "pages": [{"url": pg.url, "h1": pg.h1, "goal": pg.seo_goal, "flow": d.wireframes[pg.id].flow, "v": d.wireframes[pg.id].version} for pg in pages]}
    sections = [section("intro", "Mit építünk, és miért?" if t_hu else "What we are building and why", [])]
    for pg in pages:
        w = d.wireframes[pg.id]
        prim = d.primary(pg)
        sections.append(section(f"page{pg.id}", pg.h1 or pg.url, [
            T(["URL", "Oldaltípus" if t_hu else "Page type", "Elsődleges kulcsszó" if t_hu else "Primary keyword", "Fő CTA" if t_hu else "Main CTA"],
              [[pg.url, lbl("page_type", pg.page_type, L), prim.term if prim else "–", pg.cta_label]]),
            {"type": "callout", "title": "SEO-cél" if t_hu else "SEO purpose", "text": pg.seo_goal},
            T(["#", "Blokk" if t_hu else "Section", "Feladat" if t_hu else "Purpose"], [[i + 1, s.get("h2", ""), s.get("goal", "")] for i, s in enumerate(w.sections)]),
        ], narrative=False))
    return facts, {"title": "Wireframe-ek" if t_hu else "Wireframes", "subtitle": f"{p.client.name} · {len(pages)} {'oldal' if t_hu else 'pages'}", "chip": "WIREFRAME", "sections": sections}


# ── Belső dokumentumok ───────────────────────────────────

TECH_REQUIREMENTS = [
    "Oldalanként egyetlen H1, a jóváhagyott szöveggel; logikus H2/H3 hierarchia.",
    "Egyedi SEO title (legfeljebb ~60 karakter / 561 px) és meta description (kb. 120–155 karakter) minden indexelhető oldalon.",
    "Önhivatkozó canonical minden indexelhető oldalon; paraméteres és duplikált URL-ek canonicallal vagy 301-gyel rendezve.",
    "XML sitemap csak indexelhető, 200-as URL-ekkel; a régi / átirányított URL-ek kerüljenek ki.",
    "Átirányítás egy lépésben (301), lánc és loop nélkül; a belső linkek közvetlenül a cél URL-re mutassanak.",
    "Strukturált adat oldaltípus szerint (lásd a táblában), Rich Results Testtel ellenőrizve.",
    "Képek: WebP/AVIF, reszponzív méretek, width/height attribútum, leíró fájlnév és alt szöveg; 100 KB feletti képek optimalizálása.",
    "Mobilbarát működés, Core Web Vitals alapellenőrzés (LCP, CLS, INP); lazy loading a hajtás alatti képekre.",
    "GA4 és Search Console bekötve; konverziós események (űrlap, telefon, CTA) mérése.",
    "Publikálás után indexelés kérése a Search Console-ban.",
]


def dev_brief(d: Data, language: str) -> tuple[dict, dict]:
    p = d.project
    rows, redirects, links_rows = [], [], []
    for pg in d.pages:
        prim = d.primary(pg)
        rows.append([pg.url, PAGE_TYPES.get(pg.page_type, pg.page_type), prim.term if prim else "–", pg.h1, pg.seo_title, ", ".join(pg.schema_types or []), pg.cta_label + (" → " + pg.cta_url if pg.cta_url else "")])
        if pg.lifecycle in ("redirect", "merge"):
            redirects.append([pg.url, "301 – egy lépésben", pg.redirect_to or "–", "A cél 200-as; a régi URL nincs a sitemapben; a belső linkek közvetlenül a célra mutatnak."])
        if pg.lifecycle == "remove":
            redirects.append([pg.url, "410 vagy 301 a legközelebbi oldalra", pg.redirect_to or "–", "A régi URL nem indexelődik, nincs belső link rá."])
    for lk in d.links:
        frm = d.page_by_id.get(lk.from_page_id)
        if frm:
            links_rows.append([frm.url, lk.anchor, lk.to_url, {"menu": "Menü", "button": "Gomb", "card": "Kártya", "text": "Szöveglink"}.get(lk.link_type, lk.link_type), lk.placement])
    facts = {"project": p.name, "domain": p.domain, "pages": rows, "redirects": redirects, "links": len(links_rows)}
    content = {
        "title": f"{p.domain} – SEO fejlesztői teendők",
        "subtitle": "Belső dokumentum · a jóváhagyott kulcsszókutatás, struktúra és wireframe-ek alapján",
        "chip": "FEJLESZTŐI BRIEF",
        "sections": [
            section("decision_basis", "Döntési alap", []),
            section("url_structure", "SEO URL-struktúra és oldalanként teendők", [
                T(["URL", "Oldaltípus", "Elsődleges kulcsszó", "H1", "SEO title", "Schema", "CTA"], rows)], narrative=False),
            section("redirects", "Átirányítások", [T(["Forrás URL", "Művelet", "Cél URL", "Késznek akkor tekinthető"], redirects)] if redirects else [P("Nincs átirányítandó oldal a struktúrában.")], narrative=False),
            section("internal_links", "Belső linkek", [T(["Honnan", "Anchor", "Hova", "Típus", "Hol"], links_rows)], narrative=False),
            section("technical", "Technikai követelmények", [B(TECH_REQUIREMENTS)], narrative=False),
            section("implementation_notes", "Kivitelezési megjegyzések", []),
        ],
    }
    return facts, content


def writer_brief(d: Data, language: str) -> tuple[dict, dict]:
    p = d.project
    mapping = []
    for pg in d.pages:
        prim = d.primary(pg)
        if not prim:
            continue
        a = d.an.get(prim.id)
        mapping.append([pg.title or pg.h1, pg.url, f"{prim.term} ({num(d.vol(prim))})", "; ".join(f"{k.term} ({num(d.vol(k))})" for k in d.secondary(pg)[:8]),
                        INTENTS.get(a.intent, "") if a else "", pg.h1])
    style_rows = []
    if d.style:
        for key, question, _ in STYLE_QUESTIONS:
            ans = (d.style.answers or {}).get(key)
            if ans:
                style_rows.append([question, ans])
    non_target = [[x.term, x.reason] for x in d.parked[:20]] + [[k.term, "Navigációs / márkakeresés"] for a, k in d.analysed if a.intent == "navigational"][:10]
    cannibal = [[c["cluster"].name, c["cluster"].cannibalization_rule or "Egy elsődleges kulcsszó = egy URL; a cikk nem másolja a landinget."] for c in d.cluster_rows(20)]
    items = [[month_label(r.month, "hu"), r.title, r.url, d.kw[r.keyword_id].term if r.keyword_id in d.kw else "–", r.cta, ("wireframe kész" if r.page_id in d.wireframes else "–")] for r in d.roadmap]
    facts = {"project": p.name, "mapping": mapping, "style": style_rows, "language": p.content_language, "items": len(items)}
    content = {
        "title": f"{p.domain} – oldalankénti SEO keyword mapping",
        "subtitle": f"Belső dokumentum · {len(d.analysed)} elemzett kulcsszó alapján",
        "chip": "SZÖVEGÍRÓI BRIEF",
        "sections": [
            section("principle", "Fontos mapping-elv", [
                {"type": "callout", "title": "Mapping-elv", "text": "A primary kulcsszó nem minden esetben a legnagyobb volumenű kifejezés. Szolgáltatási oldalnál a keresési szándék elsőbbséget élvez."}]),
            section("writing_rules", "Általános szövegírói szabályok", [
                B(["Minden landing egyetlen H1-et használjon; a primary kifejezés kerüljön a bevezetőbe, és természetesen jelenjen meg egy releváns H2-ben vagy FAQ-ban is.",
                   "A szolgáltatási landing ne legyen lexikoncikk: a nagy információs témák külön cikket kapnak.",
                   "Ne ígérjünk helyezést vagy garantált eredményt; csak ellenőrzött proof.",
                   "A linkszöveg a látogatónak legyen érthető; ne linkeljünk minden exact-match kulcsszót.",
                   "Tartalmi nyelv: " + {"hu": "magyar", "en-US": "amerikai angol", "en-GB": "brit angol"}.get(p.content_language, p.content_language) + "."])
            ] + ([T(["Stílusbeli irányelv", "Ügyfél válasza"], style_rows)] if style_rows else [])),
            section("mapping", "Kulcsszó-mapping oldalanként", [T(["Oldal", "URL", "Primary kulcsszó", "Secondary / támogató kifejezések", "Intent", "Javasolt H1"], mapping)], narrative=False),
            section("non_target", "Kifejezetten nem célzott kulcsszavak", [T(["Kulcsszó", "Miért nem"], non_target)] if non_target else [P("Nincs ilyen kulcsszó.")], narrative=False),
            section("cannibalization", "Kannibalizációs szabályok", [T(["Klaszter", "Szabály"], cannibal)], narrative=False),
            section("content_plan", "Tartalmi feladatok", [T(["Hónap", "Cím", "URL", "Primary", "CTA", "Wireframe"], items)] if items else [], narrative=False),
        ],
    }
    content["sections"] = [s for s in content["sections"] if s["blocks"] or s["narrative"]]
    return facts, content


IMAGE_RULES = [
    "Fájlnév: kisbetű, ékezet nélkül, kötőjellel, leíró és rövid (pl. konyha-felujitas-naples.webp); ne maradjon benne „final”, „v2”.",
    "Formátum: fotó AVIF/WebP (JPEG fallback), átlátszó kép WebP/PNG, ikon és logó SVG.",
    "Webes export külön munkafázis: nem azonos a nyomdai vagy social exporttal; 100 KB fölötti kép csak indokolt esetben.",
    "Reszponzív változatok a fejlesztő kérése szerint (-480, -960, -1600 utótag); mobilos vágás a kritikus komponensekhez.",
    "Szöveg ne legyen képbe égetve; az alt szöveget a szövegíró véglegesíti.",
]


def designer_brief(d: Data, language: str) -> tuple[dict, dict]:
    p = d.project
    audience = (d.profile.audiences if d.profile else []) or ([{"name": p.target_audience}] if p.target_audience else [])
    rows = []
    for pg in d.pages:
        w = d.wireframes.get(pg.id)
        rows.append([pg.url, pg.seo_goal, " → ".join(w.flow) if w else "–", (w.ux_notes if w and w.ux_notes else "–"), (w.visual_sequence if w and w.visual_sequence else "–")])
    facts = {"project": p.name, "audience": audience, "pages": rows, "differentiators": d.profile.differentiators if d.profile else []}
    content = {
        "title": f"{p.domain} – grafikusi brief",
        "subtitle": "Belső dokumentum · oldalcélok, szakaszok, UX- és képi irány",
        "chip": "GRAFIKUSI BRIEF",
        "sections": [
            section("audience", "Célcsoport és vizuális irány", [B([a.get("name", "") + (" – " + a["needs"] if a.get("needs") else "") for a in audience])] if audience else []),
            section("pages", "Oldalanként", [T(["URL", "Oldal célja", "Kötelező szakaszok", "UX-megjegyzés", "Vizuális sorrend"], rows)], narrative=False),
            section("images", "Kép- és exportszabályok", [B(IMAGE_RULES)], narrative=False),
        ],
    }
    return facts, content


def seo_checklist(d: Data, language: str) -> tuple[dict, dict]:
    p = d.project
    checks = ["Egyedi SEO title", "Egy H1", "60–90 szavas bevezető", "Fő CTA", "Proof az első két képernyőn", "5+ valódi FAQ", "Belső linkek a wireframe szerint",
              "Schema", "Alt szövegek", "Meta description", "Indexelhető, canonical rendben", "Indexelés kérése"]
    rows = [[pg.url, PAGE_TYPES.get(pg.page_type, pg.page_type)] + ["☐"] * len(checks) for pg in d.pages if pg.page_type not in ("support",)]
    facts = {"project": p.name, "pages": [r[0] for r in rows]}
    return facts, {"title": f"{p.domain} – SEO publikálási checklist", "subtitle": "Oldal nem élesedik a minimum teljesülése nélkül", "chip": "SEO CHECKLIST",
                   "sections": [section("checklist", "Publikálási minimum oldalanként", [T(["URL", "Típus"] + checks, rows)], narrative=False)]}


def tech_audit(d: Data, language: str) -> tuple[dict, dict]:
    from . import audit as audit_service
    from . import audit_rules as AR

    p = d.project
    ov = audit_service.overview(d.db, p.id)
    topics = [t for t in ov["topics"] if t["included"] and (any(f["count"] for f in t["findings"]) or t["observation"] or t["metrics"])]
    by_size = {sz: [t["title"] for t in topics if t["size"] == sz] for sz in ("XL", "M", "S")}
    depth = max((len(v) for v in by_size.values()), default=0)
    facts = {
        "domain": p.domain,
        "topics": [{"topic": t["topic"], "size": t["size"], "findings": [(f["issue_key"], f["count"]) for f in t["findings"] if f["count"]],
                    "observation": t["observation"], "recommendation": t["recommendation"], "metrics": t["metrics"]} for t in topics],
    }
    sections = [section("priorities", "Prioritási lista", [
        P("A feltárt hibákat fontosság szerint, a pólóméretekhez hasonló kategóriákba soroltuk. Minél nagyobb a méret, annál kritikusabb a hiba, annál sürgetőbb a javítása."),
        T(["XL", "M", "S"], [[by_size[sz][i] if i < len(by_size[sz]) else "" for sz in ("XL", "M", "S")] for i in range(depth)]),
    ], narrative=True)]
    for t in topics:
        meta = AR.TOPICS[t["topic"]]
        seen = [f["label"] for f in t["findings"] if f["count"]]
        if t["topic"] == "speed" and t["metrics"]:
            m = t["metrics"]
            if m.get("mobile") is not None:
                seen.insert(0, f"A Google PageSpeed Insights mobil pontszáma {m['mobile']}/100" + (f", asztali {m['desktop']}/100." if m.get("desktop") is not None else "."))
            if m.get("cwv_pass") is False:
                seen.append("Az oldal jelenleg nem teljesíti a Core Web Vitals elvárásait.")
        blocks = [P(meta["intro"]), {"type": "subhead", "text": "Ideális esetben:"}, B(meta["ideal"]),
                  {"type": "subhead", "text": "Miért fontos?"}, P(meta["why"]),
                  {"type": "subhead", "text": f"Mit látunk a(z) {p.domain} esetében?"}]
        if seen:
            blocks.append(B(seen))
        if t["observation"]:
            blocks.append(P(t["observation"]))
        blocks.append({"type": "narrative_slot"})
        blocks += [{"type": "subhead", "text": "Fejlesztési javaslatok"}, P(t["recommendation"] or meta["recommendation"])]
        sample = [[u["url"], f["label"].split(" ", 1)[1] if f["label"][:1].isdigit() else f["label"], u.get("detail", "")]
                  for f in t["findings"] if f["count"] for u in f["sample"][:3]]
        if sample:
            blocks.append(T(["Példa URL", "Megállapítás", "Részlet"], sample[:12]))
        sections.append(section(t["topic"], f"{meta['title']} ({t['size']})", blocks, narrative=True))
    content = {"title": "Technikai SEO audit", "subtitle": f"{p.client.name} · {p.domain}", "chip": "TECHNIKAI SEO AUDIT", "sections": sections}
    return facts, content


def _period(d: Data) -> tuple[date, date]:
    raw = getattr(d, "period", None)
    if raw:
        y, m = (int(x) for x in raw.split("-")[:2])
        start = date(y, m, 1)
    else:
        first = date.today().replace(day=1)
        start = (first - timedelta(days=1)).replace(day=1)  # az előző hónap
    end = (start.replace(day=28) + timedelta(days=4)).replace(day=1)
    return start, end


def monthly_report(d: Data, language: str) -> tuple[dict, dict]:
    from sqlalchemy import and_

    from ..models import AuditFinding, ProductionTask
    from ..models.documents import TASK_ROLES

    p = d.project
    L = lang_of(language)
    hu = L == "hu"
    start, end = _period(d)
    in_month = lambda ts: ts is not None and start <= ts.date() < end  # noqa: E731
    tasks = d.db.scalars(select(ProductionTask).where(ProductionTask.project_id == p.id, ProductionTask.status == "done")).all()
    done = [t for t in tasks if in_month(t.updated_at)]
    published = [r for r in d.roadmap if r.status == "published" and in_month(r.updated_at)]
    fixed = d.db.scalars(select(AuditFinding).where(and_(AuditFinding.project_id == p.id, AuditFinding.status == "fixed"))).all()
    fixed = [f for f in fixed if in_month(f.updated_at)]
    next_start = end
    upcoming = [r for r in d.roadmap if r.month == next_start]
    own = sorted([(k, r) for _, k in d.analysed for r in k.rankings if r.is_own and r.position], key=lambda x: x[1].position)
    top3 = sum(1 for _, r in own if r.position <= 3)
    top10 = sum(1 for _, r in own if r.position <= 10)
    label = month_label(start, L)
    facts = {"project": p.name, "domain": p.domain, "period": start.isoformat(), "done": [(t.role, t.title) for t in done],
             "published": [r.title for r in published], "fixed": [(f.issue_key, f.previous_count) for f in fixed],
             "top3": top3, "top10": top10, "rankings": [(k.term, r.position) for k, r in own[:20]], "next": [r.title for r in upcoming]}
    from . import audit_rules as AR

    content = {
        "title": ("Havi SEO riport" if hu else "Monthly SEO report"), "subtitle": f"{p.client.name} · {p.domain} · {label}", "chip": label.upper(),
        "sections": [
            section("summary", "Összefoglaló" if hu else "Summary", [{"type": "kpis", "items": [
                {"label": "Elvégzett feladat" if hu else "Tasks completed", "value": num(len(done))},
                {"label": "Megjelent tartalom" if hu else "Content published", "value": num(len(published))},
                {"label": "Javított technikai hiba" if hu else "Technical fixes", "value": num(len(fixed))},
                {"label": "Top 10 kulcsszó" if hu else "Top-10 keywords", "value": num(top10), "hint": (f"ebből top 3: {top3}" if hu else f"top 3: {top3}")},
            ]}]),
            section("search_console", "Keresési teljesítmény" if hu else "Search performance", [
                {"type": "callout", "title": "Google Search Console", "text": (
                    "A kattintások, megjelenések és átlagos pozíció havi alakulása a Search Console bekötése után automatikusan itt jelenik meg. Addig a mérési terv szerinti számokat a SEO manager adja meg."
                    if hu else "Clicks, impressions and average position will appear here automatically once Search Console is connected.")},
            ], narrative=False),
            section("work", "Elvégzett munka" if hu else "Work completed", [
                T(["Terület" if hu else "Area", "Feladat" if hu else "Task", "URL"], [[TASK_ROLES.get(t.role, t.role), t.title, t.target_url or t.source_url] for t in done])
            ] if done else [P("Ebben a hónapban nem zárult feladat." if hu else "No tasks were closed this month.")]),
            section("content", "Megjelent tartalmak" if hu else "Published content", [
                T(["Cím" if hu else "Title", "URL", "Elsődleges kulcsszó" if hu else "Primary keyword"], [[r.title, r.url, d.kw[r.keyword_id].term if r.keyword_id in d.kw else "–"] for r in published])
            ] if published else []),
            section("technical", "Technikai javítások" if hu else "Technical fixes", [
                T(["Téma" if hu else "Topic", "Megállapítás" if hu else "Finding", "Korábban" if hu else "Before"],
                  [[AR.TOPICS[f.topic]["title"], AR.label_for(f.issue_key, f.previous_count or 0), f.previous_count] for f in fixed])
            ] if fixed else []),
            section("rankings", "Helyezések" if hu else "Rankings", [
                T(["Kulcsszó" if hu else "Keyword", "Pozíció" if hu else "Position", "URL"], [[k.term, r.position, r.url] for k, r in own[:20]])
            ] if own else []),
            section("next", "Következő hónap" if hu else "Next month", [
                T(["Tartalom" if hu else "Content", "Típus" if hu else "Type", "Prioritás" if hu else "Priority"], [[r.title, lbl("content_type", r.content_type, L), r.priority] for r in upcoming])
            ] if upcoming else []),
        ],
    }
    content["sections"] = [s for s in content["sections"] if s["blocks"]]
    return facts, content


BUILDERS = {
    "seo_strategy": seo_strategy,
    "content_strategy": content_strategy,
    "roadmap": roadmap_doc,
    "wireframe_deck": wireframe_deck,
    "dev_brief": dev_brief,
    "writer_brief": writer_brief,
    "designer_brief": designer_brief,
    "seo_checklist": seo_checklist,
    "tech_audit": tech_audit,
    "monthly_report": monthly_report,
}


def build(db: Session, project: Project, doc_type: str, language: str, period: Optional[str] = None) -> tuple[dict, dict, Data]:
    d = Data(db, project)
    d.period = period
    facts, content = BUILDERS[doc_type](d, language)
    return facts, content, d
