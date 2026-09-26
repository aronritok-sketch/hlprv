"""Környezeti beállítások. Az API kulcsok a Beállítások oldalon (adatbázisban) is megadhatók; az ott megadott érték erősebb."""

from functools import lru_cache

from pydantic import field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="SEO_OS_", env_file=".env", extra="ignore")

    database_url: str = "postgresql+psycopg://seo:seo@localhost/seo_os"
    # A WordPress bővítménnyel közös titok (HMAC-SHA256). Legalább 32 karakter.
    hmac_secret: str = ""
    # Az aláírás legfeljebb ennyi másodperccel térhet el a szerver órájától.
    hmac_window: int = 300
    storage_dir: str = "/var/lib/seo-os/files"
    # A Screaming Frog ügynök tokenje (Bearer). Üresen az ügynök nem tud csatlakozni.
    agent_token: str = ""

    openai_api_key: str = ""
    anthropic_api_key: str = ""
    dataforseo_login: str = ""
    dataforseo_password: str = ""
    ahrefs_api_key: str = ""

    @field_validator("database_url")
    @classmethod
    def _driver(cls, v: str) -> str:
        """A felhőszolgáltatók „postgres://…” / „postgresql://…” címet adnak; az SQLAlchemy-nek a psycopg meghajtó kell."""
        for prefix in ("postgres://", "postgresql://"):
            if v.startswith(prefix):
                return "postgresql+psycopg://" + v[len(prefix):]
        return v

    # Tesztekhez: a nyelvi modellek helyett determinisztikus helyettesítő.
    llm_fake: bool = False
    # A háttérfeladatok azonnal, a kérésen belül futnak (tesztekhez).
    jobs_inline: bool = False


@lru_cache
def get_settings() -> Settings:
    return Settings()
