#!/usr/bin/env bash
# One-time setup of a fresh Ubuntu 26.04 / 24.04 server for Rafeeq.
#
#   sudo bash setup-server.sh your-domain.example
#
# Installs Nginx, PHP-FPM, MySQL, llama.cpp and the model; creates the
# database, secrets, firewall and services. Safe to re-run: steps that are
# already done are skipped. Generated secrets are stored in /etc/rafeeq/
# (root only) and are never printed.

set -euo pipefail

DOMAIN="${1:-}"
LLAMA_BUILD="b11853"
LLAMA_URL="https://github.com/ggml-org/llama.cpp/releases/download/${LLAMA_BUILD}/llama-${LLAMA_BUILD}-bin-ubuntu-x64.tar.gz"
MODEL_FILE="Qwen3.5-4B-Q4_K_M.gguf"
MODEL_URL="https://huggingface.co/unsloth/Qwen3.5-4B-GGUF/resolve/main/${MODEL_FILE}"
MODEL_SHA256="00fe7986ff5f6b463e62455821146049db6f9313603938a70800d1fb69ef11a4"
MODEL_ALIAS="qwen3.5-4b"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG="${HERE}/../config"
APP_DIR="/var/www/rafeeq"
SECRETS="/etc/rafeeq/secrets.env"

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
die() { printf '\033[1;31mError: %s\033[0m\n' "$*" >&2; exit 1; }
random_secret() { openssl rand -hex 24; }

[[ $EUID -eq 0 ]] || die "Run as root (sudo bash $0 your-domain)."
[[ -n "$DOMAIN" ]] || die "Usage: sudo bash $0 your-domain.example"
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || die "Invalid domain: $DOMAIN"
[[ "$(uname -m)" == "x86_64" ]] || die "This script expects an x86_64 server."

. /etc/os-release
[[ "$ID" == "ubuntu" ]] || die "This script supports Ubuntu only (found $ID)."

export DEBIAN_FRONTEND=noninteractive
# Never stop for questions: keep existing config files, restart services automatically.
export NEEDRESTART_MODE=a
export LC_ALL=C.UTF-8
APT_OPTS=(-yq -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)

log "Updating the system"
apt-get update -q
apt-get upgrade "${APT_OPTS[@]}"

log "Installing base packages"
apt-get install "${APT_OPTS[@]}" nginx mysql-server ufw unzip curl rsync ca-certificates openssl \
    libgomp1 libcurl4 unattended-upgrades certbot python3-certbot-nginx

# Laravel 13's dependencies need PHP 8.4+. Ubuntu 26.04 ships 8.5;
# Ubuntu 24.04 ships 8.3, so there we add the widely used ondrej/php PPA.
PHP_VERSION="$(apt-cache policy php-fpm 2>/dev/null | awk '/Candidate:/ {print $2}' | sed -E 's/^[0-9]+://; s/^([0-9]+\.[0-9]+).*/\1/')"
if [[ -z "$PHP_VERSION" ]] || ! dpkg --compare-versions "$PHP_VERSION" ge 8.4; then
    log "Ubuntu's PHP (${PHP_VERSION:-none}) is too old; adding ppa:ondrej/php for PHP 8.5"
    apt-get install "${APT_OPTS[@]}" software-properties-common
    add-apt-repository -y ppa:ondrej/php
    apt-get update -q
    PHP_VERSION="8.5"
fi

log "Installing PHP ${PHP_VERSION}"
apt-get install "${APT_OPTS[@]}" "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mysql" \
    "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" \
    "php${PHP_VERSION}-zip" "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-intl"
apt-get install "${APT_OPTS[@]}" composer
# The composer package may pull in Ubuntu's default PHP; make sure `php` is ours.
update-alternatives --set php "/usr/bin/php${PHP_VERSION}"

log "Adding 2 GB swap (safety margin for an 8 GB server)"
if ! swapon --show | grep -q .; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    sysctl -w vm.swappiness=10 >/dev/null
    echo 'vm.swappiness=10' > /etc/sysctl.d/99-rafeeq.conf
fi

log "Creating secrets (stored in /etc/rafeeq, root only)"
install -d -m 750 /etc/rafeeq
if [[ ! -f "$SECRETS" ]]; then
    umask 077
    cat > "$SECRETS" <<EOF
DOMAIN=${DOMAIN}
PHP_VERSION=${PHP_VERSION}
DB_DATABASE=assistant
DB_USERNAME=assistant
DB_PASSWORD=$(random_secret)
LLM_API_KEY=$(random_secret)
LLM_MODEL=${MODEL_ALIAS}
EOF
    umask 022
fi
chmod 600 "$SECRETS"
# shellcheck disable=SC1090
. "$SECRETS"

log "Configuring MySQL"
install -m 644 "${CONFIG}/mysql-rafeeq.cnf" /etc/mysql/mysql.conf.d/zz-rafeeq.cnf
systemctl restart mysql
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USERNAME}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'localhost';
FLUSH PRIVILEGES;
SQL

log "Tuning PHP-FPM for a small server"
POOL="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
sed -i -E \
    -e 's/^;?pm = .*/pm = ondemand/' \
    -e 's/^;?pm.max_children = .*/pm.max_children = 4/' \
    -e 's/^;?pm.process_idle_timeout = .*/pm.process_idle_timeout = 30s/' \
    -e 's/^;?request_terminate_timeout = .*/request_terminate_timeout = 360/' \
    "$POOL"
grep -q '^request_terminate_timeout' "$POOL" || echo 'request_terminate_timeout = 360' >> "$POOL"
systemctl restart "php${PHP_VERSION}-fpm"

log "Installing llama.cpp ${LLAMA_BUILD}"
id llama >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin llama
install -d -m 755 /opt/llama /opt/llama/models
if [[ ! -x /opt/llama/bin/llama-server ]] || [[ "$(cat /opt/llama/BUILD 2>/dev/null)" != "$LLAMA_BUILD" ]]; then
    tmp="$(mktemp -d)"
    curl -fsSL "$LLAMA_URL" -o "${tmp}/llama.tar.gz"
    tar -xzf "${tmp}/llama.tar.gz" -C "$tmp"
    server_bin="$(find "$tmp" -type f -name llama-server | head -n 1)"
    [[ -n "$server_bin" ]] || die "llama-server not found in the release archive."
    rm -rf /opt/llama/bin
    cp -r "$(dirname "$server_bin")" /opt/llama/bin
    echo "$LLAMA_BUILD" > /opt/llama/BUILD
    rm -rf "$tmp"
fi

log "Downloading the model (${MODEL_FILE}, about 2.7 GB)"
MODEL_PATH="/opt/llama/models/${MODEL_FILE}"
if [[ ! -f "$MODEL_PATH" ]] || ! echo "${MODEL_SHA256}  ${MODEL_PATH}" | sha256sum -c --quiet - 2>/dev/null; then
    curl -fL --retry 3 -C - "$MODEL_URL" -o "${MODEL_PATH}.part"
    echo "${MODEL_SHA256}  ${MODEL_PATH}.part" | sha256sum -c --quiet - || die "Model checksum mismatch."
    mv "${MODEL_PATH}.part" "$MODEL_PATH"
fi
chown -R root:root /opt/llama
chmod -R a+rX /opt/llama

log "Configuring the model service"
umask 027
cat > /etc/rafeeq/llama.env <<EOF
LLAMA_API_KEY=${LLM_API_KEY}
LLAMA_MODEL_FILE=${MODEL_FILE}
LLAMA_ALIAS=${MODEL_ALIAS}
LLAMA_CTX=8192
LLAMA_THREADS=$(nproc)
EOF
umask 022
chown root:llama /etc/rafeeq/llama.env
chmod 640 /etc/rafeeq/llama.env
chown root:llama /etc/rafeeq
install -m 644 "${CONFIG}/llama-server.service" /etc/systemd/system/llama-server.service
systemctl daemon-reload
systemctl enable --now llama-server

log "Configuring Nginx for ${DOMAIN}"
install -d -m 755 "$APP_DIR" "${APP_DIR}/frontend"
sed -e "s/__DOMAIN__/${DOMAIN}/g" -e "s/__PHP_VERSION__/${PHP_VERSION}/g" \
    "${CONFIG}/nginx-rafeeq.conf" > /etc/nginx/sites-available/rafeeq
ln -sf /etc/nginx/sites-available/rafeeq /etc/nginx/sites-enabled/rafeeq
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

log "Firewall: only SSH, HTTP and HTTPS are open"
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

log "Daily database backup at 03:30"
install -m 750 "${HERE}/backup-db.sh" /usr/local/sbin/rafeeq-backup-db
echo '30 3 * * * root /usr/local/sbin/rafeeq-backup-db >/dev/null 2>&1' > /etc/cron.d/rafeeq-backup
chmod 644 /etc/cron.d/rafeeq-backup

log "Automatic security updates"
dpkg-reconfigure -f noninteractive unattended-upgrades

log "Waiting for the model service to answer"
for _ in $(seq 1 60); do
    if curl -fs -o /dev/null http://127.0.0.1:8080/health; then
        echo "Model service is up."
        break
    fi
    sleep 2
done
systemctl --no-pager --lines=0 status llama-server || true

cat <<EOF

Setup finished.
Next:
  1. Upload a release and run:  sudo bash install-release.sh /path/to/rafeeq-release.tar.gz
  2. Create your account:       cd ${APP_DIR}/backend && sudo -u www-data php artisan user:create
  3. Enable HTTPS:              sudo certbot --nginx -d ${DOMAIN}
EOF
