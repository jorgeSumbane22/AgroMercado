#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-$ROOT/backups}"
DB_HOST="${AGRO_DB_HOST:-127.0.0.1}"
DB_NAME="${AGRO_DB_NAME:-agro_marketplace}"
DB_USER="${AGRO_DB_USER:-root}"
DB_PASS="${AGRO_DB_PASS:-}"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"
DB_FILE="$BACKUP_DIR/${DB_NAME}_${STAMP}.sql.gz"
UPLOAD_FILE="$BACKUP_DIR/uploads_${STAMP}.tar.gz"
if [[ -n "$DB_PASS" ]]; then MYSQL_PWD="$DB_PASS" mysqldump --single-transaction --routines --events -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" | gzip -9 > "$DB_FILE"; else mysqldump --single-transaction --routines --events -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" | gzip -9 > "$DB_FILE"; fi
tar -czf "$UPLOAD_FILE" -C "$ROOT" uploads
sha256sum "$DB_FILE" "$UPLOAD_FILE" > "$BACKUP_DIR/${STAMP}.sha256"
find "$BACKUP_DIR" -type f -mtime +30 -delete
echo "Backup concluído: $DB_FILE e $UPLOAD_FILE"
