"""Admin által szerkeszthető beállítások. Sorrend: adatbázis → környezeti változó → alapérték."""

from typing import Any

from sqlalchemy.orm import Session

from ..config import get_settings
from ..models import AppSetting

# kulcs: (címke, titkos-e, alapérték, környezeti változó neve a Settings-ben)
DEFINITIONS: dict[str, tuple[str, bool, Any, str | None]] = {
    "openai_api_key": ("OpenAI API kulcs", True, "", "openai_api_key"),
    "openai_model": ("OpenAI modell (elemzés)", False, "gpt-4.1-mini", None),
    "openai_embedding_model": ("OpenAI embedding modell", False, "text-embedding-3-small", None),
    "anthropic_api_key": ("Anthropic (Claude) API kulcs", True, "", "anthropic_api_key"),
    "claude_model": ("Claude modell – belső briefek", False, "claude-sonnet-5", None),
    "claude_model_client": ("Claude modell – ügyféldokumentumok", False, "claude-opus-5-5", None),
    "dataforseo_login": ("DataForSEO login", False, "", "dataforseo_login"),
    "dataforseo_password": ("DataForSEO jelszó", True, "", "dataforseo_password"),
    "ahrefs_api_key": ("Ahrefs API kulcs", True, "", "ahrefs_api_key"),
    "monthly_budget_usd": ("Havi API-keret projektenként (USD)", False, 50, None),
    "llm_prices": ("Modellárak (USD / 1M token: [bemenet, kimenet])", False, {}, None),
    "scoring_weights": (
        "Prioritási súlyok",
        False,
        {"intent": 0.35, "business_value": 0.25, "commercial": 0.20, "ease": 0.12, "volume": 0.08},
        None,
    ),
    "priority_thresholds": ("Prioritási határok (P1, P2)", False, {"p1": 0.62, "p2": 0.42}, None),
    "brand_name": ("Márkanév a dokumentumokon", False, "HelloProVision", None),
}


def get(db: Session, key: str) -> Any:
    label, secret, default, env = DEFINITIONS[key]
    row = db.get(AppSetting, key)
    if row is not None and row.value not in (None, ""):
        return row.value
    if env:
        value = getattr(get_settings(), env, "")
        if value:
            return value
    return default


def set_value(db: Session, key: str, value: Any, user_id: int | None) -> None:
    if key not in DEFINITIONS:
        raise KeyError(key)
    row = db.get(AppSetting, key)
    if row is None:
        row = AppSetting(key=key, is_secret=DEFINITIONS[key][1])
        db.add(row)
    row.value = value
    row.updated_by = user_id or None


def public_view(db: Session) -> list[dict]:
    out = []
    for key, (label, secret, default, env) in DEFINITIONS.items():
        value = get(db, key)
        out.append(
            {
                "key": key,
                "label": label,
                "secret": secret,
                "is_set": bool(value),
                "value": (("••••" + str(value)[-4:]) if value else "") if secret else value,
            }
        )
    return out
