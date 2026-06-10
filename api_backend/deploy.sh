#!/usr/bin/env bash
# Flehty / AgriTrack — one-shot deploy + performance tuning for the VPS.
#
# Usage:
#   cd ~/simple-hello-world-7b647d0b/api_backend && bash deploy.sh
#
# Replaces this whole chain in a single command:
#   git pull && composer install --no-dev --optimize-autoloader && \
#   php artisan optimize:clear && php artisan config:cache && \
#   php artisan route:cache && php artisan migrate --force && \
#   sudo supervisorctl restart agritrack
#
# AND additionally tunes OPcache + nginx gzip (the two biggest perf wins
# people forget). Safe to re-run anytime.

set -euo pipefail

# ── repo root = parent of this script's directory ────────────────────────────
API_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$API_DIR")"

PHP_VERSION="${PHP_VERSION:-8.2}"
SUPERVISOR_PROGRAM="${SUPERVISOR_PROGRAM:-agritrack}"

echo "==> Pulling latest code ($APP_DIR)"
cd "$APP_DIR"
git pull --ff-only

echo "==> Installing PHP dependencies (production, optimized autoloader)"
cd "$API_DIR"
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction

echo "==> Running database migrations"
php artisan migrate --force || echo "    (no migrations directory or already up to date)"

echo "==> Rebuilding Laravel caches"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache

# ── OPcache: typically the single biggest PHP perf win (3-5x faster) ─────────
echo "==> Configuring OPcache"
OPCACHE_INI="/etc/php/${PHP_VERSION}/fpm/conf.d/10-opcache.ini"
if [ -d "/etc/php/${PHP_VERSION}/fpm/conf.d" ]; then
  sudo tee "$OPCACHE_INI" > /dev/null <<'EOF'
zend_extension=opcache.so
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.revalidate_freq=0
opcache.fast_shutdown=1
opcache.jit_buffer_size=128M
opcache.jit=tracing
EOF
  echo "    wrote $OPCACHE_INI (validate_timestamps=0 — that's why we re-run this script after every deploy)"
fi

# ── nginx gzip: ~80% smaller JSON responses ──────────────────────────────────
echo "==> Configuring nginx gzip"
if [ -d /etc/nginx/conf.d ]; then
  sudo tee /etc/nginx/conf.d/zz-gzip.conf > /dev/null <<'EOF'
gzip on;
gzip_vary on;
gzip_proxied any;
gzip_comp_level 6;
gzip_min_length 256;
gzip_types
  application/json
  application/javascript
  application/xml
  text/css
  text/plain
  text/xml
  image/svg+xml;
EOF
  sudo nginx -t
fi

# ── Reload everything ────────────────────────────────────────────────────────
echo "==> Reloading PHP-FPM, nginx, and ${SUPERVISOR_PROGRAM}"
sudo systemctl reload "php${PHP_VERSION}-fpm" || sudo systemctl restart "php${PHP_VERSION}-fpm" || true
sudo systemctl reload nginx || true
sudo supervisorctl restart "$SUPERVISOR_PROGRAM" || echo "    (supervisor program $SUPERVISOR_PROGRAM not found — skipping)"

# ── Sanity check ─────────────────────────────────────────────────────────────
echo
echo "==> Sanity check"
php -r 'echo "    OPcache : ", (function_exists("opcache_get_status") && @opcache_get_status(false) ? "ON" : "OFF"), PHP_EOL;'
echo "    APP_ENV  : $(grep ^APP_ENV "$API_DIR/.env" 2>/dev/null | cut -d= -f2 || echo unknown)"
echo "    APP_DEBUG: $(grep ^APP_DEBUG "$API_DIR/.env" 2>/dev/null | cut -d= -f2 || echo unknown)"
echo
echo "If APP_DEBUG=true, set it to false in $API_DIR/.env then re-run this script."
echo "Done."
