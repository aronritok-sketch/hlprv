"""Kulcsszóadat-szolgáltatók API-n: DataForSEO és Ahrefs (v3, Enterprise csomag kell hozzá).

Minden hívás:
  - projektenkénti havi keret (monthly_budget_usd) ellenőrzése a hívás előtt, az api_logs tényleges költsége alapján;
  - 30 napos gyorsítótár (ugyanaz a kérés nem kerül újra pénzbe);
  - naplózás (api_logs: végpont, státusz, költség, idő).
A kapott adatok ugyanúgy a kulcsszó-adatbázisba kerülnek, mint a feltöltött exportok (forrás: dataforseo / ahrefs_api).
"""

import time
from datetime import datetime, timedelta, timezone
from typing import Iterable, Optional

import httpx
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from ..models import ApiLog, LlmCache
from . import settings as app_settings
from .llm import _hash, _log

DFS_BASE = "https://api.dataforseo.com"
AHREFS_BASE = "https://api.ahrefs.com"
CACHE_DAYS = 30
_transport: Optional[httpx.BaseTransport] = None  # tesztekhez

COUNTRIES = {"us": ("United States", "en", "us"), "hu": ("Hungary", "hu", "hu"), "uk": ("United Kingdom", "en", "gb"),
             "gb": ("United Kingdom", "en", "gb"), "de": ("Germany", "de", "de"), "at": ("Austria", "de", "at")}


class ProviderError(Exception):
    pass


def _chunks(items: list, n: int) -> Iterable[list]:
    for i in range(0, len(items), n):
        yield items[i:i + n]


def dataforseo_configured(db: Session) -> bool:
    return bool(app_settings.get(db, "dataforseo_login") and app_settings.get(db, "dataforseo_password"))


def ahrefs_configured(db: Session) -> bool:
    return bool(app_settings.get(db, "ahrefs_api_key"))


def month_spend(db: Session, project_id: Optional[int]) -> float:
    start = datetime.now(timezone.utc).replace(day=1, hour=0, minute=0, second=0, microsecond=0)
    q = select(func.coalesce(func.sum(ApiLog.cost_usd), 0)).where(ApiLog.created_at >= start)
    if project_id:
        q = q.where(ApiLog.project_id == project_id)
    return float(db.scalar(q) or 0)


def check_budget(db: Session, project_id: Optional[int]) -> None:
    budget = float(app_settings.get(db, "monthly_budget_usd") or 0)
    if budget and project_id and month_spend(db, project_id) >= budget:
        raise ProviderError(f"A projekt havi API-kerete ({budget:.0f} USD) elfogyott. Az admin a Beállításokban emelheti.")


def _cached(db: Session, key: str):
    row = db.get(LlmCache, key)
    if row is None:
        return None
    if row.created_at and row.created_at < datetime.now(timezone.utc) - timedelta(days=CACHE_DAYS):
        db.delete(row)
        db.flush()
        return None
    return row.response


def _client(**kw) -> httpx.Client:
    return httpx.Client(timeout=120, transport=_transport, **kw)


# ── DataForSEO ───────────────────────────────────────────


def dfs_post(db: Session, path: str, tasks: list[dict], project_id: Optional[int] = None, job_id: Optional[int] = None) -> list[dict]:
    """Egy DataForSEO „live” hívás; a feladatok eredményeit adja vissza (task.result listák összefűzve)."""
    if not dataforseo_configured(db):
        raise ProviderError("Nincs DataForSEO hozzáférés beállítva.")
    key = _hash("dataforseo", path, tasks)
    hit = _cached(db, key)
    if hit is not None:
        return hit
    check_budget(db, project_id)
    started = time.monotonic()
    try:
        with _client(auth=(app_settings.get(db, "dataforseo_login"), app_settings.get(db, "dataforseo_password"))) as c:
            r = c.post(DFS_BASE + path, json=tasks)
        data = r.json()
    except (httpx.HTTPError, ValueError) as e:
        _log(db, project_id, job_id, "dataforseo", path, "", key, 0, 0, 0, int((time.monotonic() - started) * 1000), str(e))
        db.flush()
        raise ProviderError(f"DataForSEO hiba: {e}")
    ms = int((time.monotonic() - started) * 1000)
    cost = float(data.get("cost") or 0)
    ok = r.status_code == 200 and data.get("status_code") == 20000
    errors = [t.get("status_message", "") for t in data.get("tasks") or [] if t.get("status_code") != 20000]
    db.add(ApiLog(project_id=project_id, job_id=job_id, provider="dataforseo", endpoint=path, model="", request_hash=key,
                  status_code=data.get("status_code") or r.status_code, tokens_in=len(tasks), tokens_out=0, cost_usd=cost,
                  duration_ms=ms, error="; ".join(errors)[:2000] if errors or not ok else ""))
    db.flush()
    if not ok:
        raise ProviderError(f"DataForSEO: {data.get('status_message') or r.status_code}")
    if errors and len(errors) == len(data.get("tasks") or []):
        raise ProviderError("DataForSEO: " + errors[0])
    results = []
    for t in data.get("tasks") or []:
        if t.get("status_code") == 20000:
            results.extend(t.get("result") or [])
    db.add(LlmCache(key=key, provider="dataforseo", model=path[:128], response=results))
    db.flush()
    return results


def dfs_search_volume(db: Session, keywords: list[str], location_name: str, language_code: str, **kw) -> dict[str, dict]:
    out = {}
    for chunk in _chunks(keywords, 1000):
        res = dfs_post(db, "/v3/keywords_data/google_ads/search_volume/live",
                       [{"keywords": chunk, "location_name": location_name, "language_code": language_code}], **kw)
        for item in res:
            if item.get("keyword"):
                out[item["keyword"].lower()] = {
                    "volume": item.get("search_volume"), "cpc": item.get("cpc"),
                    "competition": str(item.get("competition") or "").lower(),
                    "trend": [m.get("search_volume") or 0 for m in reversed(item.get("monthly_searches") or [])][-12:],
                }
    return out


def dfs_keyword_difficulty(db: Session, keywords: list[str], location_name: str, language_code: str, **kw) -> dict[str, int]:
    out = {}
    for chunk in _chunks(keywords, 1000):
        res = dfs_post(db, "/v3/dataforseo_labs/google/bulk_keyword_difficulty/live",
                       [{"keywords": chunk, "location_name": location_name, "language_code": language_code}], **kw)
        for r in res:
            for item in r.get("items") or []:
                if item.get("keyword") is not None and item.get("keyword_difficulty") is not None:
                    out[item["keyword"].lower()] = int(item["keyword_difficulty"])
    return out


def _labs_item(item: dict) -> dict:
    info = item.get("keyword_info") or {}
    props = item.get("keyword_properties") or {}
    intent = (item.get("search_intent_info") or {}).get("main_intent")
    return {"keyword": item.get("keyword") or (item.get("keyword_data") or {}).get("keyword"), "volume": info.get("search_volume"),
            "cpc": info.get("cpc"), "competition": str(info.get("competition_level") or "").lower(),
            "kd": props.get("keyword_difficulty"), "intent": intent}


def dfs_keyword_ideas(db: Session, seeds: list[str], location_name: str, language_code: str, limit: int = 200, **kw) -> list[dict]:
    out = []
    for seed in seeds:
        res = dfs_post(db, "/v3/dataforseo_labs/google/keyword_suggestions/live",
                       [{"keyword": seed, "location_name": location_name, "language_code": language_code, "limit": limit,
                         "include_seed_keyword": True}], **kw)
        for r in res:
            for item in r.get("items") or []:
                row = _labs_item(item)
                if row["keyword"]:
                    out.append(row)
    return out


def dfs_ranked_keywords(db: Session, target: str, location_name: str, language_code: str, limit: int = 500, **kw) -> list[dict]:
    res = dfs_post(db, "/v3/dataforseo_labs/google/ranked_keywords/live",
                   [{"target": target, "location_name": location_name, "language_code": language_code, "limit": limit}], **kw)
    out = []
    for r in res:
        for item in r.get("items") or []:
            kd = item.get("keyword_data") or {}
            row = _labs_item(kd)
            serp = ((item.get("ranked_serp_element") or {}).get("serp_item")) or {}
            row.update({"position": serp.get("rank_group"), "url": serp.get("url") or ""})
            if row["keyword"]:
                out.append(row)
    return out


def dfs_serp(db: Session, keyword: str, location_name: str, language_code: str, depth: int = 20, **kw) -> list[dict]:
    res = dfs_post(db, "/v3/serp/google/organic/live/advanced",
                   [{"keyword": keyword, "location_name": location_name, "language_code": language_code, "depth": depth}], **kw)
    out = []
    for r in res:
        for item in r.get("items") or []:
            if item.get("type") == "organic" and item.get("domain"):
                out.append({"position": item.get("rank_group"), "domain": item["domain"].lower().removeprefix("www."), "url": item.get("url") or "",
                            "title": item.get("title") or ""})
    return out


def dfs_test(db: Session) -> str:
    with _client(auth=(app_settings.get(db, "dataforseo_login"), app_settings.get(db, "dataforseo_password"))) as c:
        r = c.get(DFS_BASE + "/v3/appendix/user_data")
    data = r.json()
    if data.get("status_code") != 20000:
        raise ProviderError(data.get("status_message") or f"HTTP {r.status_code}")
    res = ((data.get("tasks") or [{}])[0].get("result") or [{}])[0]
    balance = (res.get("money") or {}).get("balance")
    return f"Kapcsolódva ({res.get('login', '')}); egyenleg: {balance} USD" if balance is not None else "Kapcsolódva."


# ── Ahrefs (v3) ──────────────────────────────────────────


def ahrefs_get(db: Session, path: str, params: dict, project_id: Optional[int] = None, job_id: Optional[int] = None) -> dict:
    if not ahrefs_configured(db):
        raise ProviderError("Nincs Ahrefs API kulcs beállítva.")
    key = _hash("ahrefs", path, params)
    hit = _cached(db, key)
    if hit is not None:
        return hit
    check_budget(db, project_id)
    started = time.monotonic()
    try:
        with _client(headers={"Authorization": f"Bearer {app_settings.get(db, 'ahrefs_api_key')}", "Accept": "application/json"}) as c:
            r = c.get(AHREFS_BASE + path, params=params)
        data = r.json() if r.content else {}
    except (httpx.HTTPError, ValueError) as e:
        _log(db, project_id, job_id, "ahrefs", path, "", key, 0, 0, 0, int((time.monotonic() - started) * 1000), str(e))
        db.flush()
        raise ProviderError(f"Ahrefs hiba: {e}")
    ms = int((time.monotonic() - started) * 1000)
    err = "" if r.status_code == 200 else str(data.get("error") or data)[:500]
    _log(db, project_id, job_id, "ahrefs", path, "", key, r.status_code, 0, 0, ms, err)
    db.flush()
    if r.status_code != 200:
        raise ProviderError(f"Ahrefs ({r.status_code}): {err}")
    db.add(LlmCache(key=key, provider="ahrefs", model=path[:128], response=data))
    db.flush()
    return data


def ahrefs_organic_keywords(db: Session, target: str, country: str, limit: int = 500, **kw) -> list[dict]:
    data = ahrefs_get(db, "/v3/site-explorer/organic-keywords", {
        "target": target, "country": country, "date": datetime.now(timezone.utc).date().isoformat(), "mode": "subdomains", "limit": limit,
        "select": "keyword,volume,keyword_difficulty,cpc,best_position,best_position_url,sum_traffic",
        "order_by": "sum_traffic:desc",
    }, **kw)
    out = []
    for item in data.get("keywords") or []:
        cpc = item.get("cpc")
        out.append({"keyword": item.get("keyword"), "volume": item.get("volume"), "kd": item.get("keyword_difficulty"),
                    "cpc": round(cpc / 100, 2) if isinstance(cpc, (int, float)) else None,  # az Ahrefs USD centben adja
                    "position": item.get("best_position"), "url": item.get("best_position_url") or "", "traffic": item.get("sum_traffic")})
    return [o for o in out if o["keyword"]]


def ahrefs_test(db: Session) -> str:
    with _client(headers={"Authorization": f"Bearer {app_settings.get(db, 'ahrefs_api_key')}", "Accept": "application/json"}) as c:
        r = c.get(AHREFS_BASE + "/v3/subscription-info/limits-and-usage")
    if r.status_code != 200:
        raise ProviderError(f"HTTP {r.status_code}: {r.text[:200]}")
    return "Kapcsolódva (Ahrefs API v3)."
