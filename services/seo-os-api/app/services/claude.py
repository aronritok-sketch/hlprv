"""Claude (Anthropic) – a dokumentumszövegek írója.

- Szigorú JSON-séma kimenet (output_config.format), így a szöveg szakaszokba rendezve érkezik.
- Adaptív gondolkodás, állítható effort.
- Streaming (hosszú kimenetnél nem fut időtúllépésbe).
- A rendszerprompt (házi stílus + referenciadokumentumok) gyorsítótárazott: ugyanazon típusú dokumentumoknál olcsóbb.
- Elutasítás (refusal) esetén a szerveroldali fallback másik modellen futtatja újra; ha az is elutasítja, hiba.
- Gyorsítótár és napló ugyanúgy, mint az OpenAI hívásoknál (llm_cache, api_logs).
"""

import json
import time
from typing import Optional

from sqlalchemy.orm import Session

from . import settings as app_settings
from .llm import LLMError, LLMUnavailable, _cached, _hash, _log, _store, claude_available

_client_factory = None  # tesztekhez felülírható: (api_key) -> kliens

FALLBACK_BETA = "server-side-fallback-2026-07-01"


def client(db: Session):
    if not claude_available(db):
        raise LLMUnavailable("Nincs Anthropic API kulcs beállítva.")
    key = app_settings.get(db, "anthropic_api_key")
    if _client_factory:
        return _client_factory(key)
    import anthropic

    return anthropic.Anthropic(api_key=key, timeout=900, max_retries=3)


def write_json(db: Session, *, name: str, version: str, system: str, user: str, schema: dict, audience: str,
               project_id: Optional[int] = None, job_id: Optional[int] = None) -> tuple[dict, str]:
    """Visszaadja a sémának megfelelő JSON-t és a használt modellt."""
    model = app_settings.get(db, "claude_model_client" if audience == "client" else "claude_model")
    effort = app_settings.get(db, "claude_effort") or "high"
    key = _hash("claude", model, effort, name, version, system, user, schema)
    hit = _cached(db, key)
    if hit is not None:
        return hit, model
    c = client(db)
    started = time.monotonic()
    try:
        with c.beta.messages.stream(
            model=model,
            max_tokens=64000,
            betas=[FALLBACK_BETA],
            fallbacks="default",
            thinking={"type": "adaptive"},
            output_config={"effort": effort, "format": {"type": "json_schema", "schema": schema}},
            system=[{"type": "text", "text": system, "cache_control": {"type": "ephemeral"}}],
            messages=[{"role": "user", "content": user}],
        ) as stream:
            message = stream.get_final_message()
    except Exception as e:  # noqa: BLE001 – az SDK a 429/5xx hibákat már újrapróbálta
        ms = int((time.monotonic() - started) * 1000)
        _log(db, project_id, job_id, "anthropic", f"messages:{name}@{version}", model, key, getattr(e, "status_code", 0) or 0, 0, 0, ms, f"{type(e).__name__}: {e}")
        db.flush()
        raise LLMError(f"A Claude hívás nem sikerült: {type(e).__name__}: {e}")
    ms = int((time.monotonic() - started) * 1000)
    usage = getattr(message, "usage", None)
    tin = (getattr(usage, "input_tokens", 0) or 0) + (getattr(usage, "cache_read_input_tokens", 0) or 0) + (getattr(usage, "cache_creation_input_tokens", 0) or 0)
    tout = getattr(usage, "output_tokens", 0) or 0
    served = getattr(message, "model", model) or model
    if message.stop_reason == "refusal":
        _log(db, project_id, job_id, "anthropic", f"messages:{name}@{version}", served, key, 200, tin, tout, ms, "refusal")
        db.flush()
        raise LLMError("A Claude nem készítette el a szöveget (elutasítás). Ellenőrizd a bemeneti adatokat, vagy próbáld újra.")
    if message.stop_reason == "max_tokens":
        _log(db, project_id, job_id, "anthropic", f"messages:{name}@{version}", served, key, 200, tin, tout, ms, "max_tokens")
        db.flush()
        raise LLMError("A dokumentum túl hosszú lett (max_tokens). Próbáld kevesebb adattal.")
    text = next((b.text for b in message.content if getattr(b, "type", "") == "text"), "")
    try:
        data = json.loads(text)
    except json.JSONDecodeError as e:
        raise LLMError(f"A Claude válasza nem érvényes JSON: {e}")
    _log(db, project_id, job_id, "anthropic", f"messages:{name}@{version}", served, key, 200, tin, tout, ms)
    _store(db, key, "anthropic", served, data)
    db.flush()
    return data, served
