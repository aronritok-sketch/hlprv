"""Szabályalapú elemzés (angol és magyar kifejezésekre). Két szerepe van:
  1. API kulcs nélkül is működik vele a rendszer (az eredmény „heurisztikus” jelölést kap);
  2. az AI-elemzés előtt determinisztikus alapot ad (Ahrefs intents, módosítók, lokáció).
"""

import re
from collections import defaultdict
from typing import Any, Optional

from .importers import normalize_term

# Módosítók → mit jeleznek (a sorrend számít: az első találat dönt).
PATTERNS: list[tuple[str, str]] = [
    # Összehasonlító / vizsgálódó
    (r"\b(best|top|vs|versus|compare|comparison|alternatives?|reviews?|rated|pricing|packages?|cost of|how much)\b", "commercial_investigation"),
    (r"\b(legjobb|top|vélemény\w*|összehasonlít\w*|árak|mennyibe kerül|árlista|csomag\w*)\b", "commercial_investigation"),
    # Tranzakciós
    (r"\b(buy|order|hire|book|quote|get a quote|free consultation|shop)\b", "transactional"),
    (r"\b(vásárl\w*|rendel\w*|árajánlat\w*|foglal\w*|megrendel\w*|webshop)\b", "transactional"),
    # Probléma
    (r"\b(fix|repair|problem|problems|issue|issues|not working|broken|leak\w*|damage\w*|mistakes?|signs?)\b", "problem"),
    (r"\b(javítás\w*|hiba\w*|probléma\w*|nem működik|beázás\w*|nedves\w*|penész\w*|elakadás\w*|kiégés)\b", "problem"),
    # Információs
    (r"^(how|what|why|when|where|which|who|can|does|is|are)\b", "informational"),
    (r"\b(how to|guide|tips|ideas|examples?|checklist|meaning|definition|tutorial|diy|trends?)\b", "informational"),
    (r"^(hogyan|mi az|mit|miért|mikor|hol|melyik|mennyi)\b", "informational"),
    (r"\b(ötlet\w*|tipp\w*|példa|példák|jelentése|útmutató|trend\w*|hogyan)\b", "informational"),
    # Kereskedelmi (szolgáltatáskereső)
    (r"\b(agency|agencies|company|companies|services?|contractors?|consultant|consulting|firm|specialists?|experts?|installers?|near me)\b", "commercial"),
    (r"\b(ügynökség\w*|cég\w*|szolgáltatás\w*|vállalkozó\w*|tanácsad\w*|szakember\w*|kivitelez\w*|mester\w*|kísérés\w*)\b", "commercial"),
]

COMPARISON = re.compile(r"\b(best|top|vs|versus|compare|comparison|alternatives?|reviews?|legjobb|vélemény\w*|összehasonlít\w*)\b")
MODIFIERS = ["near me", "best", "top", "vs", "cost", "price", "pricing", "cheap", "affordable", "how to", "ideas", "reviews",
             "legjobb", "ár", "árak", "olcsó", "hogyan", "vélemények", "közelben"]

INTENT_BASE = {
    "transactional": 1.0,
    "commercial": 1.0,
    "commercial_investigation": 0.8,
    "problem": 0.6,
    "mixed": 0.6,
    "informational": 0.45,
    "navigational": 0.1,
}
OPPORTUNITY_BASE = {"transactional": 5, "commercial": 4, "commercial_investigation": 3, "problem": 3, "mixed": 3, "informational": 2, "navigational": 1}

STOP = {"the", "a", "an", "for", "and", "of", "in", "to", "on", "with", "my", "your", "near", "me", "best", "top",
        "a", "az", "és", "egy", "hogy", "is", "de", "vagy", "–", "-", "&"}


def ahrefs_intent(source_intents: list[str]) -> Optional[str]:
    s = {x.strip().lower() for x in source_intents or []}
    if not s:
        return None
    if "navigational" in s or "branded" in s:
        return "navigational"
    if "transactional" in s and "commercial" in s:
        return "transactional" if "informational" not in s else "commercial_investigation"
    if "commercial" in s and "informational" in s:
        return "commercial_investigation"
    if "commercial" in s:
        return "commercial"
    if "transactional" in s:
        return "transactional"
    if "informational" in s:
        return "informational"
    return None


def classify_intent(term: str, source_intents: list[str], competitor_brands: list[str] = ()) -> str:
    t = normalize_term(term)
    for brand in competitor_brands:
        if brand and len(brand) > 3 and brand in t.replace(" ", ""):
            return "navigational"
    hits = [intent for pattern, intent in PATTERNS if re.search(pattern, t)]
    ah = ahrefs_intent(source_intents)
    if hits:
        first = hits[0]
        # A szolgáltatásnév + „best/top” típusú keresés vizsgálódó; a „near me” kereskedelmi.
        if "near me" in t or "közelben" in t:
            return "commercial"
        if first == "informational" and ah in ("commercial", "transactional") and "commercial" in hits:
            return "commercial_investigation"
        return first
    if ah:
        return ah
    # Rövid, szolgáltatásszerű főnévi kifejezés („kitchen remodeling naples”): kereskedelmi.
    return "commercial" if len(t.split()) <= 4 else "informational"


def is_local(term: str, term_location: str, source_intents: list[str]) -> bool:
    t = normalize_term(term)
    return bool(term_location) or "near me" in t or "közelben" in t or "local" in {x.lower() for x in source_intents or []}


def modifiers(term: str) -> list[str]:
    t = " " + normalize_term(term) + " "
    return [m for m in MODIFIERS if f" {m} " in t]


def bucket(intent: str, local: bool, mods: list[str], term: str) -> str:
    if intent == "problem":
        return "problem"
    if COMPARISON.search(normalize_term(term)) or intent == "commercial_investigation" and any(m in mods for m in ("best", "top", "vs", "legjobb")):
        return "comparison"
    if intent in ("commercial", "transactional", "commercial_investigation"):
        return "local" if local else "commercial"
    return "informational"


def tokens(s: str) -> set[str]:
    return {w for w in re.findall(r"[\wáéíóöőúüű]+", normalize_term(s)) if w not in STOP and len(w) > 1}


def stem(w: str) -> str:
    """Nagyon egyszerű szótövezés (angol többes szám, magyar ragok egy része)."""
    for suf in ("ing", "ers", "er", "es", "s", "ek", "ok", "ök", "ban", "ben", "nak", "nek", "hoz", "hez", "ról", "ről"):
        if len(w) > len(suf) + 3 and w.endswith(suf):
            return w[: -len(suf)]
    return w


def business_value(term: str, services: list[dict], excluded: list[str], intent: str) -> int:
    t_tokens = {stem(w) for w in tokens(term)}
    best = 2 if intent in ("commercial", "transactional", "commercial_investigation") else 2
    for s in services or []:
        s_tokens = {stem(w) for w in tokens(s.get("name", ""))}
        if not s_tokens:
            continue
        overlap = len(t_tokens & s_tokens) / len(s_tokens)
        if overlap >= 0.99:
            best = max(best, 5 if (s.get("high_margin") or s.get("priority")) else 4)
        elif overlap >= 0.5:
            best = max(best, 4 if (s.get("high_margin") or s.get("priority")) else 3)
        elif overlap > 0:
            best = max(best, 3)
    if intent == "navigational":
        best = 1
    for e in excluded:
        if e and normalize_term(e) in normalize_term(term):
            best = 1
    return best


def commercial_opportunity(intent: str, cpc: Optional[float], currency: str = "") -> int:
    base = OPPORTUNITY_BASE.get(intent, 2)
    if cpc:
        usd = cpc / 360 if currency == "HUF" else cpc
        if usd >= 10:
            base += 1
        elif usd < 0.5 and base > 1:
            base -= 1
    return max(1, min(5, base))


def cluster_key(term: str, parent_topic: str, locations: list[str], services: list[dict]) -> str:
    """Csoportosítási kulcs: szolgáltatásnév → Ahrefs parent topic → a kifejezés magja (lokáció és módosítók nélkül)."""
    t = normalize_term(term)
    t_tokens = {stem(w) for w in tokens(t)}
    for s in sorted(services or [], key=lambda s: -len(s.get("name", ""))):
        s_tokens = {stem(w) for w in tokens(s.get("name", ""))}
        if s_tokens and s_tokens <= t_tokens:
            return s["name"]
    if parent_topic:
        return parent_topic
    core = t
    for loc in locations:
        core = re.sub(r"\b" + re.escape(normalize_term(loc)) + r"\b", " ", core)
    for m in MODIFIERS:
        core = re.sub(r"\b" + re.escape(m) + r"\b", " ", core)
    words = [w for w in core.split() if w not in STOP]
    return " ".join(words[-2:]) if words else t


def cluster(rows: list[dict[str, Any]], locations: list[str], services: list[dict]) -> dict[str, list[int]]:
    """rows: [{id, term, parent_topic}] → {klaszternév: [id, …]}. A kis csoportok a legközelebbi nagyobbhoz kerülnek."""
    groups: dict[str, list[int]] = defaultdict(list)
    for r in rows:
        groups[cluster_key(r["term"], r.get("parent_topic", ""), locations, services)].append(r["id"])
    big = {k for k, v in groups.items() if len(v) >= 3}
    merged: dict[str, list[int]] = defaultdict(list)
    for k, ids in groups.items():
        if k in big or not big:
            merged[k].extend(ids)
            continue
        kt = {stem(w) for w in tokens(k)}
        target = max(big, key=lambda b: len(kt & {stem(w) for w in tokens(b)}))
        if kt & {stem(w) for w in tokens(target)}:
            merged[target].extend(ids)
        else:
            merged[k].extend(ids)
    return {k.strip().title() if k.islower() else k: v for k, v in merged.items()}
