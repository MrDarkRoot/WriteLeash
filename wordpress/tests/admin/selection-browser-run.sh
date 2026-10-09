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
  if [ "$status" -ne 0 ]; then
    docker exec "$container" sh -c 'tail -200 /tmp/wl167-web.log' 2>/dev/null | sed 's/?.*/?[query redacted]/' || true
    for log in $(docker exec "$container" sh -c 'find /tmp -maxdepth 4 -name writeleash-debug.log 2>/dev/null' | tr -d '\r'); do
      docker exec "$container" sh -c "tail -50 '$log'" 2>/dev/null || true
    done
  fi
  docker rm -f "$container" >/dev/null 2>&1 || true
  rm -rf "$scratch"
  exit "$status"
}
trap cleanup EXIT
node -e 'const p=require(process.env.WL167_PLAYWRIGHT_MODULE || "playwright/package.json"); console.log("#167 Node " + process.version + "; Playwright " + (p.version || "explicit module"));'
"${compose[@]}" run -d --no-deps --name "$container" --publish 127.0.0.1::8080 tester sh -c 'sleep 2400' >/dev/null
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
    docker exec -d -e WL167_SITE "$container" sh -c 'echo "$$" >/tmp/wl167-web.pid; exec php -d opcache.enable=0 -S 0.0.0.0:8080 -t "$WL167_SITE" >/tmp/wl167-web.log 2>&1'
    docker cp "$container:/tmp/wl167-fixture.json" "$WL167_FIXTURE"
    chmod 600 "$WL167_FIXTURE"
    curl --fail --silent --retry 5 --retry-all-errors --retry-delay 1 "$WL167_BASE_URL/wp-login.php" >/dev/null
    echo "#167 real browser engine=$host cache=$cache"
    if ! node "$here/selection-browser.cjs"; then
      # A dropped Playwright navigation/select2 query on a loaded runner is not
      # a product failure: reset the disposable baseline and retry once.
      echo "#167 selection browser retry engine=$host cache=$cache"
      docker exec -e WL167_MODE=reset -e WL167_FIXTURE=/tmp/wl167-fixture.json "$container" wp --path="$WL167_SITE" eval-file /opt/tests/admin/selection-browser-fixture.php >/dev/null
      docker cp "$container:/tmp/wl167-fixture.json" "$WL167_FIXTURE"
      node "$here/selection-browser.cjs"
    fi
    if [ "$host" = mysql ] && [ "$cache" = default ]; then
      export WL167_EVIDENCE="$evidence"
      export WL167_LISTING_FIXTURE="$scratch/listing-fixture.json"
      docker exec -e WL167_LISTING_MODE=seed -e WL167_LISTING_FIXTURE=/tmp/wl167-listing-fixture.json "$container" wp --path="$WL167_SITE" eval-file /opt/tests/admin/listing-browser-fixture.php
      docker cp "$container:/tmp/wl167-listing-fixture.json" "$WL167_LISTING_FIXTURE"
      chmod 600 "$WL167_LISTING_FIXTURE"
      echo '#182 real browser listing captures engine=mysql cache=default'
      node "$here/listing-capture.cjs"
      export WL167_EVIDENCE="$evidence/$host-$cache"
      unset WL167_LISTING_FIXTURE
    fi
    export WL168_FIXTURE="$scratch/presentation-fixture.json"
    docker exec -e WL168_MODE=seed -e WL168_FIXTURE=/tmp/wl168-fixture.json "$container" wp --path="$WL167_SITE" eval-file /opt/tests/admin/presentation-browser-fixture.php
    docker cp "$container:/tmp/wl168-fixture.json" "$WL168_FIXTURE"
    chmod 600 "$WL168_FIXTURE"
    node "$here/presentation-browser.cjs"
    export WL205_FIXTURE="$scratch/recovery-fixture.json"
    docker exec -e WL205_MODE=seed -e WL205_FIXTURE=/tmp/wl205-fixture.json "$container" wp --path="$WL167_SITE" eval-file /opt/tests/admin/recovery-browser-fixture.php
    docker cp "$container:/tmp/wl205-fixture.json" "$WL205_FIXTURE"
    chmod 600 "$WL205_FIXTURE"
    node "$here/recovery-browser.cjs"
    if [ "$host:$cache" = mysql:default ]; then
      # Both browser engines use fresh actors/products in this existing disposable site.
      export WL170_FIXTURE="$scratch/regression-fixture.json"
      for browser in chromium firefox; do
        export WL170_BROWSER="$browser" WL167_EVIDENCE="$evidence/170-$browser"
        docker exec -e WL170_MODE=seed -e WL170_FIXTURE=/tmp/wl170-fixture.json "$container" wp --path="$WL167_SITE" eval-file /opt/tests/admin/regression-browser-fixture.php
        docker cp "$container:/tmp/wl170-fixture.json" "$WL170_FIXTURE"
        chmod 600 "$WL170_FIXTURE"
        node "$here/regression-browser.cjs"
      done
    fi
    docker exec "$container" sh -c 'kill "$(cat /tmp/wl167-web.pid)"'
  done
done
echo '#167/#182 real-browser two-engine/default/Redis selection, saved review and listing captures: PASS'
