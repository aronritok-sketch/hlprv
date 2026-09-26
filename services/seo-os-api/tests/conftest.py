"""Tesztkörnyezet: valódi PostgreSQL (SEO_OS_TEST_DATABASE_URL), minden teszt előtt üres adatbázis.

A `client` fixture úgy ír alá, mint a WordPress bővítmény; a szerepkör tesztenként választható.
"""

import json
import os
import time
from pathlib import Path
from urllib.parse import quote

import pytest
from alembic import command
from alembic.config import Config
from fastapi.testclient import TestClient
from sqlalchemy import create_engine, text

TEST_DB = os.environ.get("SEO_OS_TEST_DATABASE_URL", "postgresql+psycopg://seo:seo@localhost/seo_os_test")
SECRET = "test-secret-" + "x" * 40
AGENT_TOKEN = "agent-token-123"
ROOT = Path(__file__).resolve().parents[1]
FIXTURES = Path(__file__).resolve().parent / "fixtures"

os.environ["SEO_OS_DATABASE_URL"] = TEST_DB
os.environ["SEO_OS_HMAC_SECRET"] = SECRET
os.environ["SEO_OS_AGENT_TOKEN"] = AGENT_TOKEN
os.environ["SEO_OS_LLM_FAKE"] = "1"
os.environ["SEO_OS_JOBS_INLINE"] = "1"

from app.auth import canonical, sign  # noqa: E402
from app.config import get_settings  # noqa: E402
from app.db import reset_engine, session_factory  # noqa: E402
from app.main import create_app  # noqa: E402


@pytest.fixture(scope="session", autouse=True)
def _migrate(tmp_path_factory):
    os.environ["SEO_OS_STORAGE_DIR"] = str(tmp_path_factory.mktemp("files"))
    get_settings.cache_clear()
    reset_engine()
    engine = create_engine(TEST_DB)
    with engine.begin() as conn:
        conn.execute(text("DROP SCHEMA IF EXISTS seo CASCADE"))
    engine.dispose()
    cfg = Config(str(ROOT / "alembic.ini"))
    cfg.set_main_option("script_location", str(ROOT / "alembic"))
    cfg.attributes["url"] = TEST_DB
    command.upgrade(cfg, "head")
    yield


@pytest.fixture(autouse=True)
def _clean():
    engine = create_engine(TEST_DB)
    with engine.begin() as conn:
        tables = conn.execute(
            text("SELECT tablename FROM pg_tables WHERE schemaname='seo' AND tablename <> 'alembic_version'")
        ).scalars().all()
        if tables:
            conn.execute(text("TRUNCATE " + ", ".join(f"seo.{t}" for t in tables) + " RESTART IDENTITY CASCADE"))
    engine.dispose()
    yield


@pytest.fixture
def db():
    s = session_factory()()
    yield s
    s.close()


USERS = {
    "admin": (1, "Áron Admin"),
    "seo_manager": (2, "Olívia SEO"),
    "content_manager": (3, "Zsolti Content"),
    "designer": (4, "Grafikus"),
    "developer": (5, "Laci Dev"),
    "staff": (6, "Kata CRM"),
}


class SignedClient:
    """Úgy ír alá, mint a WordPress bővítmény."""

    def __init__(self, role: str = "admin", wp_user: int | None = None, secret: str = SECRET):
        self.http = TestClient(create_app())
        self.role = role
        self.wp_user = wp_user if wp_user is not None else USERS.get(role, (0, ""))[0]
        self.name = USERS.get(role, (0, "Ügynök"))[1]
        self.secret = secret
        self.extra_headers: dict[str, str] = {}

    def as_role(self, role: str) -> "SignedClient":
        other = SignedClient(role, secret=self.secret)
        return other

    def request(self, method: str, path: str, json_body=None, raw: bytes | None = None, headers=None, ts=None):
        body = raw if raw is not None else (json.dumps(json_body).encode() if json_body is not None else b"")
        ts = str(ts if ts is not None else int(time.time()))
        email, name = quote(f"{self.role}@example.com"), quote(self.name)
        sig = sign(self.secret, canonical(method, path, ts, body, str(self.wp_user), self.role, email, name))
        h = {
            "X-HPV-Timestamp": ts,
            "X-HPV-User": str(self.wp_user),
            "X-HPV-Role": self.role,
            "X-HPV-Email": email,
            "X-HPV-Name": name,
            "X-HPV-Signature": sig,
            "Content-Type": "application/json",
        }
        h.update(self.extra_headers)
        h.update(headers or {})
        return self.http.request(method, path, content=body, headers=h)

    def get(self, path, **kw):
        return self.request("GET", path, **kw)

    def post(self, path, json=None, **kw):
        return self.request("POST", path, json_body=json, **kw)

    def patch(self, path, json=None, **kw):
        return self.request("PATCH", path, json_body=json, **kw)

    def put(self, path, json=None, **kw):
        return self.request("PUT", path, json_body=json, **kw)

    def delete(self, path, **kw):
        return self.request("DELETE", path, **kw)


@pytest.fixture
def client():
    return SignedClient("admin")


@pytest.fixture
def as_role():
    return lambda role: SignedClient(role)


def make_project(c: SignedClient, **over) -> dict:
    body = {
        "name": "Imperial Kitchens – SEO",
        "domain": "https://www.imperialkitchens.com/",
        "industry": "Kitchen Remodeling",
        "market": "us",
        "locations": ["Naples", "Fort Myers", "naples"],
        "content_language": "en-US",
        "business_services": [{"name": "Kitchen remodeling", "high_margin": True, "priority": True}, {"name": "Cabinets"}],
        "target_audience": "Homeowners in Naples",
        "business_goals": "More qualified remodeling leads",
        "excluded_topics": ["DIY"],
        "scope": ["keyword_research", "structure", "content_strategy", "content_mgmt"],
        "start_date": "2026-09-01",
        "client": {"name": "Imperial Kitchens", "primary_domain": "imperialkitchens.com"},
        "competitors": [{"domain": "https://competitor-a.com/x", "source": "client"}, {"domain": "competitor-b.com"}],
        "seed_keywords": [
            {"keyword": "kitchen remodeling naples", "kind": "service"},
            {"keyword": "DIY cabinets", "kind": "excluded"},
        ],
    }
    body.update(over)
    r = c.post("/projects", json=body)
    assert r.status_code == 201, r.text
    return r.json()
