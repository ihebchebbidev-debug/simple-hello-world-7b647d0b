#!/bin/bash

echo "==> AgriTrack API starting"

# Render injects PORT (default 10000). Patch Apache to match.
PORT=${PORT:-80}
echo "==> Configuring Apache on port $PORT"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

echo "==> Ensuring storage directories exist"
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

# Ensure .env exists so key:generate has a file to write to
touch .env

# Render's generateValue produces a plain random string without the required
# "base64:" prefix that Laravel needs. Detect and auto-generate a valid key.
if [ -z "$APP_KEY" ] || [ "${APP_KEY#base64:}" = "$APP_KEY" ]; then
    echo "==> APP_KEY missing or not in base64: format — generating one"
    php artisan key:generate --force
    unset APP_KEY
else
    echo "==> APP_KEY is valid"
fi

# Always clear any cached config first — a stale bootstrap/cache/config.php
# from a previous build will mask new env vars (e.g. DATABASE_URL) and make
# Laravel silently fall back to the SQLite default.
echo "==> Clearing stale caches"
php artisan config:clear  || true
php artisan cache:clear   || true
php artisan route:clear   || true
php artisan view:clear    || true
rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/packages.php bootstrap/cache/services.php

echo "==> Caching config"
php artisan config:cache || echo "[warn] config:cache failed"

# ---------------------------------------------------------------------------
# Database connection diagnostics
# ---------------------------------------------------------------------------
echo ""
echo "========================================"
echo "  DATABASE CONNECTION"
echo "========================================"
if [ -n "$DATABASE_URL" ]; then
    # Strip credentials before printing
    SAFE_URL=$(echo "$DATABASE_URL" | sed -E 's#(://)[^@]+@#\1***:***@#')
    echo "==> DATABASE_URL is set: $SAFE_URL"
elif [ -n "$DB_HOST" ]; then
    echo "==> DB_HOST=$DB_HOST DB_DATABASE=${DB_DATABASE:-?} DB_USERNAME=${DB_USERNAME:-?}"
else
    echo "==> [FATAL] No DATABASE_URL or DB_HOST configured."
    echo "    Laravel will fall back to a local SQLite file inside the container,"
    echo "    which is wiped on every redeploy. Set DATABASE_URL in Render."
    exit 1
fi

php artisan tinker --execute="echo 'driver=' . config('database.default') . PHP_EOL; \
    \$c = config('database.default'); \
    echo 'database=' . (config(\"database.connections.\$c.database\") ?: config(\"database.connections.\$c.url\")) . PHP_EOL; \
    try { DB::connection()->getPdo(); echo '[OK] connection alive' . PHP_EOL; } \
    catch (\Throwable \$e) { echo '[FAIL] ' . \$e->getMessage() . PHP_EOL; exit(1); }" \
    || { echo "==> [FATAL] Could not connect to database"; exit 1; }
echo "========================================"

# ---------------------------------------------------------------------------
# Migrations
# ---------------------------------------------------------------------------
echo ""
echo "========================================"
echo "  DATABASE MIGRATIONS"
echo "========================================"
echo "==> Pending migrations (before run):"
php artisan migrate:status 2>&1 | tail -40 || true
echo ""
php artisan migrate --force --verbose 2>&1
MIGRATE_EXIT=$?
if [ $MIGRATE_EXIT -eq 0 ]; then
    echo "==> [OK] Migrations completed successfully"
    echo "==> Final migration status:"
    php artisan migrate:status 2>&1 | tail -25 || true
else
    echo "==> [FAIL] Migrations failed (exit $MIGRATE_EXIT)"
    echo "    Check that DATABASE_URL is set correctly in Render dashboard"
    exit $MIGRATE_EXIT
fi
echo "========================================"
echo ""

# ---------------------------------------------------------------------------
# Seeders (only runs when RUN_SEEDERS=true is set in Render env vars)
# ---------------------------------------------------------------------------
if [ "$RUN_SEEDERS" = "true" ]; then
    echo ""
    echo "========================================"
    echo "  DATABASE SEEDERS"
    echo "========================================"
    php artisan db:seed --force --verbose 2>&1
    SEED_EXIT=$?
    if [ $SEED_EXIT -eq 0 ]; then
        echo "==> [OK] Seeders completed successfully"
    else
        echo "==> [FAIL] Seeders failed (exit $SEED_EXIT)"
    fi
    echo "========================================"
    echo ""
else
    echo "==> Seeders skipped (set RUN_SEEDERS=true in Render env to run them)"
fi

echo "==> Starting Apache"
exec /usr/local/bin/apache2-foreground
