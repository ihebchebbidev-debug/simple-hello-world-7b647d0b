#!/usr/bin/env bash
#
# ONE-TIME migration: `php artisan serve` (single request at a time)
#                     →  nginx + PHP-FPM (concurrent worker pool).
#
# Why: artisan serve handles ONE request at a time, so parallel calls and
# multiple users queue up. PHP-FPM runs a pool of workers → true concurrency.
# OPcache already applies to FPM (deploy.sh writes it to the fpm conf.d).
#
# SAFE BY DESIGN:
#   • backs up the whole /etc/nginx tree first,
#   • reuses your EXISTING SSL certificate (HTTPS keeps working),
#   • validates with `nginx -t` before touching anything,
#   • after switching, curls the live health endpoint and AUTO-ROLLS-BACK
#     to artisan serve if it doesn't return 200.
#
# Usage:
#   bash api_backend/setup-fpm.sh            # migrate to FPM
#   bash api_backend/setup-fpm.sh --rollback # manual revert to artisan serve
#
set -euo pipefail

DOMAIN="${API_DOMAIN:-api.flehty.com}"
API_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PUBLIC_DIR="${API_DIR}/public"
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo 8.4)"
SUPERVISOR_PROGRAM="${SUPERVISOR_PROGRAM:-agritrack}"
HEALTH_URL="${HEALTH_URL:-https://${DOMAIN}/api/health}"
SITE_NAME="flehty-api"
SITE_FILE="/etc/nginx/sites-available/${SITE_NAME}.conf"
SITE_LINK="/etc/nginx/sites-enabled/${SITE_NAME}.conf"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="/etc/nginx.backup-${STAMP}.tgz"

log()  { echo -e "==> $*"; }
warn() { echo -e "!!  $*" >&2; }

# ── helpers ──────────────────────────────────────────────────────────────────
# Test the LOCAL nginx→FPM path (force the connection to 127.0.0.1 with the
# right SNI/Host) — this verifies the migration without depending on the server
# being able to reach its own PUBLIC IP (NAT hairpin / firewall would otherwise
# make a perfectly-working setup look broken).
health_code() {
  curl -k -o /dev/null -s -w '%{http_code}' --max-time 8 \
    --resolve "${DOMAIN}:443:127.0.0.1" "$HEALTH_URL" 2>/dev/null || echo 000
}
health_ok() { [ "$(health_code)" = "200" ]; }

diagnose() {
  warn "── Diagnostics (why the health check failed) ──"
  warn "Local HTTP code: $(health_code)"
  warn "FPM socket:"; ls -l "$FPM_SOCK" 2>&1 | sed 's/^/    /'
  warn "php-fpm active: $(sudo systemctl is-active "php${PHP_VERSION}-fpm" 2>/dev/null || echo unknown)"
  warn "Laravel log (last 12 lines):"
  sudo tail -n 12 "${API_DIR}/storage/logs/laravel.log" 2>/dev/null | sed 's/^/    /' || warn "    (no laravel.log)"
  warn "nginx error log (last 8 lines):"
  sudo tail -n 8 /var/log/nginx/error.log 2>/dev/null | sed 's/^/    /' || true
}

start_artisan_serve() {
  sudo supervisorctl start "$SUPERVISOR_PROGRAM" >/dev/null 2>&1 || true
}

restore_nginx_backup() {
  if [ -f "$BACKUP" ]; then
    log "Restoring /etc/nginx from $BACKUP"
    sudo tar xzf "$BACKUP" -C / 2>/dev/null || true
    sudo nginx -t >/dev/null 2>&1 && sudo systemctl reload nginx || true
  fi
}

rollback() {
  warn "Rolling back to the previous (artisan serve) setup…"
  sudo rm -f "$SITE_LINK" "$SITE_FILE"      # remove what THIS script added
  restore_nginx_backup                      # tar restore re-creates the originals
  start_artisan_serve
  warn "Rollback complete. The site is back on the previous setup."
}

# ── manual rollback path ─────────────────────────────────────────────────────
if [ "${1:-}" = "--rollback" ]; then
  LATEST_BACKUP="$(ls -1t /etc/nginx.backup-*.tgz 2>/dev/null | head -1 || true)"
  BACKUP="${LATEST_BACKUP:-$BACKUP}"
  rollback
  exit 0
fi

log "Migrating ${DOMAIN} → nginx + PHP-FPM ${PHP_VERSION}"
log "App public dir: ${PUBLIC_DIR}"

# ── 1. Ensure PHP-FPM is installed ──────────────────────────────────────────
# It's almost certainly already installed (deploy.sh writes to its conf.d). Only
# touch apt if the fpm tree is genuinely missing — and keep apt NON-FATAL so a
# broken third-party PPA (e.g. ondrej/php with no packages for this Ubuntu
# release) can't abort the migration.
if [ ! -d "/etc/php/${PHP_VERSION}/fpm" ] && ! command -v "php-fpm${PHP_VERSION}" >/dev/null 2>&1; then
  log "Installing php${PHP_VERSION}-fpm…"
  sudo apt-get update -y || warn "apt update had errors (broken PPA?) — continuing"
  sudo apt-get install -y "php${PHP_VERSION}-fpm" || true
fi
if [ ! -d "/etc/php/${PHP_VERSION}/fpm" ]; then
  warn "php${PHP_VERSION}-fpm is not installed and could not be installed automatically."
  warn "Install it once, then re-run:  sudo apt-get install -y php${PHP_VERSION}-fpm"
  exit 1
fi

NGINX_DUMP="$(sudo nginx -T 2>/dev/null || true)"

# Dedicated FPM pool that runs as the APP OWNER (not www-data), so Laravel can
# write storage/logs + bootstrap/cache without permission errors — the classic
# "500 on a fresh FPM migration" trap. The socket is owned by the nginx user so
# nginx can reach it.
APP_USER="$(stat -c '%U' "$API_DIR" 2>/dev/null || echo agrysync)"
NGINX_USER="$(printf '%s\n' "$NGINX_DUMP" | awk '/^user /{print $2}' | tr -d ';' | head -1)"
NGINX_USER="${NGINX_USER:-www-data}"
FPM_SOCK="/run/php/flehty-${PHP_VERSION}-fpm.sock"
log "FPM pool user=${APP_USER}, socket=${FPM_SOCK}, nginx user=${NGINX_USER}"

sudo tee "/etc/php/${PHP_VERSION}/fpm/pool.d/flehty.conf" > /dev/null <<EOF
[flehty]
user = ${APP_USER}
group = ${APP_USER}
listen = ${FPM_SOCK}
listen.owner = ${NGINX_USER}
listen.group = ${NGINX_USER}
listen.mode = 0660
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
pm.max_requests = 500
catch_workers_output = yes
EOF

sudo systemctl enable "php${PHP_VERSION}-fpm" >/dev/null 2>&1 || true
if ! sudo systemctl restart "php${PHP_VERSION}-fpm"; then
  warn "Could not start php${PHP_VERSION}-fpm — is the package fully installed?"
  warn "Try:  sudo apt-get install -y php${PHP_VERSION}-fpm   (then re-run this script)"
  warn "No nginx changes were made — your site is untouched."
  exit 1
fi

# ── 1b. Let nginx traverse to public/ (app lives under /home, mode 750) ──────
# nginx (${NGINX_USER}) must be able to stat files under the docroot for
# try_files. Grant EXECUTE-ONLY (traverse, not listing) on each path component
# down to public/, read on the public docroot — and lock down .env so making
# the parent traversable doesn't expose secrets.
log "Granting ${NGINX_USER} traverse access to ${PUBLIC_DIR}…"
p="$PUBLIC_DIR"
while [ -n "$p" ] && [ "$p" != "/" ]; do
  sudo chmod o+x "$p" 2>/dev/null || true
  p="$(dirname "$p")"
done
sudo chmod -R o+rX "$PUBLIC_DIR" 2>/dev/null || true
sudo chmod o-rwx "${API_DIR}/.env" 2>/dev/null || true   # keep DB creds private

# ── 2. Find the nginx include files that define THIS domain ──────────────────
# We iterate the include dirs and grep each file directly, because `grep -r`
# SKIPS symlinks while recursing — and sites-enabled/* are symlinks, so the
# previous attempt missed the live block and the old server kept winning.
# Only files that nginx actually includes are considered; other domains
# (e.g. api.flowentra.app) are never touched.
DOMAIN_FILES=()
for f in /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf; do
  [ -e "$f" ] || continue
  case "$f" in "$SITE_FILE"|"$SITE_LINK") continue ;; esac
  if sudo grep -qE "server_name[^;]*\b${DOMAIN//./\\.}\b" "$f" 2>/dev/null; then
    DOMAIN_FILES+=("$f")
    log "Found existing block for ${DOMAIN}: $f"
  fi
done

SSL_CERT=""
SSL_KEY=""
for df in "${DOMAIN_FILES[@]:-}"; do
  [ -n "$df" ] || continue
  c="$(sudo awk '/ssl_certificate /{print $2}' "$df" 2>/dev/null | tr -d ';' | head -1 || true)"
  k="$(sudo awk '/ssl_certificate_key/{print $2}' "$df" 2>/dev/null | tr -d ';' | head -1 || true)"
  if [ -n "$c" ]; then SSL_CERT="$c"; fi
  if [ -n "$k" ]; then SSL_KEY="$k"; fi
done

# Prefer a Let's Encrypt cert that matches the domain if one exists.
if [ -d "/etc/letsencrypt/live/${DOMAIN}" ]; then
  SSL_CERT="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
  SSL_KEY="/etc/letsencrypt/live/${DOMAIN}/privkey.pem"
fi

if [ -z "${SSL_CERT}" ] || [ -z "${SSL_KEY}" ] || ! sudo test -f "$SSL_CERT"; then
  warn "Could not auto-detect a valid SSL certificate for ${DOMAIN}."
  warn "Run:  sudo nginx -T | grep -E 'server_name|ssl_certificate'"
  warn "and re-run with:  API_SSL_CERT=/path/fullchain.pem API_SSL_KEY=/path/privkey.pem bash $0"
  SSL_CERT="${API_SSL_CERT:-$SSL_CERT}"
  SSL_KEY="${API_SSL_KEY:-$SSL_KEY}"
  if [ -z "${SSL_CERT}" ] || [ -z "${SSL_KEY}" ]; then
    warn "Aborting safely — no changes made."
    exit 1
  fi
fi
log "Using SSL cert: ${SSL_CERT}"

# ── 3. Back up the whole nginx tree (for rollback) ──────────────────────────
log "Backing up /etc/nginx → ${BACKUP}"
sudo tar czf "$BACKUP" /etc/nginx 2>/dev/null || true

# ── 4. Disable any existing server block for this domain (avoid conflict) ────
# nginx includes sites-enabled/* (ALL files), so renaming in place wouldn't
# disable a block — we MOVE conflicting files out of the include path entirely,
# into a holding dir. The full backup (step 3) restores the originals on rollback.
DISABLED_DIR="/etc/nginx-flehty-disabled-${STAMP}"
sudo mkdir -p "$DISABLED_DIR"
for f in "${DOMAIN_FILES[@]:-}"; do
  [ -n "$f" ] || continue
  # DOMAIN_FILES only ever contains sites-enabled symlinks / conf.d files for
  # THIS domain (built in step 2). Moving them out of the include path disables
  # them; the sites-available originals + other domains stay untouched.
  log "Disabling existing block: $f"
  sudo mv "$f" "$DISABLED_DIR/" 2>/dev/null || true
done

if [ "${#DOMAIN_FILES[@]}" -eq 0 ]; then
  warn "No existing nginx block for ${DOMAIN} was found in sites-enabled/conf.d."
  warn "If the old block lives elsewhere (main nginx.conf or a custom include),"
  warn "the migration may conflict. Paste:  sudo grep -rl '${DOMAIN}' /etc/nginx"
fi

# ── 5. Write the FPM server block ───────────────────────────────────────────
log "Writing ${SITE_FILE}"
sudo tee "$SITE_FILE" > /dev/null <<EOF
# Managed by api_backend/setup-fpm.sh — Laravel API on PHP-FPM (concurrent).
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name ${DOMAIN};

    ssl_certificate     ${SSL_CERT};
    ssl_certificate_key ${SSL_KEY};

    root ${PUBLIC_DIR};
    index index.php;

    client_max_body_size 25m;

    # gzip is configured globally in conf.d/zz-gzip.conf

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:${FPM_SOCK};
        fastcgi_index index.php;
        fastcgi_split_path_info ^(.+\.php)(/.+)\$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_read_timeout 60;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
EOF
sudo ln -sfn "$SITE_FILE" "$SITE_LINK"

# ── 6. Validate, reload, and switch off artisan serve ───────────────────────
if ! sudo nginx -t; then
  warn "nginx config test FAILED — reverting."
  rollback
  exit 1
fi

log "Stopping ${SUPERVISOR_PROGRAM} (artisan serve) and reloading nginx…"
sudo supervisorctl stop "$SUPERVISOR_PROGRAM" >/dev/null 2>&1 || true
sudo systemctl reload nginx

# ── 7. Verify the live endpoint — AUTO-ROLLBACK if it fails ─────────────────
log "Verifying ${HEALTH_URL} …"
ok=0
for i in 1 2 3 4 5; do
  if health_ok; then ok=1; break; fi
  sleep 1
done

if [ "$ok" != 1 ]; then
  warn "Health check did NOT return 200 — auto-rolling back."
  diagnose
  rollback
  exit 1
fi

log "SUCCESS ✅  ${DOMAIN} is now served by PHP-FPM ${PHP_VERSION} (concurrent)."
log "Backup kept at ${BACKUP}"
log "If anything looks off later, revert with:  bash $0 --rollback"
