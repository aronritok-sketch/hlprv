#!/bin/sh
# Konténer indítása.
#   start.sh          → adatbázis-migráció, API (és ha SEO_OS_RUN_WORKER=1, mellette a háttérfeladat-worker is)
#   start.sh worker   → csak a worker (docker compose külön szolgáltatásként így futtatja)
# Rootként indul, hogy a fájltár (Docker kötet / felhő-lemez) jogosultságát beállítsa, majd korlátozott felhasználóra vált.
set -e
STORAGE="${SEO_OS_STORAGE_DIR:-/var/lib/seo-os/files}"
if [ "$(id -u)" = 0 ]; then
  mkdir -p "$STORAGE"
  chown -R seoos:seoos "$STORAGE" 2>/dev/null || true
  exec setpriv --reuid=seoos --regid=seoos --init-groups "$0" "$@"
fi

if [ "$1" = "worker" ]; then
  exec python -m app.jobs.worker
fi

alembic upgrade head

if [ "${SEO_OS_RUN_WORKER:-0}" = "1" ]; then
  # Egy szolgáltatásban futó worker (felhős telepítéshez): ha leáll, újraindul.
  ( while true; do python -m app.jobs.worker || true; sleep 5; done ) &
fi

exec uvicorn app.main:app --host 0.0.0.0 --port "${PORT:-8100}" --proxy-headers --forwarded-allow-ips='*' --workers "${WEB_CONCURRENCY:-1}"
