#!/usr/bin/env bash
# QuaverDesk — nightly encrypted backup of the database, recordings and .env.
# Install a root-owned copy and run that (cron.d entry provided):
#   sudo install -o root -g root -m 700 deploy/backup.sh /usr/local/sbin/quaverdesk-backup
# Requires: age (apt install age).
#
# The archive is encrypted to an age PUBLIC key, so this server cannot read its
# own backups — only whoever holds the matching private key can. Copy the
# files in $BACKUP_DIR off the server (e.g. with rclone) — a backup on the same
# disk is not a backup.
set -euo pipefail
umask 077

APP_DIR="${APP_DIR:-/srv/quaverdesk}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/quaverdesk}"
RECIPIENT_FILE="${RECIPIENT_FILE:-/etc/quaverdesk/backup-recipient.txt}"
MYSQL_DEFAULTS="${MYSQL_DEFAULTS:-/etc/quaverdesk/backup.my.cnf}"
KEEP_DAYS="${KEEP_DAYS:-14}"

# Value of a .env setting, or empty if it isn't set (never fails the script).
env_value() { { grep -E "^$1=" "$APP_DIR/.env" || true; } | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

[[ -r "$RECIPIENT_FILE" ]] || { echo "Missing $RECIPIENT_FILE (an age public key, age1…)" >&2; exit 1; }
command -v age >/dev/null || { echo "Install age: apt install age" >&2; exit 1; }

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$BACKUP_DIR"

DB_CONNECTION="$(env_value DB_CONNECTION)"
case "$DB_CONNECTION" in
    mysql|mariadb)
        mysqldump --defaults-extra-file="$MYSQL_DEFAULTS" --single-transaction --routines --no-tablespaces \
            "$(env_value DB_DATABASE)" > "$WORK/database.sql"
        ;;
    sqlite)
        DB_FILE="$(env_value DB_DATABASE)"
        DB_FILE="${DB_FILE:-$APP_DIR/database/database.sqlite}"
        sqlite3 "$DB_FILE" ".backup '$WORK/database.sqlite'"
        ;;
    *)
        echo "Unsupported DB_CONNECTION: $DB_CONNECTION" >&2; exit 1 ;;
esac

# Recordings (already encrypted with APP_KEY) and the .env holding APP_KEY.
tar -C "$APP_DIR" -cf "$WORK/files.tar" .env storage/app/private

tar -C "$WORK" -czf - . | age -R "$RECIPIENT_FILE" > "$BACKUP_DIR/quaverdesk-$STAMP.tar.gz.age"

find "$BACKUP_DIR" -name 'quaverdesk-*.tar.gz.age' -mtime +"$KEEP_DAYS" -delete

echo "$(date -u +%FT%TZ) backup ok: $BACKUP_DIR/quaverdesk-$STAMP.tar.gz.age"
