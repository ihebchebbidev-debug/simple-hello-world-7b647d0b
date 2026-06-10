#!/usr/bin/env bash
# One-shot deploy + performance tuning for the Flehty API on the VPS.
#
# Usage (run from anywhere as root or with sudo):
#   cd /var/www/flehty && bash api_backend/deploy.sh
#
# What it does:
#   1. git pull latest code
#   2. composer install (production, optimized autoloader)
#   3. Cache config / routes / events / views (saves ~30-80ms per request)
#   4. Ensure OPcache is enabled and tuned for production
#   5. Enable gzip in nginx (cuts JSON response size ~80%)
#   6. Reload PHP-FPM + nginx
#
# Safe to re-run anytime.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/flehty}"
API_DIR="$APP_DIR/api_backend"
PHP_VERSION="${PHP_VERSION:-8.2}"
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"

echo "==> Pulling latest code"
cd "$APP_DIR"
git pull --ff-only

echo "==> Installing PHP dependencies (production)"
cd "$API_DIR"
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction

echo "==> Clearing & rebuilding Laravel caches"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache

echo "==> Configuring OPcache"
OPCACHE_INI="/etc/php/${PHP_VERSION}/fpm/conf.d/10-opcache.ini"
if [ -f "$OPCACHE_INI" ] || [ -d "/etc/php/${PHP_VERSION}/fpm/conf.d" ]; then
  cat > "$OPCACHE_INI" <<'EOF'
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
  echo "    wrote $OPCACHE_INI (validate_timestamps=0 — re-run this script after every deploy)"
fi

echo "==> Configuring nginx gzip"
NGINX_GZIP="/etc/nginx/conf.d/zz-gzip.conf"
if [ -d /etc/nginx/conf.d ]; then
  cat > "$NGINX_GZIP" <<'EOF'
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
  nginx -t
fi

echo "==> Reloading services"
systemctl reload "$PHP_FPM_SERVICE" || systemctl restart "$PHP_FPM_SERVICE"
systemctl reload nginx || true

echo "==> Done. Quick sanity check:"
php -r 'echo "OPcache: ", (function_exists("opcache_get_status") && opcache_get_status(false) ? "ON" : "OFF"), PHP_EOL;'
echo "    APP_ENV=$(grep ^APP_ENV "$API_DIR/.env" | cut -d= -f2)"
echo "    APP_DEBUG=$(grep ^APP_DEBUG "$API_DIR/.env" | cut -d= -f2)"
echo
echo "If APP_DEBUG=true, set it to false in $API_DIR/.env then re-run this script."
