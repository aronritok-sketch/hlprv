#!/usr/bin/env bash
# HelloProVision SEO OS – szerver-felmérés (CSAK OLVAS, semmit nem módosít).
# Futtatás a szerveren:  sudo bash diagnose.sh
# Az eredmény a képernyőn és a /tmp/hpv-diagnose.txt fájlban is megjelenik – ezt küldd el.
# Jelszavakat, kulcsokat nem ír ki (a wp-config.php-ból csak azt nézi, hogy vannak-e SEO OS beállítások).

OUT=/tmp/hpv-diagnose.txt
exec > >(tee "$OUT") 2>&1

section() { printf '\n===== %s =====\n' "$1"; }
have() { command -v "$1" >/dev/null 2>&1; }

section "Rendszer"
echo "Dátum: $(date -Iseconds)"
[ -r /etc/os-release ] && . /etc/os-release && echo "OS: ${PRETTY_NAME:-?}"
echo "Kernel: $(uname -r)   Architektúra: $(uname -m)"
echo "CPU: $(nproc 2>/dev/null)"
have free && free -h | sed -n '1,2p'
df -h / /opt 2>/dev/null | awk 'NR==1 || !seen[$0]++'
echo "Felhasználó: $(id -un) (uid $(id -u))"

section "Docker"
if have docker; then
  docker --version
  docker compose version 2>/dev/null || echo "docker compose: NINCS"
  systemctl is-active docker 2>/dev/null | sed 's/^/docker szolgáltatás: /'
  docker ps --format '{{.Names}}  {{.Image}}  {{.Status}}' 2>/dev/null
else
  echo "Docker: NINCS telepítve"
fi

section "Webszerver (nginx)"
if have nginx; then
  nginx -v 2>&1
  nginx -t 2>&1 | tail -2
  echo "--- server blokkok (listen / server_name / root / php) ---"
  nginx -T 2>/dev/null | grep -E '^# configuration file|^\s*(listen|server_name|root|fastcgi_pass|ssl_certificate|return|include)\s' | grep -v 'include\s\+/etc/nginx/mime.types'
else
  echo "nginx: NINCS"
fi

section "Webroot mappák"
for base in /etc/nginx/webroot /var/www /srv/www; do
  [ -d "$base" ] || continue
  echo "--- $base ---"
  for d in "$base"/*; do
    [ -e "$d" ] || [ -L "$d" ] || continue
    if [ -L "$d" ]; then
      tgt=$(readlink "$d"); real=$(readlink -f "$d" 2>/dev/null)
      if [ -e "$d" ]; then echo "$(basename "$d") -> $tgt  (valódi: $real)"; else echo "$(basename "$d") -> $tgt  !!! HIBÁS LINK (a cél nem létezik)"; fi
    else
      echo "$(basename "$d")  (mappa)"
    fi
  done
done

section "WordPress telepítések"
found=0
for cfg in $(find /etc/nginx/webroot /var/www /srv /home -maxdepth 5 -name wp-config.php 2>/dev/null | sort -u); do
  found=1
  dir=$(dirname "$cfg")
  echo "--- $dir ---"
  ver=$(grep -oP "wp_version = '\K[^']+" "$dir/wp-includes/version.php" 2>/dev/null)
  echo "WordPress verzió: ${ver:-?}"
  echo "Tulajdonos: $(stat -c '%U:%G' "$dir" 2>/dev/null)"
  for c in WP_HOME WP_SITEURL HPV_SEO_OS_SECRET HPV_SEO_OS_API_URL HPV_SEO_HOST HPV_CRM_HOST HPV_PORTAL_HOST DISABLE_WP_CRON; do
    grep -q "$c" "$cfg" && echo "  wp-config: $c beállítva" || echo "  wp-config: $c nincs"
  done
  echo "Bővítmények:"; ls -1 "$dir/wp-content/plugins" 2>/dev/null | sed 's/^/  /'
  [ -d "$dir/wp-content/mu-plugins" ] && { echo "MU-bővítmények:"; ls -1 "$dir/wp-content/mu-plugins" | sed 's/^/  /'; }
done
[ $found = 0 ] && echo "Nem találtam WordPress telepítést (wp-config.php)."

section "PHP, adatbázis"
have php && php -v | head -1 || echo "php (CLI): nincs"
systemctl list-units --type=service --no-legend 2>/dev/null | grep -Ei 'php|mysql|mariadb|postgres|redis' | awk '{print $1, $3, $4}'
have mysql && mysql --version
have wp && echo "wp-cli: $(wp --version --allow-root 2>/dev/null)" || echo "wp-cli: nincs"

section "Nyitott portok"
have ss && ss -ltnp 2>/dev/null | awk 'NR>1 {print $4, $6}' | sed 's/users:((\"\([^\"]*\)\".*/\1/' | sort -u

section "SSL tanúsítványok"
have certbot && echo "certbot: $(certbot --version 2>&1)"
ls -1 /etc/letsencrypt/live 2>/dev/null | grep -v README
ls -1 /etc/nginx/ssl 2>/dev/null | sed 's/^/nginx\/ssl: /'

section "Cron"
crontab -l 2>/dev/null | grep -v '^#' | sed 's/\(token\|key\|pass\)[^ ]*/\1=***REJTVE***/Ig'
ls /etc/cron.d 2>/dev/null | sed 's/^/cron.d: /'

section "Tűzfal"
have ufw && ufw status 2>/dev/null | head -8

section "SEO OS"
[ -d /opt/seo-os ] && ls -la /opt/seo-os || echo "/opt/seo-os: még nincs"
curl -s -m 3 http://127.0.0.1:8100/health 2>/dev/null && echo " ← API válaszol" || echo "API (127.0.0.1:8100): nem fut"

echo
echo "Kész. Az eredmény: $OUT – ezt a teljes szöveget küldd el."
