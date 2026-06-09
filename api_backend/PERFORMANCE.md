# Backend performance — what changed & how to deploy

This pass targets the slow endpoints the client complained about:
**dashboard** (`/api/dashboard/stats`, `/api/dashboard/recent-activity`)
and the five **reports** (`/api/reports/*`).

## Changes in this commit

### 1. Server-side response cache (60 s, per user + URL)
New middleware `App\Http\Middleware\CacheResponse`.

* Registered as alias `cache.response` in `bootstrap/app.php`.
* Applied in `routes/api.php`:
  * `dashboard` group → `cache.response:30`
  * `reports`   group → `cache.response:60`
* Sends `Cache-Control: private, max-age=…` so browsers also cache.
* Adds `X-Cache: HIT|MISS` for debugging.

Effect: repeat loads of a report / dashboard tab go from ~300–1500 ms
(SUM + correlated subqueries) to ~5–15 ms (cache lookup).

### 2. `DashboardController::recentActivity` rewrite
Was: 4 separate queries × `limit` rows + PHP `sortByDesc`.
Now: one `UNION ALL` ordered + limited at the DB. Roughly 4× fewer
round-trips, no PHP-side sort of 40 rows.

### 3. `DashboardController::stats` is cached for 60 s
The 6 `COUNT(*)` / `SUM(...)` queries it runs only re-execute once a
minute even if the dashboard auto-polls.

## Deploy steps on your VPS

```bash
cd /var/www/flehty                 # or wherever the repo lives
git pull

# Make sure the cache driver is "file" (default) or "redis"
grep -E '^CACHE_(STORE|DRIVER)=' .env || echo 'CACHE_STORE=file' >> .env

# Rebuild Laravel caches — this is the SINGLE biggest perf win
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache
php artisan optimize

# Composer autoloader in production mode (skip if already done)
composer install --no-dev --optimize-autoloader --classmap-authoritative

# Restart PHP-FPM so OPcache picks up the new files
sudo systemctl reload php8.2-fpm
```

## OPcache (huge win, do this once)

Edit `/etc/php/8.2/fpm/conf.d/10-opcache.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0     ; production: don't restat files
opcache.save_comments=1
opcache.fast_shutdown=1
```

Then:
```bash
sudo systemctl reload php8.2-fpm
```

> With `validate_timestamps=0` you MUST run `systemctl reload php8.2-fpm`
> after every deploy, otherwise PHP will keep serving the old bytecode.

## Optional next-level wins (not done here)

* Switch `CACHE_STORE=redis` and install Redis — file cache is fine for
  one server but Redis is ~5× faster and shared across workers.
* Add `gzip on;` (or `brotli`) in your nginx vhost — report JSON
  payloads compress 10–20×.
* Enable HTTP/2 in nginx if not already (`listen 443 ssl http2;`).

## Verifying the cache works

```bash
curl -sI -H "Authorization: Bearer <token>" \
  https://api.flehty.com/api/dashboard/stats | grep -i x-cache
# 1st call → X-Cache: MISS
# 2nd call → X-Cache: HIT
```
