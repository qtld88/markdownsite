#!/usr/bin/env bash
# Start a throwaway Nextcloud in Docker with this app enabled.
#
#   scripts/smoke.sh 31      start Nextcloud 31 (any nextcloud image tag works)
#   scripts/smoke.sh stop    remove the container
#
# The app is staged in .smoke/markdownsite (production build, no dev
# dependencies) and copied into the container's custom_apps/, so the working
# copy's vendor/ keeps its dev dependencies. Passwords are generated per run
# and written to .smoke/credentials.txt (git-ignored).
set -euo pipefail

NAME=markdownsite-smoke
PORT="${SMOKE_PORT:-8080}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAGE="$ROOT/.smoke"

if [ "${1:-}" = "" ]; then
	echo "usage: $0 <nextcloud-version>|stop" >&2
	exit 2
fi

if [ "$1" = "stop" ]; then
	docker rm -f "$NAME" >/dev/null 2>&1 || true
	echo "Stopped $NAME."
	exit 0
fi
VERSION="$1"

echo "==> Building frontend"
(cd "$ROOT" && npm run build)

echo "==> Staging app in $STAGE/markdownsite"
rm -rf "$STAGE"
mkdir -p "$STAGE/markdownsite"
(cd "$ROOT" && tar \
	--exclude='./.git' --exclude='./.smoke' --exclude='./node_modules' \
	--exclude='./vendor' --exclude='./docs' --exclude='./.claude' \
	-cf - .) | tar -xf - -C "$STAGE/markdownsite"
(cd "$STAGE/markdownsite" && composer install --no-dev --no-interaction --quiet)

ADMIN_PASS="$(openssl rand -hex 12)"
USER_PASS="$(openssl rand -hex 12)"
printf 'admin  %s\nreader %s\n' "$ADMIN_PASS" "$USER_PASS" > "$STAGE/credentials.txt"

echo "==> Starting nextcloud:$VERSION"
docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" -p "$PORT:80" \
	-e SQLITE_DATABASE=nextcloud \
	-e NEXTCLOUD_ADMIN_USER=admin \
	-e NEXTCLOUD_ADMIN_PASSWORD="$ADMIN_PASS" \
	-e NEXTCLOUD_TRUSTED_DOMAINS=localhost \
	"nextcloud:$VERSION" >/dev/null

occ() { docker exec -u www-data "$NAME" php occ "$@"; }

echo "==> Waiting for the installation to finish"
for _ in $(seq 1 120); do
	if occ status --output=json 2>/dev/null | grep -q '"installed":true'; then
		break
	fi
	sleep 2
done
occ status --output=json | grep -q '"installed":true' || { echo "Nextcloud did not finish installing" >&2; exit 1; }

echo "==> Installing and enabling markdownsite"
docker cp "$STAGE/markdownsite" "$NAME:/var/www/html/custom_apps/markdownsite"
docker exec "$NAME" chown -R www-data:www-data /var/www/html/custom_apps/markdownsite
occ app:enable markdownsite
# The welcome wizard would cover the page on first login.
occ app:disable firstrunwizard >/dev/null || true

echo "==> Creating a second user and uploading the fixture site"
docker exec -u www-data -e OC_PASS="$USER_PASS" "$NAME" php occ user:add --password-from-env reader >/dev/null
docker cp "$ROOT/tests/fixtures/smoke-site" "$NAME:/var/www/html/data/admin/files/smoke-site"
docker exec "$NAME" chown -R www-data:www-data /var/www/html/data/admin/files/smoke-site
occ files:scan --path=/admin/files/smoke-site >/dev/null

echo
echo "Ready: http://localhost:$PORT/index.php/apps/markdownsite/"
echo "Users and passwords: $STAGE/credentials.txt"
echo "Stop with: $0 stop"
