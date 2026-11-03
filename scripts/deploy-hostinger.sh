#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

APP_DIR='/home/u974495265/domains/franconia.in/public_html/api'
BACKUP_DIR='/home/u974495265/franconia-private/backups'
COMMIT="${1:-}"
PHP_BIN="${PHP_BIN:-/opt/alt/php83/usr/bin/php}"
if ! command -v "$PHP_BIN" >/dev/null; then PHP_BIN=php; fi
export PATH="$(dirname "$(command -v "$PHP_BIN")"):$PATH"

[[ "$COMMIT" =~ ^[0-9a-f]{40}$ ]] || { echo 'Expected the exact tested commit SHA.'; exit 1; }
cd "$APP_DIR"
[[ "$(pwd -P)" == "$APP_DIR" ]] || { echo 'Unexpected deployment directory.'; exit 1; }
test -d .git || { echo 'Clone the backend repository into the api directory first.'; exit 1; }
test -f .env || { echo 'Create the private production .env first.'; exit 1; }
command -v flock >/dev/null
exec 9>/home/u974495265/franconia-deploy.lock
flock -n 9 || { echo 'Another deployment is running.'; exit 1; }

"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_INT_SIZE === 8 ? 0 : 1);' || { echo 'Select a PHP 8.3+ CLI binary with PHP_BIN.'; exit 1; }
case "$(git remote get-url origin)" in
  https://github.com/mnkkal/franconia.git|git@github.com:mnkkal/franconia.git) ;;
  *) echo 'Unexpected Git repository; refusing to deploy.'; exit 1 ;;
esac
git diff --quiet && git diff --cached --quiet || { echo 'Server has tracked edits; preserve/reconcile them before deploying.'; exit 1; }
git fetch --no-tags origin main
git cat-file -e "$COMMIT^{commit}"
git merge-base --is-ancestor "$COMMIT" origin/main || { echo 'Commit is not on main.'; exit 1; }
if git merge-base --is-ancestor "$COMMIT" HEAD && [[ "$(git rev-parse HEAD)" != "$COMMIT" ]]; then
  echo 'A newer commit is already deployed; skipping the older run.'
  exit 0
fi

mkdir -p "$BACKUP_DIR" bootstrap/cache storage/framework/{cache/data,sessions,testing,views} storage/logs storage/app/{private,public}
if test -f vendor/autoload.php; then
  "$PHP_BIN" artisan down --retry=60
fi
trap 'echo "Deployment failed. Inspect this run; restore the prior release/database if required. Maintenance mode is retained if it was enabled."' ERR

git merge --ff-only "$COMMIT"
[[ "$(git rev-parse HEAD)" == "$COMMIT" ]]
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan franconia:backup-database --directory="$BACKUP_DIR"
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan franconia:update-prices
"$PHP_BIN" artisan storage:link
"$PHP_BIN" artisan filament:assets
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan up

status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 https://api.franconia.in/up)"
[[ "$status" == 200 ]] || { echo "API health check failed: HTTP $status"; exit 1; }
private_status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 https://franconia.in/api/.env)"
[[ "$private_status" == 403 || "$private_status" == 404 ]] || { echo 'Private-file protection check failed; investigate hosting rules.'; exit 1; }
echo "Deployed $COMMIT to https://api.franconia.in"
