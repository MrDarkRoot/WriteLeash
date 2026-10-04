#!/usr/bin/env bash
# Small host-browser check using the same pinned, disposable Admin services.
# Called by run.sh; playwright 1.61.0 and Chrome are test-only prerequisites.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
compose=(docker compose -p writeleash_woo111 -f "$here/docker-compose.yml")
container="writeleash_167_browser_$$"
scratch="$(mktemp -d)"
cleanup() {
  local status=$?
  trap - EXIT
  if [ "$status" -ne 0 ]; then docker exec "$container" sh -c 'tail -40 /tmp/wl167-web.log' 2>/dev/null | sed 's/?.*/?[query redacted]/' || true; fi
  docker rm -f "$container" >/dev/null 2>&1 || true
  rm -rf "$scratch"
  exit "$status"
}
trap cleanup EXIT
node -e 'const p=require(process.env.WL167_PLAYWRIGHT_MODULE || "playwright/package.json"); console.log("#167 Node " + process.version + "; Playwright " + (p.version || "explicit module"));'
"${compose[@]}" run -d --no-deps --name "$container" --publish 127.0.0.1::8080 tester sh -c 'sleep 1200' >/dev/null
port="$(docker port "$container" 8080/tcp | sed 's/.*://')"
export WL167_CONTAINER="$container" WL167_BASE_URL="http://127.0.0.1:$port" WL167_FIXTURE="$scratch/fixture.json"
evidence="${WL167_EVIDENCE:-$scratch/screenshots}"
for host in mysql mariadb; do
  for cache in default persistent; do
    export WL167_SITE="/tmp/wl167-$host-$cache"
    export WL167_EVIDENCE="$evidence/$host-$cache"
    docker exec -i -e WL167_SITE -e WL167_BASE_URL -e WL167_HOST="$host" -e WL167_CACHE="$cache" -e WL167_FIXTURE=/tmp/wl167-fixture.json "$container" sh -s <<'SETUP'
set -eu
site="$WL167_SITE"
cp -R /opt/wp-core "$site"
sh /opt/tests/stage-plugin.sh "$site"
wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost="$WL167_HOST" --dbprefix="wl167_${WL167_CACHE}_"
wp --path="$site" config set WP_HTTP_BLOCK_EXTERNAL true --raw
wp --path="$site" config set WP_ACCESSIBLE_HOSTS 127.0.0.1
wp --path="$site" config set DISABLE_WP_CRON true --raw
wp --path="$site" core install --url="$WL167_BASE_URL" --title=Selection-167 --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
wp --path="$site" plugin install /opt/woo-zips/woocommerce.11.1.2.zip --activate
wp --path="$site" plugin activate writeleash
if [ "$WL167_CACHE" = persistent ]; then
  wp --path="$site" plugin install /opt/woo-zips/redis-cache.2.7.0.zip --activate
  wp --path="$site" config set WP_REDIS_HOST redis
  wp --path="$site" config set WP_REDIS_CLIENT predis
  wp --path="$site" config set WP_REDIS_PREFIX "wl167-$WL167_HOST-$WL167_CACHE:"
  wp --path="$site" redis enable
fi
WL167_MODE=seed wp --path="$site" eval-file /opt/tests/admin/selection-browser-fixture.php
SETUP
    docker exec -d -e WL167_SITE "$container" sh -c 'echo "$$" >/tmp/wl167-web.pid; exec php -S 0.0.0.0:8080 -t "$WL167_SITE" >/tmp/wl167-web.log 2>&1'
    docker cp "$container:/tmp/wl167-fixture.json" "$WL167_FIXTURE"
    chmod 600 "$WL167_FIXTURE"
    curl --fail --silent --retry 5 --retry-all-errors --retry-delay 1 "$WL167_BASE_URL/wp-login.php" >/dev/null
    echo "#167 real browser engine=$host cache=$cache"
    node "$here/selection-browser.cjs"
    export WL168_FIXTURE="$scratch/presentation-fixture.json"
    docker exec -e WL168_MODE=seed -e WL168_FIXTURE=/tmp/wl168-fixture.json "$container" wp --path="$WL167_SITE" eval-file /opt/tests/admin/presentation-browser-fixture.php
    docker cp "$container:/tmp/wl168-fixture.json" "$WL168_FIXTURE"
    chmod 600 "$WL168_FIXTURE"
    node "$here/presentation-browser.cjs"
    docker exec "$container" sh -c 'kill "$(cat /tmp/wl167-web.pid)"'
  done
done
echo '#167 real-browser two-engine/default/Redis selection and saved review: PASS'
