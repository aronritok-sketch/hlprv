"""Nyelvi modellek: OpenAI (elemzés, szigorú JSON-séma kimenettel; embedding) és Claude (dokumentumszöveg, 4. ütem).

Minden hívás:
  - gyorsítótárazott (azonos bemenet → nincs újabb költség),
  - naplózott (api_logs: tokenek, idő, modell, prompt-verzió a request_hash-ben),
  - hibánál háromszor újrapróbál, növekvő várakozással.
Ha nincs API kulcs (vagy SEO_OS_LLM_FAKE=1), a hívó a szabályalapú (heurisztikus) motort használja.
"""

import hashlib
import json
import time
from typing import Any, Optional

from sqlalchemy.orm import Session

from ..config import get_settings
from ..models import ApiLog, LlmCache
from . import settings as app_settings


class LLMUnavailable(Exception):
    pass


class LLMError(Exception):
    pass


def openai_available(db: Session) -> bool:
    return not get_settings().llm_fake and bool(app_settings.get(db, "openai_api_key"))


def claude_available(db: Session) -> bool:
    return not get_settings().llm_fake and bool(app_settings.get(db, "anthropic_api_key"))


def _hash(*parts: Any) -> str:
    return hashlib.sha256(json.dumps(parts, sort_keys=True, ensure_ascii=False, default=str).encode()).hexdigest()


def _cached(db: Session, key: str):
    row = db.get(LlmCache, key)
    return row.response if row else None


def _store(db: Session, key: str, provider: str, model: str, response: Any) -> None:
    if db.get(LlmCache, key) is None:
        db.add(LlmCache(key=key, provider=provider, model=model, response=response))


def _log(db: Session, project_id, job_id, provider, endpoint, model, key, status, tin, tout, ms, error="") -> None:
    prices = app_settings.get(db, "llm_prices")
    p = (prices or {}).get(model) or [0, 0]
    cost = (tin * float(p[0]) + tout * float(p[1])) / 1_000_000
    db.add(
        ApiLog(
            project_id=project_id, job_id=job_id, provider=provider, endpoint=endpoint, model=model,
            request_hash=key, status_code=status, tokens_in=tin, tokens_out=tout, cost_usd=cost,
            duration_ms=ms, error=error[:2000],
        )
    )


_client_factory = None  # tesztekhez felülírható: (api_key) -> kliens


def openai_client(db: Session):
    if not openai_available(db):
        raise LLMUnavailable("Nincs OpenAI API kulcs beállítva.")
    if _client_factory:
        return _client_factory(app_settings.get(db, "openai_api_key"))
    from openai import OpenAI

    return OpenAI(api_key=app_settings.get(db, "openai_api_key"), timeout=120, max_retries=0)


def json_call(db: Session, *, name: str, version: str, system: str, user: str, schema: dict, project_id: Optional[int] = None,
              job_id: Optional[int] = None, model: Optional[str] = None, temperature: float = 0.2) -> dict:
    """OpenAI hívás szigorú JSON-séma kimenettel. `name`/`version` a prompt azonosítója (a gyorsítótár kulcsának része)."""
    model = model or app_settings.get(db, "openai_model")
    key = _hash("openai", model, name, version, system, user, schema)
    hit = _cached(db, key)
    if hit is not None:
        return hit
    client = openai_client(db)
    last_error = ""
    for attempt in range(3):
        started = time.monotonic()
        try:
            resp = client.chat.completions.create(
                model=model,
                messages=[{"role": "system", "content": system}, {"role": "user", "content": user}],
                response_format={"type": "json_schema", "json_schema": {"name": name, "strict": True, "schema": schema}},
                temperature=temperature,
            )
            ms = int((time.monotonic() - started) * 1000)
            content = resp.choices[0].message.content or "{}"
            data = json.loads(content)
            usage = getattr(resp, "usage", None)
            _log(db, project_id, job_id, "openai", f"chat:{name}@{version}", model, key, 200,
                 getattr(usage, "prompt_tokens", 0) or 0, getattr(usage, "completion_tokens", 0) or 0, ms)
            _store(db, key, "openai", model, data)
            db.flush()
            return data
        except LLMUnavailable:
            raise
        except Exception as e:  # noqa: BLE001 – hálózati/kvóta hibák: újrapróbálás
            ms = int((time.monotonic() - started) * 1000)
            last_error = f"{type(e).__name__}: {e}"
            _log(db, project_id, job_id, "openai", f"chat:{name}@{version}", model, key, getattr(e, "status_code", 0) or 0, 0, 0, ms, last_error)
            db.flush()
            if attempt < 2:
                time.sleep(2 * (attempt + 1))
    raise LLMError("Az OpenAI hívás nem sikerült: " + last_error)


def embed(db: Session, texts: list[str], project_id: Optional[int] = None, job_id: Optional[int] = None) -> list[list[float]]:
    model = app_settings.get(db, "openai_embedding_model")
    out: list[Optional[list[float]]] = [None] * len(texts)
    missing: list[int] = []
    for i, t in enumerate(texts):
        hit = _cached(db, _hash("emb", model, t))
        if hit is not None:
            out[i] = hit
        else:
            missing.append(i)
    client = openai_client(db) if missing else None
    for start in range(0, len(missing), 500):
        chunk = missing[start : start + 500]
        started = time.monotonic()
        resp = client.embeddings.create(model=model, input=[texts[i] for i in chunk])
        ms = int((time.monotonic() - started) * 1000)
        usage = getattr(resp, "usage", None)
        _log(db, project_id, job_id, "openai", "embeddings", model, "", 200, getattr(usage, "prompt_tokens", 0) or 0, 0, ms)
        for i, item in zip(chunk, resp.data):
            out[i] = list(item.embedding)
            _store(db, _hash("emb", model, texts[i]), "openai", model, out[i])
        db.flush()
    return [v or [] for v in out]
