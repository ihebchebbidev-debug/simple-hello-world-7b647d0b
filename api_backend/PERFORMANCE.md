# Backend performance — no-cache version

You asked for **no response caching** so new entries (and language
changes) always show up instantly. So all the previous cache code was
removed. The remaining wins are pure query optimizations + Laravel
production tuning — none of them hide fresh data.

## Code changes kept

* `DashboardController::recentActivity` rewritten as **one `UNION ALL`**
  query ordered + limited at the database. Was 4 separate queries ×
  `limit` rows + a PHP `sortByDesc`. Same result, ~4× fewer DB
  round-trips.
* Both dashboard endpoints stamp `Cache-Control: no-store` so the
  browser also never serves a stale copy.

## Code changes reverted

* Deleted `App\Http\Middleware\CacheResponse`.
* Removed `cache.response` alias from `bootstrap/app.php`.
* Removed the middleware from the `dashboard` and `reports` route
  groups in `routes/api.php`.
* Removed the `Cache::remember` wrapper from `DashboardController::stats`.

## Deploy on the VPS (these don't cache responses — safe)

```bash
cd /var/www/flehty
git pull

# Rebuild Laravel's internal caches (config/routes/views).
# These cache PHP code, NOT API responses, so live data is unaffected.
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache

# Production-grade autoloader
composer install --no-dev --optimize-autoloader --classmap-authoritative

# Reload PHP so OPcache picks up the new files
sudo systemctl reload php8.2-fpm
```

## OPcache (huge win, does NOT cache responses)

OPcache caches **compiled PHP bytecode**, not API responses. New DB
rows always show up immediately.

Edit `/etc/php/8.2/fpm/conf.d/10-opcache.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.save_comments=1
opcache.fast_shutdown=1
```

```bash
sudo systemctl reload php8.2-fpm
```

> With `validate_timestamps=0` you MUST `systemctl reload php8.2-fpm`
> after every `git pull`, otherwise PHP keeps the old bytecode.

## Optional next-level wins (still no response cache)

* Enable **gzip** in nginx — JSON payloads shrink ~10–20×, makes the
  network part almost instant.
* Use **HTTP/2** (`listen 443 ssl http2;` in your vhost).
* Add a few **indexes** only if `EXPLAIN ANALYZE` shows a sequential
  scan — current schema already has `(plot_id, operation_date)` on every
  ops table, which is what the reports use.
