#!/usr/bin/env bash
# Back up the Rafeeq database and attached images to /var/backups/rafeeq and
# keep the last 14 days. Installed by setup-server.sh as
# /usr/local/sbin/rafeeq-backup-db (runs daily).
#
# Restore:
#   gunzip -c /var/backups/rafeeq/assistant-YYYYmmdd-HHMM.sql.gz | sudo mysql assistant
#   sudo tar -xzf /var/backups/rafeeq/images-YYYYmmdd-HHMM.tar.gz -C /var/www/rafeeq/backend/storage/app/private

set -euo pipefail

DIR="/var/backups/rafeeq"
KEEP_DAYS=14
STAMP="$(date +%Y%m%d-%H%M)"
IMAGES_PARENT="/var/www/rafeeq/backend/storage/app/private"

install -d -m 700 "$DIR"
file="${DIR}/assistant-${STAMP}.sql.gz"

# Root connects through the local socket, so no password is needed here.
mysqldump --single-transaction --routines --triggers assistant | gzip -9 > "${file}.part"
mv "${file}.part" "$file"
chmod 600 "$file"

if [[ -d "${IMAGES_PARENT}/images" ]]; then
    images="${DIR}/images-${STAMP}.tar.gz"
    tar -czf "${images}.part" -C "$IMAGES_PARENT" images
    mv "${images}.part" "$images"
    chmod 600 "$images"
fi

find "$DIR" \( -name 'assistant-*.sql.gz' -o -name 'images-*.tar.gz' \) -mtime "+${KEEP_DAYS}" -delete
