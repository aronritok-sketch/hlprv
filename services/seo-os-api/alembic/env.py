"""Alembic: a modellek a `seo` sémában vannak; a verziótábla is ott."""

from logging.config import fileConfig

from alembic import context
from sqlalchemy import create_engine, text

from app import models  # noqa: F401 – minden modell regisztrálása
from app.config import get_settings
from app.db import SCHEMA, Base

config = context.config
if config.config_file_name is not None:
    fileConfig(config.config_file_name)

target_metadata = Base.metadata


def include_name(name, type_, parent_names):
    if type_ == "schema":
        return name == SCHEMA
    return True


def run_migrations_online() -> None:
    url = config.attributes.get("url") or get_settings().database_url
    # Ha az adatbázis-felhasználó neve „seo”, a "$user" miatt a seo séma lenne az alapértelmezett,
    # és az összehasonlítás összekeveredne. A kapcsolat ezért a public sémával indul; a seo mindig kifejezett.
    engine = create_engine(url, connect_args={"options": "-csearch_path=public"})
    with engine.connect() as connection:
        connection.execute(text(f"CREATE SCHEMA IF NOT EXISTS {SCHEMA}"))
        connection.commit()
        context.configure(
            connection=connection,
            target_metadata=target_metadata,
            version_table_schema=SCHEMA,
            include_schemas=True,
            include_name=include_name,
            compare_type=True,
        )
        with context.begin_transaction():
            context.run_migrations()


run_migrations_online()
