#!/usr/bin/env bash
# Disposable WordPress + WooCommerce lab for issue #327.
# Issues a small grant, runs one grant job, denies one direct product write,
# and checks the Protection report. Tears the containers and volumes down.
# Does not merge, publish, or touch WordPress.org SVN.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
runtime="$here/runtime"
out="$here/out"
woo_sha=9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e
project="${DEMO327_PROJECT:-writeleash_demo327}"
compose=(docker compose -p "$project" -f "$here/docker-compose.yml")
site=/tmp/wl327

mkdir -p "$runtime/woo" "$out"
if [[ ! -f "$runtime/woo/woocommerce.11.1.2.zip" ]]; then
  cached=""
  for candidate in \
    "$repo/wordpress/tests/admin/cache/woocommerce.11.1.2.zip" \
    "$repo/wordpress/tests/jobs/cache/woocommerce.11.1.2.zip" \
    "/home/vanta/Projects/WriteLeash/wordpress/tests/admin/cache/woocommerce.11.1.2.zip"
  do
    if [[ -f "$candidate" ]]; then cached="$candidate"; break; fi
  done
  if [[ -n "$cached" ]]; then
    cp "$cached" "$runtime/woo/woocommerce.11.1.2.zip"
  else
    curl -fL --retry 3 "https://downloads.wordpress.org/plugin/woocommerce.11.1.2.zip" -o "$runtime/woo/woocommerce.11.1.2.zip"
  fi
fi
printf '%s  %s\n' "$woo_sha" "$runtime/woo/woocommerce.11.1.2.zip" | sha256sum -c -

cleanup() {
  local status=$?
  trap - EXIT
  if [[ "${DEMO327_KEEP:-}" != 1 ]]; then
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || status=1
    if ! bash "$repo/wordpress/tests/assert-compose-clean.sh" "$project"; then status=1; fi
  else
    echo "DEMO327_KEEP=1, container left up" >&2
  fi
  exit "$status"
}
trap cleanup EXIT

"${compose[@]}" up -d --build --wait
container="$("${compose[@]}" ps -q web)"
port="$("${compose[@]}" port web 8080 | sed 's/.*://')"
base="http://127.0.0.1:${port}"
echo "disposable site ${base} container ${container}"

morgan_pass="$(openssl rand -base64 24 | tr -d '/+=' | head -c 20)"
umask 077
printf '%s\n' "$morgan_pass" > "$runtime/morgan-password"
chmod 600 "$runtime/morgan-password"

"${compose[@]}" exec -T web sh -s <<SETUP
set -eu
site=$site
rm -rf "\$site"
mkdir -p "\$site"
cp -R /opt/wp-core/. "\$site/"
sh /opt/tests/stage-plugin.sh "\$site"
SETUP

docker exec -i -e MORGAN_PASS="$morgan_pass" -e SITE="$site" -e BASE="$base" "$container" sh -s <<'SETUP'
set -eu
echo "creating wp-config"
wp --path="$SITE" config create --dbname=wp_demo --dbuser=wp_demo --dbpass=disposable_wp_password --dbhost=mysql --dbprefix=wp_ --skip-check
wp --path="$SITE" config set WP_DEBUG true --raw
wp --path="$SITE" config set WP_DEBUG_LOG true --raw
wp --path="$SITE" config set WP_DEBUG_DISPLAY false --raw
wp --path="$SITE" config set DISABLE_WP_CRON true --raw
wp --path="$SITE" config set WP_ENVIRONMENT_TYPE local
wp --path="$SITE" config set WP_HTTP_BLOCK_EXTERNAL true --raw
wp --path="$SITE" config set WP_ACCESSIBLE_HOSTS 127.0.0.1
echo "installing wordpress"
wp --path="$SITE" core install --url="$BASE" --title='Northwind Outfitters Demo' --admin_user=morgan --admin_password="$MORGAN_PASS" --admin_email=morgan.merchant@example.test --skip-email
wp --path="$SITE" option update blogdescription 'Fictional store for a disposable WriteLeash demo'
wp --path="$SITE" option update admin_email_lifespan 1893456000
wp --path="$SITE" option update show_welcome_panel 0
echo "activating plugins"
wp --path="$SITE" plugin install /opt/woo-zips/woocommerce.11.1.2.zip --activate
wp --path="$SITE" plugin activate writeleash
wp --path="$SITE" rewrite structure '/%postname%/'
wp --path="$SITE" rewrite flush
wp --path="$SITE" option update woocommerce_currency USD
wp --path="$SITE" option update woocommerce_currency_pos left
wp --path="$SITE" option update woocommerce_price_thousand_sep ','
wp --path="$SITE" option update woocommerce_price_decimal_sep '.'
wp --path="$SITE" option update woocommerce_price_num_decimals 2
wp --path="$SITE" option update woocommerce_default_country 'US:CA'
wp --path="$SITE" option update woocommerce_coming_soon no
wp --path="$SITE" option update woocommerce_store_pages_only no
wp --path="$SITE" option update woocommerce_coming_soon_banner_dismissed yes
echo "wordpress ready"
SETUP

docker exec -d "$container" sh -c "exec php -d opcache.enable=0 -S 0.0.0.0:8080 -t '$site' /opt/demo/router.php >/tmp/wl327-web.log 2>&1"
curl --fail --silent --retry 30 --retry-all-errors --retry-delay 1 "$base/wp-login.php" >/dev/null

docker exec -e WL327_SECRET_OUT=/tmp/wl327-seed-secret.json -e WL327_PUBLIC_OUT=/tmp/wl327-seed.json "$container" wp --path="$site" eval-file /opt/demo/seed.php
docker cp "$container:/tmp/wl327-seed.json" "$runtime/seed.json"
actor_id="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["actor"]["id"])' "$runtime/seed.json")"
permitted_id="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["products"]["permitted"]["id"])' "$runtime/seed.json")"
denied_id="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["products"]["denied"]["id"])' "$runtime/seed.json")"

expires="$(docker exec "$container" wp --path="$site" eval 'echo gmdate( "Y-m-d H:i:s", time() + 30 * DAY_IN_SECONDS );' | tr -d '\r' | grep -E '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$' | tail -n 1)"
if [[ ! "$expires" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}\ [0-9]{2}:[0-9]{2}:[0-9]{2}$ ]]; then
  echo "could not compute a UTC grant expiry" >&2
  exit 1
fi
grant_line="$(docker exec "$container" wp --path="$site" writeleash grant create --user=morgan --actor="$actor_id" --budget=1 --expires="$expires" | tr -d '\r')"
echo "$grant_line"
grant_id="$(printf '%s\n' "$grant_line" | sed -n 's/^Success: grant \([^ ]*\) created.*/\1/p')"
[[ -n "$grant_id" ]]
version="$(docker exec "$container" wp --path="$site" eval "\$g = \\WriteLeash\\Grant_Repository::read_by_public_id( '$grant_id' ); echo (int) ( \$g['version'] ?? 0 );" | tr -d '\r' | grep -E '^[0-9]+$' | tail -n 1)"
[[ "$version" -ge 1 ]]

morgan_app="$(docker exec "$container" wp --path="$site" user application-password create morgan wl327-job --porcelain | tr -d '\r')"
riley_app="$(docker exec "$container" wp --path="$site" user application-password create riley wl327-deny --porcelain | tr -d '\r')"
python3 - "$runtime/secrets.json" "$morgan_pass" "$morgan_app" "$riley_app" <<'PY'
import json, sys
path, morgan, morgan_app, riley_app = sys.argv[1:]
json.dump({"morgan_password": morgan, "morgan_app": morgan_app, "riley_app": riley_app}, open(path, "w"))
PY
chmod 600 "$runtime/secrets.json"

# 40.00 -> 30.00 stays inside the grant job's 50 percent decrease cap.
python3 - "$runtime/job.json" "$actor_id" "$version" "$permitted_id" <<'PY'
import json, sys
path, actor, version, product = sys.argv[1:]
json.dump({
    "idempotency_key": "wl327-permit-1",
    "actor_id": int(actor),
    "grant_version": int(version),
    "price_field": "regular_price",
    "operation": "SET",
    "target": "30.00",
    "product_ids": [int(product)],
}, open(path, "w"))
PY

job_code="$(curl -sS -o "$runtime/job-body.json" -w '%{http_code}' -u "morgan:${morgan_app}" -H 'Content-Type: application/json' -d @"$runtime/job.json" "$base/wp-json/writeleash/v1/grants/${grant_id}/jobs")"
echo "grant job HTTP ${job_code}"
if [[ "$job_code" != 201 ]]; then
  cat "$runtime/job-body.json" >&2 || true
  exit 1
fi
job_public="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["job"]["public_id"])' "$runtime/job-body.json")"
[[ -n "$job_public" ]]
docker exec -e WL327_JOB_PUBLIC_ID="$job_public" "$container" wp --path="$site" eval-file /opt/demo/run-job.php | tee "$runtime/job-run.json"

docker exec -e WL327_CHECK_OUT=/tmp/wl327-before.json -e WL327_CHECK_MODE=before -e WL327_PERMITTED_ID="$permitted_id" -e WL327_DENIED_ID="$denied_id" -e WL327_ACTOR_ID="$actor_id" "$container" wp --path="$site" eval-file /opt/demo/check.php
deny_code="$(curl -sS -o "$runtime/deny-body.json" -w '%{http_code}' -u "riley:${riley_app}" -X PUT -H 'Content-Type: application/json' --data '{"regular_price":"1.00"}' "$base/wp-json/wc/v3/products/${denied_id}")"
echo "direct write HTTP ${deny_code}"
if [[ "$deny_code" != 403 ]]; then
  cat "$runtime/deny-body.json" >&2 || true
  exit 1
fi
docker cp "$container:/tmp/wl327-before.json" "$runtime/before.json"
docker cp "$runtime/before.json" "$container:/tmp/wl327-before.json"
docker exec -e WL327_CHECK_OUT=/tmp/wl327-after.json -e WL327_CHECK_MODE=after -e WL327_BEFORE=/tmp/wl327-before.json -e WL327_PERMITTED_ID="$permitted_id" -e WL327_DENIED_ID="$denied_id" -e WL327_ACTOR_ID="$actor_id" "$container" wp --path="$site" eval-file /opt/demo/check.php
docker cp "$container:/tmp/wl327-after.json" "$runtime/after.json"

php_version="$(docker exec "$container" php -r 'echo PHP_VERSION;')"
wp_version="$(docker exec "$container" wp --path="$site" core version | tr -d '\r')"
woo_version="$(docker exec "$container" wp --path="$site" plugin get woocommerce --field=version | tr -d '\r')"
plugin_version="$(docker exec "$container" wp --path="$site" plugin get writeleash --field=version | tr -d '\r')"
db_version="$(docker exec "$container" wp --path="$site" eval 'global $wpdb; echo $wpdb->get_var( "SELECT VERSION()" );' | tr -d '\r')"
engine="$(docker exec "$container" wp --path="$site" eval 'global $wpdb; echo $wpdb->get_var( "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \"wp_posts\"" );' | tr -d '\r')"
commit="$(git -C "$repo" rev-parse HEAD)"
plugin_tree="$(git -C "$repo" rev-parse 'HEAD:wordpress/writeleash')"
core_image="wordpress:7.1.2-php8.2-apache@sha256:da4c3e7b0051b70835b0a188ecdd5bce5ebf6f83d1f3df41553f1a97d237fd19"
cli_image="wordpress:cli-php8.2@sha256:aaa79b1239f24fccbb1634ec0a25c3e4fac6271bc9bf2334c40c3c8ef77bce89"
mysql_image="mysql@sha256:9c3380eac945af0736031b200027f581925927c81e010056214a4bd6b6693714"

python3 - "$out/evidence.json" "$runtime/seed.json" "$runtime/job-body.json" "$runtime/job-run.json" "$runtime/deny-body.json" "$runtime/after.json" \
  "$commit" "$plugin_tree" "$plugin_version" "$grant_id" "$expires" "$job_code" "$deny_code" "$woo_sha" <<'PY'
import json, sys
(out, seed_path, job_path, run_path, deny_path, after_path, commit, tree, plugin, grant, expires, job_code, deny_code, woo_sha) = sys.argv[1:]
seed = json.load(open(seed_path))
job = json.load(open(job_path))
run = json.load(open(run_path))
deny = json.load(open(deny_path))
after = json.load(open(after_path))
evidence = {
    "status": "PASS",
    "git_commit": commit,
    "plugin_tree": tree,
    "plugin_version": plugin,
    "woo_zip_sha256": woo_sha,
    "grant": {"public_id": grant, "budget_max": 1, "expires_at": expires, "expires_source": "utc_now_plus_30_days"},
    "actor_id": seed["actor"]["id"],
    "products": seed["products"],
    "permitted_job": {"http_status": int(job_code), "body": job, "worker": run, "regular_price_after": after["permitted_regular_price"]},
    "denied_write": {"http_status": int(deny_code), "body": deny, "regular_price_after": after["denied_regular_price"], "unchanged": after["denied_unchanged"]},
    "protection_report": after["report"],
    "http_requests_during_report": after["http_requests_during_report"],
}
json.dump(evidence, open(out, "w"), indent=2)
open(out, "a").write("\n")
PY

cat > "$here/VERSIONS.md" <<EOF
# Disposable stack for issue #327

Recorded from the running containers. Not a published release.

| Item | Value |
| --- | --- |
| Status | PASS |
| Git commit | \`$commit\` |
| WriteLeash plugin tree | \`$plugin_tree\` |
| WriteLeash header | $plugin_version |
| WordPress | $wp_version |
| PHP | $php_version |
| WooCommerce | $woo_version |
| WooCommerce zip sha256 | \`$woo_sha\` |
| Database | $db_version |
| wp_posts engine | $engine |
| Core image | \`$core_image\` |
| CLI image | \`$cli_image\` |
| MySQL image | \`$mysql_image\` |
| Grant expiry | \`$expires\` UTC, source \`utc_now_plus_30_days\` |
| Permitted price | 40.00 to 30.00 through \`POST /wp-json/writeleash/v1/grants/{id}/jobs\` and the \`writeleash_process_job\` hook |
| Denied price | Harborline Notebook stayed 25.00 after \`PUT /wp-json/wc/v3/products/{id}\` returned 403 \`writeleash_perimeter_denied\` |
| Report | counted the REST denial; HTTP requests during the report load: 0 |

Fixture database passwords are the disposable lab values in \`docker-compose.yml\`. Generated WordPress and application passwords stayed in gitignored \`runtime/\` and are removed with the container. No production credential was used.
EOF

echo "demo 327 PASS"
