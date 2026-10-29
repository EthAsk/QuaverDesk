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
echo "==> Done. Health check: ${APP_URL:-https://YOUR.DOMAIN}/up"
