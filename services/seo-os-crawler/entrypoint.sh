#!/bin/sh
# A Screaming Frog licenc és beállítások előkészítése, majd az ügynök indítása.
set -e
SF_HOME="$HOME/.ScreamingFrogSEOSpider"
mkdir -p "$SF_HOME"

if [ -n "$SF_LICENCE_USER" ] && [ -n "$SF_LICENCE_KEY" ]; then
  printf '%s\n%s\n' "$SF_LICENCE_USER" "$SF_LICENCE_KEY" > "$SF_HOME/licence.txt"
fi
# A licencszerződés elfogadása (a szükséges érték verziófüggő: ha a crawl EULA-hiba miatt áll le,
# indítsd el egyszer a programot grafikusan, fogadd el, és másold át az ottani spider.config sort).
if [ -n "$SF_EULA" ] && ! grep -q "eula.accepted" "$SF_HOME/spider.config" 2>/dev/null; then
  echo "eula.accepted=$SF_EULA" >> "$SF_HOME/spider.config"
fi
# Memória (nagy oldalakhoz): pl. SF_MEMORY=8g
if [ -n "$SF_MEMORY" ]; then
  echo "-Xmx$SF_MEMORY" > "$HOME/.screamingfrogseospider"
fi

exec python3 /opt/hpv/agent.py "$@"
