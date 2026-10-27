#!/usr/bin/env bash
# Dump the Rafeeq database to /var/backups/rafeeq and keep the last 14 days.
# Installed by setup-server.sh as /usr/local/sbin/rafeeq-backup-db (runs daily).
#
# Restore:
#   gunzip -c /var/backups/rafeeq/assistant-YYYYmmdd-HHMM.sql.gz | sudo mysql assistant

set -euo pipefail

DIR="/var/backups/rafeeq"
KEEP_DAYS=14

install -d -m 700 "$DIR"
file="${DIR}/assistant-$(date +%Y%m%d-%H%M).sql.gz"

# Root connects through the local socket, so no password is needed here.
mysqldump --single-transaction --routines --triggers assistant | gzip -9 > "${file}.part"
mv "${file}.part" "$file"
chmod 600 "$file"

find "$DIR" -name 'assistant-*.sql.gz' -mtime "+${KEEP_DAYS}" -delete
