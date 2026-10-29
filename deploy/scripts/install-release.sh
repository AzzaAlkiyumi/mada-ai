#!/usr/bin/env bash
# Install or update Rafeeq from a release archive made by package-release.ps1.
#
#   sudo bash install-release.sh /root/rafeeq-release.tar.gz
#
# The database, .env and storage/ are kept between updates.

set -euo pipefail

ARCHIVE="${1:-}"
APP_DIR="/var/www/rafeeq"
SECRETS="/etc/rafeeq/secrets.env"

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
die() { printf '\033[1;31mError: %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run as root."
[[ -f "$ARCHIVE" ]] || die "Usage: sudo bash $0 /path/to/rafeeq-release.tar.gz"
[[ -f "$SECRETS" ]] || die "$SECRETS not found. Run setup-server.sh first."
# shellcheck disable=SC1090
. "$SECRETS"

export COMPOSER_ALLOW_SUPERUSER=1
artisan() { php "${APP_DIR}/backend/artisan" "$@"; }

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

log "Unpacking $(basename "$ARCHIVE")"
# Archives built on Windows carry world-writable modes; reset them.
umask 022
tar -xzf "$ARCHIVE" -C "$tmp" --no-same-permissions --no-same-owner
find "$tmp" -type d -exec chmod 755 {} +
find "$tmp" -type f -exec chmod 644 {} +
chmod 755 "${tmp}/backend/artisan" 2>/dev/null || true
[[ -f "${tmp}/backend/artisan" && -f "${tmp}/frontend-dist/index.html" ]] || die "Not a Rafeeq release archive."

first_install=false
[[ -f "${APP_DIR}/backend/.env" ]] || first_install=true

if ! $first_install; then
    artisan down --retry=30 || true
fi

log "Copying files"
install -d -m 755 "${APP_DIR}/backend" "${APP_DIR}/frontend"
command -v rsync >/dev/null || apt-get install -yq rsync
rsync -a --delete \
    --exclude '/.env' --exclude '/storage/' --exclude '/bootstrap/cache/' --exclude '/vendor/' \
    "${tmp}/backend/" "${APP_DIR}/backend/"
rsync -a --delete "${tmp}/frontend-dist/" "${APP_DIR}/frontend/"

cd "${APP_DIR}/backend"
install -d storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

if $first_install; then
    log "Creating .env from .env.production.example"
    sed -e "s|__DOMAIN__|${DOMAIN}|g" \
        -e "s|__DB_DATABASE__|${DB_DATABASE}|g" \
        -e "s|__DB_USERNAME__|${DB_USERNAME}|g" \
        -e "s|__DB_PASSWORD__|${DB_PASSWORD}|g" \
        -e "s|__LLM_API_KEY__|${LLM_API_KEY}|g" \
        -e "s|__LLM_MODEL__|${LLM_MODEL}|g" \
        .env.production.example > .env
    chmod 640 .env
fi

log "Installing PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

if $first_install; then
    artisan key:generate --force
fi

log "Migrating the database"
artisan migrate --force

log "Caching configuration"
artisan optimize

log "Setting permissions"
chown -R root:root "${APP_DIR}"
chown root:www-data .env
chmod 640 .env
chown -R www-data:www-data storage bootstrap/cache

systemctl reload "php${PHP_VERSION}-fpm"
artisan up

log "Checking the site"
# Once HTTPS is on, plain HTTP only redirects, so ask over HTTPS (falling back to HTTP before certbot).
code="$(curl -sk -o /dev/null -w '%{http_code}' --resolve "${DOMAIN}:443:127.0.0.1" -H 'Accept: application/json' "https://${DOMAIN}/api/me" || true)"
[[ "$code" == "000" ]] && code="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${DOMAIN}" -H 'Accept: application/json' http://127.0.0.1/api/me || true)"
if [[ "$code" == "401" ]]; then
    echo "API is answering (401 = sign-in required, as expected)."
else
    echo "Warning: expected HTTP 401 from /api/me, got ${code}. Check: tail -n 50 ${APP_DIR}/backend/storage/logs/*.log"
fi

if $first_install; then
    cat <<EOF

First install done. Create your account:
  cd ${APP_DIR}/backend && sudo -u www-data php artisan user:create
EOF
fi
