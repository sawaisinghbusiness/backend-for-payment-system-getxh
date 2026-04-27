#!/bin/bash
# ============================================================
# backup.sh — MySQL dump for upi_wallet
# Usage:  ./backup.sh
# Cron:   0 2 * * * /var/www/html/backup.sh
# ============================================================

set -euo pipefail

# Load credentials from .env if present
ENV_FILE="$(dirname "$0")/.env"
if [ -f "$ENV_FILE" ]; then
    export $(grep -v '^#' "$ENV_FILE" | grep -E '^DB_' | xargs)
fi

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-upi_wallet}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"

BACKUP_DIR="$(dirname "$0")/backups"
mkdir -p "$BACKUP_DIR"

DATE=$(date +%F-%H-%M)
OUTPUT="$BACKUP_DIR/db-$DATE.sql"

mysqldump \
    -h "$DB_HOST" \
    -P "$DB_PORT" \
    -u "$DB_USER" \
    ${DB_PASS:+-p"$DB_PASS"} \
    --single-transaction \
    --routines \
    --triggers \
    "$DB_NAME" > "$OUTPUT"

# Keep only last 30 backups
ls -1t "$BACKUP_DIR"/db-*.sql | tail -n +31 | xargs -r rm --

echo "Backup saved: $OUTPUT"
