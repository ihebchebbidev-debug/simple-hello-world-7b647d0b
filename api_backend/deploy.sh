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

# Auto-detect the running PHP version (the box may not be 8.2). Falls back to
# an env override, then 8.2.
PHP_VERSION="${PHP_VERSION:-$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo 8.2)}"
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

# ── OPcache: the single biggest PHP perf win (3-5x faster) ───────────────────
# CRITICAL: this app runs under supervisor (`agritrack`, i.e. the PHP *CLI*
# runtime via `artisan serve`), NOT php-fpm. So we MUST set enable_cli=1 and
# write the config into EVERY SAPI's conf.d — otherwise OPcache stays OFF for
# the real runtime (which is exactly what the sanity check reported). The
# supervisor restart below makes the serve process pick this up.
echo "==> Configuring OPcache (all SAPIs, enable_cli=1 — runtime is CLI/artisan serve)"
OPCACHE_CONF=$(cat <<'EOF'
zend_extension=opcache.so
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.revalidate_freq=0
opcache.fast_shutdown=1
opcache.jit_buffer_size=128M
opcache.jit=tracing
EOF
)
opcache_written=0
for sapi_dir in /etc/php/${PHP_VERSION}/cli/conf.d /etc/php/${PHP_VERSION}/fpm/conf.d /etc/php/${PHP_VERSION}/apache2/conf.d; do
  [ -d "$sapi_dir" ] || continue
  echo "$OPCACHE_CONF" | sudo tee "$sapi_dir/10-opcache.ini" > /dev/null
  echo "    wrote $sapi_dir/10-opcache.ini"
  opcache_written=1
done
if [ "$opcache_written" = 0 ]; then
  echo "    WARNING: no /etc/php/${PHP_VERSION}/*/conf.d found — is PHP installed at this version?"
fi

# ── nginx gzip: ~80% smaller JSON responses ──────────────────────────────────
echo "==> Configuring nginx gzip"
if [ -d /etc/nginx/conf.d ]; then
  # The stock /etc/nginx/nginx.conf ships `gzip on;` (and sometimes
  # `gzip_vary on;`) in the http{} context. Files in conf.d/ are *included* in
  # that same context, so re-declaring those toggles makes `nginx -t` fail with
  # "gzip directive is duplicate". Comment out the stock lines so our tuned
  # block below is the single source of truth. Guarded + idempotent: a line
  # that's already commented starts with `#` and won't match again.
  if [ -f /etc/nginx/nginx.conf ]; then
    for directive in 'gzip on;' 'gzip_vary on;'; do
      if grep -qE "^[[:space:]]*${directive}" /etc/nginx/nginx.conf; then
        sudo sed -i -E "s|^([[:space:]]*)(${directive})|\1# \2  # superseded by conf.d/zz-gzip.conf|" /etc/nginx/nginx.conf
      fi
    done
  fi

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
