#!/usr/bin/env bash
# QuaverDesk — update an existing install to the latest code on its branch.
# Install a root-owned copy once, and run that — never run a root script from
# the app folder, which the app user can write to:
#   sudo install -o root -g root -m 700 deploy/deploy.sh /usr/local/sbin/quaverdesk-deploy
#   sudo quaverdesk-deploy
#
# Calls keep being answered during the brief maintenance window (voice
# webhooks are exempt from maintenance mode). Deploy out of hours anyway.
set -euo pipefail

APP_DIR="${APP_DIR:-/srv/quaverdesk}"
APP_USER="${APP_USER:-quaverdesk}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"

as_app() { sudo -u "$APP_USER" -H bash -c "cd '$APP_DIR' && $*"; }

if [[ $EUID -ne 0 ]]; then
    echo "Run this script with sudo." >&2
    exit 1
fi

# A backup first, so a bad update can be undone. If the backup script is installed and fails, nothing is
# changed. If it isn't installed at all, say so loudly and carry on (set SKIP_BACKUP=1 to skip it on purpose).
BACKUP_CMD="${BACKUP_CMD:-/usr/local/sbin/quaverdesk-backup}"
if [[ "${SKIP_BACKUP:-0}" != "1" ]]; then
    if [[ -x "$BACKUP_CMD" ]]; then
        echo "==> Backing up first"
        "$BACKUP_CMD" || { echo "    The backup failed, so nothing has been changed. Fix it, or run with SKIP_BACKUP=1 if you took one yourself." >&2; exit 1; }
    else
        echo "==> WARNING: no backup was taken: $BACKUP_CMD isn't installed (docs/DEPLOYMENT.md, step A8)." >&2
        echo "    If this update goes wrong, you'll have nothing to restore. Continuing in 10 seconds: press Ctrl-C to stop." >&2
        sleep 10
    fi
fi

echo "==> Fetching code"
as_app "git pull --ff-only"

echo "==> Installing PHP dependencies"
as_app "composer install --no-dev --optimize-autoloader --no-interaction --no-progress"

echo "==> Building front-end assets"
if [[ -f "$APP_DIR/package-lock.json" ]]; then
    as_app "npm ci --no-audit --no-fund && npm run build"
else
    echo "    (no package-lock.json committed — using npm install; commit a lock file for repeatable builds)"
    as_app "npm install --no-audit --no-fund && npm run build"
fi

echo "==> Entering maintenance mode"
as_app "php artisan down --retry=15"
trap 'as_app "php artisan up" || true' EXIT

echo "==> Running database migrations"
as_app "php artisan migrate --force"

echo "==> Caching config, routes and views"
as_app "php artisan optimize"

echo "==> Leaving maintenance mode"
as_app "php artisan up"
trap - EXIT

echo "==> Reloading PHP and restarting the worker"
systemctl reload "$PHP_FPM_SERVICE"
as_app "php artisan queue:restart"

APP_URL="$({ grep -E '^APP_URL=' "$APP_DIR/.env" || true; } | tail -n1 | cut -d= -f2-)"
VERSION_NOW="$(cat "$APP_DIR/VERSION" 2>/dev/null || echo "(no version)")"

echo "==> Health check"
HEALTH_OK=1
if [[ -n "$APP_URL" ]]; then
    for attempt in 1 2 3 4 5; do
        if curl -fsS --max-time 10 -o /dev/null "${APP_URL%/}/up"; then HEALTH_OK=0; break; fi
        sleep 3
    done
    [[ $HEALTH_OK -eq 0 ]] && echo "    ${APP_URL%/}/up answers" || echo "    ${APP_URL%/}/up did not answer" >&2
else
    echo "    (APP_URL is not set in .env, so the web address wasn't tried)"; HEALTH_OK=0
fi
as_app "php artisan quaverdesk:health" || HEALTH_OK=1

if [[ $HEALTH_OK -ne 0 ]]; then
    echo "==> QuaverDesk $VERSION_NOW is installed, but the health check found a problem (above)." >&2
    echo "    Fix what it lists, or roll back: see 'Rolling back' in docs/DEPLOYMENT.md. Your backup from the start of this update is in /var/backups/quaverdesk." >&2
    exit 1
fi
echo "==> Done: QuaverDesk $VERSION_NOW, and healthy."
