"""Promptok és JSON-sémák. A verziószám a gyorsítótár kulcsának része: ha egy prompt változik, emeld a verziót.

A rendszerpromptok a HelloProVision módszertanát rögzítik (docs/seo-os/ARCHITECTURE.md 1.4).
"""

LANG_NAMES = {"hu": "magyar", "en-US": "amerikai angol", "en-GB": "brit angol", "de": "német"}

METHOD_RULES = """A HelloProVision SEO-módszertanának szabályai (ezeket mindig tartsd be):
1. A prioritás sorrendje: keresési szándék → üzleti érték → kereskedelmi lehetőség → verseny → volumen. A volumen sosem önmagában dönt.
2. Az elsődleges kulcsszó nem mindig a legnagyobb volumenű: szolgáltatási oldalnál a szolgáltatáskereső szándék az első.
3. Egy elsődleges kereskedelmi kulcsszó = egy URL. A közeli változatok ugyanazon az oldalon kezelendők.
4. Egy oldal = egy szándék. A főoldal nem célozza a szolgáltatási oldalak kulcsszavait.
5. A város + szolgáltatás kereskedelmi kulcsszavak dedikált városi landinget kapnak; a cikkekben a lokáció csak kontextus.
6. A cikk információs vagy döntési kérdést válaszol meg, majd a szolgáltatási oldalra vezet; nem másolja a landinget.
7. A városi hub nem szolgáltatási oldal: a város szolgáltatási oldalaira irányít.
8. Minden városban ugyanazok a szolgáltatások, de egyedi helyi szöveg, problémák, iparágak, FAQ és proof.
9. Nem készül oldal, amit a kutatás nem indokol.
10. Az alternatív piaci nyelv (amit az ügyfél nem használ a szolgáltatására) nem szolgáltatási primary, csak cikkben kezelhető.
11. Csak ellenőrzött proof; nem találunk ki ügyfelet, eredményt, és nem ígérünk helyezést.
12. A linkszöveg a látogatónak legyen érthető; nincs automatikus exact-match linkelés, nincs sitewide kulcsszavas link.
"""


def project_context(project, profile=None) -> str:
    services = ", ".join(
        s.get("name", "") + (" (magas árrés)" if s.get("high_margin") else "") + (" (kiemelt)" if s.get("priority") else "")
        for s in project.business_services or []
    )
    lines = [
        f"Ügyfél: {project.client.name}",
        f"Domain: {project.domain}",
        f"Iparág: {project.industry or '–'}",
        f"Piac: {project.market}; lokációk: {', '.join(project.locations or []) or '–'}",
        f"Szolgáltatások: {services or '–'}",
        f"Célközönség: {project.target_audience or '–'}",
        f"Üzleti célok: {project.business_goals or '–'}",
        f"Konverziós célok: {project.conversion_goals or '–'}",
        f"Kizárt témák: {', '.join(project.excluded_topics or []) or '–'}",
        f"Tartalmi nyelv: {LANG_NAMES.get(project.content_language, project.content_language)}; belső munkanyelv: {LANG_NAMES.get(project.working_language, project.working_language)}",
    ]
    if profile is not None:
        lines.append(f"Pozicionálás: {profile.positioning}")
        if profile.differentiators:
            lines.append("Megkülönböztető előnyök: " + "; ".join(profile.differentiators))
    return "\n".join(lines)


# ── Üzleti profil ────────────────────────────────────────

PROFILE_VERSION = "profile-1"
PROFILE_SYSTEM = """Tapasztalt SEO-stratéga vagy egy digitális ügynökségnél (HelloProVision). Az ügyfél adataiból és a weboldala
szövegéből tömör üzleti profilt készítesz, amely a kulcsszókutatás és az oldalstruktúra alapja lesz.
Csak arra támaszkodj, ami a bemenetben szerepel; ha valami nem derül ki, hagyd üresen. A választ a belső munkanyelven írd."""
PROFILE_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "required": ["summary", "positioning", "services", "audiences", "differentiators", "proof_assets"],
    "properties": {
        "summary": {"type": "string"},
        "positioning": {"type": "string"},
        "services": {"type": "array", "items": {"type": "object", "additionalProperties": False,
                     "required": ["name", "description", "priority"],
                     "properties": {"name": {"type": "string"}, "description": {"type": "string"}, "priority": {"type": "boolean"}}}},
        "audiences": {"type": "array", "items": {"type": "object", "additionalProperties": False,
                      "required": ["name", "needs"], "properties": {"name": {"type": "string"}, "needs": {"type": "string"}}}},
        "differentiators": {"type": "array", "items": {"type": "string"}},
        "proof_assets": {"type": "array", "items": {"type": "string"}},
    },
}

# ── Kulcsszó-osztályozás ─────────────────────────────────

CLASSIFY_VERSION = "classify-2"
CLASSIFY_SYSTEM = """Tapasztalt SEO-stratéga vagy (HelloProVision). Kulcsszavakat osztályozol az ügyfél üzleti helyzete alapján.
""" + METHOD_RULES + """
Minden kulcsszóhoz add meg:
- intent: informational | commercial_investigation | commercial | transactional | problem | navigational | mixed
  (commercial = szolgáltatót keres; commercial_investigation = összehasonlít, árakat/legjobbat keres; problem = problémát ír le)
- is_local: helyi keresés-e (településnév, „near me”, helyi szolgáltatót keres)
- business_value 1–5: mennyire illik az ügyfél szolgáltatásaihoz és céljaihoz (5 = kiemelt, magas árrésű szolgáltatás;
  1 = nem releváns vagy kizárt téma, alternatív piaci nyelv szolgáltatási célként)
- commercial_opportunity 1–5: mennyire vezethet ajánlatkéréshez
- reason: egy rövid mondat a belső munkanyelven, miért így döntöttél
- translation: ha a tartalmi nyelv nem egyezik a munkanyelvvel, a kulcsszó fordítása a munkanyelvre; különben üres.
Az Ahrefs „intents” mezője segítség, de nem kötelező követni."""
CLASSIFY_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "required": ["items"],
    "properties": {
        "items": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "required": ["id", "intent", "is_local", "business_value", "commercial_opportunity", "reason", "translation"],
                "properties": {
                    "id": {"type": "integer"},
                    "intent": {"type": "string", "enum": ["informational", "commercial_investigation", "commercial", "transactional", "problem", "navigational", "mixed"]},
                    "is_local": {"type": "boolean"},
                    "business_value": {"type": "integer", "minimum": 1, "maximum": 5},
                    "commercial_opportunity": {"type": "integer", "minimum": 1, "maximum": 5},
                    "reason": {"type": "string"},
                    "translation": {"type": "string"},
                },
            },
        }
    },
}

# ── Klaszterek elnevezése ────────────────────────────────

CLUSTER_VERSION = "cluster-1"
CLUSTER_SYSTEM = """SEO-stratéga vagy (HelloProVision). Kulcsszócsoportokat nevezel el és rendelsz pillérhez.
""" + METHOD_RULES + """
Minden csoporthoz: name (rövid, a tartalmi nyelven, pl. „Kitchen Remodeling”), pillar (melyik szolgáltatási területet erősíti),
cannibalization_rule (egy mondat a munkanyelven: hogyan kerüljük el, hogy a csoport tartalma a meglévő landinggel versenyezzen)."""
CLUSTER_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "required": ["clusters"],
    "properties": {
        "clusters": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "required": ["key", "name", "pillar", "cannibalization_rule"],
                "properties": {"key": {"type": "string"}, "name": {"type": "string"}, "pillar": {"type": "string"},
                               "cannibalization_rule": {"type": "string"}},
            },
        }
    },
}

# ── Oldalstruktúra ───────────────────────────────────────

STRUCTURE_VERSION = "structure-1"
STRUCTURE_SYSTEM = """SEO-stratéga és információs építész vagy (HelloProVision). A kulcsszókutatásból oldalstruktúrát tervezel.
""" + METHOD_RULES + """
Kapsz egy vázlatos oldallistát (a módszertan oldalmodelljei alapján) és a klasztereket a legjobb kulcsszavaikkal.
Feladat: minden oldalhoz add meg a véglegesített adatokat. Az URL-ek, H1-ek, SEO title-ök a tartalmi nyelven legyenek,
az seo_goal és a notes a munkanyelven. A primary_keyword és a secondary_keywords csak a megadott kulcsszavak közül kerülhet ki,
szó szerint. Ne adj hozzá új városi vagy szolgáltatási oldalt, amit a vázlat nem tartalmaz; ha egy vázlatoldal nem indokolt, add
vissza keep=false értékkel és indokkal."""
STRUCTURE_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "required": ["pages"],
    "properties": {
        "pages": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "required": ["key", "keep", "url", "seo_title", "h1", "seo_goal", "cta_label", "primary_keyword", "secondary_keywords", "notes"],
                "properties": {
                    "key": {"type": "string"}, "keep": {"type": "boolean"}, "url": {"type": "string"},
                    "seo_title": {"type": "string"}, "h1": {"type": "string"}, "seo_goal": {"type": "string"},
                    "cta_label": {"type": "string"}, "primary_keyword": {"type": "string"},
                    "secondary_keywords": {"type": "array", "items": {"type": "string"}}, "notes": {"type": "string"},
                },
            },
        }
    },
}

# ── Tartalmi roadmap ─────────────────────────────────────

ROADMAP_VERSION = "roadmap-1"
ROADMAP_SYSTEM = """Tartalomstratéga vagy (HelloProVision). Kapsz egy havi bontású vázlatot (téma, elsődleges kulcsszó, céloldal),
és kidolgozod az egyes tételeket, ahogy a HelloProVision 6 havi tartalomstratégiája:
""" + METHOD_RULES + """
Minden tételhez: title (publikus cím a tartalmi nyelven, döntéstámogató, listicle/checklist forma ha illik),
content_direction (a munkanyelven: miről szóljon, fő blokkok „→” jellel elválasztva), cta (a tartalmi nyelven),
social_hook (Meta/Facebook kreatív fő hookja a tartalmi nyelven), cannibalization_rule (munkanyelven).
Case study tételnél ne találj ki ügyfelet vagy eredményt: a cím maradjon „[Client/Project]: [Specific Outcome]” típusú sablon."""
ROADMAP_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "required": ["items"],
    "properties": {
        "items": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "required": ["key", "title", "content_direction", "cta", "social_hook", "cannibalization_rule"],
                "properties": {"key": {"type": "string"}, "title": {"type": "string"}, "content_direction": {"type": "string"},
                               "cta": {"type": "string"}, "social_hook": {"type": "string"}, "cannibalization_rule": {"type": "string"}},
            },
        }
    },
}

# ── Wireframe ────────────────────────────────────────────

WIREFRAME_VERSION = "wireframe-1"
WIREFRAME_SYSTEM = """SEO-stratéga vagy (HelloProVision), és a szövegírónak írsz wireframe-et a saját formátumunkban.
""" + METHOD_RULES + """
A wireframe felépítése: essence (a lényeg 2–4 mondatban, benne a nyelvi utasítás), flow (blokkok sorrendje röviden),
sections (blokkonként: h2 a tartalmi nyelven; goal, what_to_write, seo_usage, link_cta a munkanyelven; length_min/length_max szóban),
links (hol → anchor → cél URL → kivitelezési megjegyzés; csak a megadott belső URL-ekre), proof_requirements, must_have és forbidden
listák, word_count_min/max. Kapsz egy sablont az oldaltípushoz: azt kövesd, de az adott témára szabd. A primary kulcsszó
természetesen szerepeljen az első 100–150 szóban; ne legyen mechanikus ismétlés."""
WIREFRAME_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "required": ["essence", "flow", "word_count_min", "word_count_max", "sections", "links", "proof_requirements", "must_have", "forbidden"],
    "properties": {
        "essence": {"type": "string"},
        "flow": {"type": "array", "items": {"type": "string"}},
        "word_count_min": {"type": "integer"},
        "word_count_max": {"type": "integer"},
        "sections": {"type": "array", "items": {"type": "object", "additionalProperties": False,
                     "required": ["h2", "goal", "what_to_write", "seo_usage", "link_cta", "length_min", "length_max"],
                     "properties": {"h2": {"type": "string"}, "goal": {"type": "string"}, "what_to_write": {"type": "string"},
                                    "seo_usage": {"type": "string"}, "link_cta": {"type": "string"},
                                    "length_min": {"type": "integer"}, "length_max": {"type": "integer"}}}},
        "links": {"type": "array", "items": {"type": "object", "additionalProperties": False,
                  "required": ["placement", "anchor", "target", "note"],
                  "properties": {"placement": {"type": "string"}, "anchor": {"type": "string"}, "target": {"type": "string"}, "note": {"type": "string"}}}},
        "proof_requirements": {"type": "string"},
        "must_have": {"type": "array", "items": {"type": "string"}},
        "forbidden": {"type": "array", "items": {"type": "string"}},
    },
}
