"""Kulcsszó-prioritás: determinisztikus, megmagyarázható pontszám a módszertan sorrendjében
(szándék → üzleti érték → kereskedelmi lehetőség → verseny → volumen). A súlyok a Beállításokban módosíthatók.
A modell csak osztályoz; a rangsort ez a kód adja.
"""

import math
from dataclasses import dataclass
from typing import Optional

from .heuristics import INTENT_BASE


@dataclass
class Score:
    score: float
    priority: str
    reason: str


def intent_fit(intent: str, local: bool, has_locations: bool) -> float:
    base = INTENT_BASE.get(intent, 0.5)
    if local and has_locations and intent in ("commercial", "transactional", "commercial_investigation"):
        base = min(1.0, base + 0.1)
    return base


def score(*, intent: str, local: bool, has_locations: bool, business_value: int, commercial_opportunity: int,
          kd: Optional[int], volume: Optional[int], max_volume: int, weights: dict, thresholds: dict,
          excluded: bool = False) -> Score:
    if excluded:
        return Score(0.0, "", "Kizárt kulcsszó.")
    parts = {
        "intent": intent_fit(intent, local, has_locations),
        "business_value": (business_value - 1) / 4,
        "commercial": (commercial_opportunity - 1) / 4,
        "ease": 1 - (kd / 100) if kd is not None else 0.5,
        "volume": (math.log10((volume or 0) + 1) / math.log10(max_volume + 1)) if max_volume > 0 else 0,
    }
    total = sum(parts[k] * float(weights.get(k, 0)) for k in parts)
    wsum = sum(float(v) for v in weights.values()) or 1
    s = round(total / wsum, 4)
    p1, p2 = float(thresholds.get("p1", 0.62)), float(thresholds.get("p2", 0.42))
    priority = "P1" if s >= p1 else ("P2" if s >= p2 else "parked")
    reasons = []
    # Kemény szabályok: üzletileg értéktelen vagy navigációs kulcsszó nem kerül a fókuszba.
    if business_value <= 1:
        priority = "parked"
        reasons.append("nincs üzleti relevancia")
    if intent == "navigational":
        priority = "parked"
        reasons.append("navigációs (márka) keresés")
    if not reasons:
        strongest = max(parts, key=lambda k: parts[k] * float(weights.get(k, 0)))
        label = {"intent": "keresési szándék", "business_value": "üzleti érték", "commercial": "kereskedelmi lehetőség",
                 "ease": "alacsony verseny", "volume": "keresési volumen"}[strongest]
        reasons.append(f"fő tényező: {label}")
        if kd is not None and kd >= 60:
            reasons.append(f"erős verseny (KD {kd})")
    return Score(s, priority, "; ".join(reasons))
