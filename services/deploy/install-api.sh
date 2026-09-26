#!/usr/bin/env bash
# HelloProVision SEO OS – API, worker és adatbázis telepítése / frissítése Dockerrel.
#
# Előtte: a repó „services” mappáját töltsd fel a szerverre, pl. /opt/seo-os alá (FileZilla), így ez a fájl:
#   /opt/seo-os/deploy/install-api.sh
# Futtatás:  sudo bash /opt/seo-os/deploy/install-api.sh
#
# Újrafuttatható: a meglévő .env-hez (jelszavak, titkok) nem nyúl, csak újraépíti és újraindítja a szolgáltatásokat
# (frissítés: töltsd fel az új fájlokat, és futtasd újra).
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$DIR"

say() { printf '\n\033[1;32m==>\033[0m %s\n' "$1"; }
die() { printf '\n\033[1;31mHIBA:\033[0m %s\n' "$1" >&2; exit 1; }

[ "$(id -u)" = 0 ] || die "rootként futtasd: sudo bash $0"
[ -f docker-compose.yml ] && [ -d seo-os-api ] || die "Nem találom a docker-compose.yml-t és a seo-os-api mappát itt: $DIR"

# 1. Docker
if ! command -v docker >/dev/null 2>&1; then
  say "Docker telepítése (hivatalos telepítő)…"
  curl -fsSL https://get.docker.com | sh
fi
systemctl enable --now docker >/dev/null 2>&1 || true
docker compose version >/dev/null 2>&1 || die "A docker compose bővítmény hiányzik (apt install docker-compose-plugin)."

# 2. .env – egyszer, véletlen titkokkal
rand() { openssl rand -hex "$1" 2>/dev/null || head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }
if [ ! -f .env ]; then
  say ".env létrehozása véletlen jelszavakkal…"
  cp .env.example .env
  sed -i "s|^POSTGRES_PASSWORD=.*|POSTGRES_PASSWORD=$(rand 20)|" .env
  sed -i "s|^SEO_OS_HMAC_SECRET=.*|SEO_OS_HMAC_SECRET=$(rand 32)|" .env
  sed -i "s|^SEO_OS_AGENT_TOKEN=.*|SEO_OS_AGENT_TOKEN=$(rand 24)|" .env
fi
chmod 600 .env
get() { grep -E "^$1=" .env | head -1 | cut -d= -f2-; }
[ -n "$(get SEO_OS_HMAC_SECRET)" ] || die "A .env-ben üres a SEO_OS_HMAC_SECRET."

# 3. Indítás
say "Képek építése és indítás (az első alkalom néhány perc)…"
docker compose up -d --build db api worker

say "Várakozás az API-ra…"
for i in $(seq 1 60); do
  if curl -fs -m 2 http://127.0.0.1:8100/health >/dev/null; then ok=1; break; fi
  sleep 2
done
[ "${ok:-}" = 1 ] || { docker compose logs --tail=40 api; die "Az API nem indult el – a fenti napló mutatja az okát."; }
docker compose ps

# 4. Mi a következő lépés
cat <<MSG

$(printf '\033[1;32m')Az SEO OS API fut: http://127.0.0.1:8100 (csak a szerverről érhető el).$(printf '\033[0m')

1) A WordPress wp-config.php fájljába, a "That's all, stop editing" sor ELÉ:

   define( 'HPV_SEO_OS_SECRET', '$(get SEO_OS_HMAC_SECRET)' );
   define( 'HPV_SEO_OS_API_URL', 'http://127.0.0.1:8100' );
   define( 'DISABLE_WP_CRON', true );

2) Valódi cron az e-mailekhez (crontab -e):

   */5 * * * * curl -s https://seo.helloprovision.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1

3) A Screaming Frog ügynök tokenje (az irodai gép agent.env fájljába, HPV_AGENT_TOKEN=):

   $(get SEO_OS_AGENT_TOKEN)

A titkok a $DIR/.env fájlban vannak (csak root olvashatja). Mentsd el őket biztonságos helyre.
Napló: docker compose -f $DIR/docker-compose.yml logs -f api worker
MSG
