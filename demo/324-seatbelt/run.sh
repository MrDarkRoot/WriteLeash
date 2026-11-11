#!/usr/bin/env bash
# Build a disposable WordPress/WooCommerce site, run the #324 scenes, and
# encode the recording. Does not merge, publish, or touch WordPress.org SVN.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
runtime="$here/runtime"
out="$here/out"
woo_sha=9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e
project="${DEMO324_PROJECT:-writeleash_demo324}"
compose=(docker compose -p "$project" -f "$here/docker-compose.yml")
site=/tmp/wl324

mkdir -p "$runtime/woo" "$runtime/capture" "$out"
if [[ ! -f "$runtime/woo/woocommerce.11.1.2.zip" ]]; then
  cached="$repo/wordpress/tests/admin/cache/woocommerce.11.1.2.zip"
  sibling="/home/vanta/Projects/WriteLeash/wordpress/tests/admin/cache/woocommerce.11.1.2.zip"
  if [[ -f "$cached" ]]; then
    cp "$cached" "$runtime/woo/woocommerce.11.1.2.zip"
  elif [[ -f "$sibling" ]]; then
    cp "$sibling" "$runtime/woo/woocommerce.11.1.2.zip"
  else
    curl -fL --retry 3 "https://downloads.wordpress.org/plugin/woocommerce.11.1.2.zip" -o "$runtime/woo/woocommerce.11.1.2.zip"
  fi
fi
printf '%s  %s\n' "$woo_sha" "$runtime/woo/woocommerce.11.1.2.zip" | sha256sum -c -

if [[ -d "$repo/wordpress/tests/admin/browser-tools/node_modules/playwright" ]]; then
  export DEMO324_PLAYWRIGHT_ROOT="$repo/wordpress/tests/admin/browser-tools/node_modules"
elif [[ -d /home/vanta/Projects/WriteLeash/wordpress/tests/admin/browser-tools/node_modules/playwright ]]; then
  export DEMO324_PLAYWRIGHT_ROOT=/home/vanta/Projects/WriteLeash/wordpress/tests/admin/browser-tools/node_modules
else
  npm ci --prefix "$repo/wordpress/tests/admin/browser-tools"
  export DEMO324_PLAYWRIGHT_ROOT="$repo/wordpress/tests/admin/browser-tools/node_modules"
fi

cleanup() {
  local status=$?
  if [[ "${DEMO324_KEEP:-}" != 1 ]]; then
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
  else
    echo "DEMO324_KEEP=1, container left up" >&2
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
"${compose[@]}" exec -T web sh -s <<SETUP
set -eu
site=$site
rm -rf "\$site"
mkdir -p "\$site"
cp -R /opt/wp-core/. "\$site/"
sh /opt/tests/stage-plugin.sh "\$site"
mkdir -p "\$site/wp-content/mu-plugins"
cp /opt/demo/mu-plugin.php "\$site/wp-content/mu-plugins/wl324-hold-async.php"
cp /opt/demo/board.html "\$site/demo-board.html"
SETUP

# docker exec reads the script only when stdin stays attached.
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
echo "activating woocommerce and writeleash"
wp --path="$SITE" plugin install /opt/woo-zips/woocommerce.11.1.2.zip --activate
wp --path="$SITE" plugin activate writeleash
# Pretty permalinks plus the demo router make /wp-json/ reach WordPress.
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

docker exec -d "$container" sh -c "exec php -d opcache.enable=0 -S 0.0.0.0:8080 -t '$site' /opt/demo/router.php >/tmp/wl324-web.log 2>&1"
curl --fail --silent --retry 20 --retry-all-errors --retry-delay 1 "$base/wp-login.php" >/dev/null

docker exec -e WL324_SEED_OUT=/tmp/wl324-seed.json "$container" wp --path="$site" eval-file /opt/demo/seed.php
docker cp "$container:/tmp/wl324-seed.json" "$runtime/seed.json"
riley_id="$(jq -r .users.riley.id "$runtime/seed.json")"
docker exec "$container" wp --path="$site" writeleash grant create --user=morgan --actor="$riley_id" --budget=10 --expires="2027-01-01 00:00:00"
restricted="$(docker exec "$container" wp --path="$site" eval "echo \\WriteLeash\\Grant_Repository::is_restricted_actor( ${riley_id} ) ? 'restricted' : 'not-restricted';")"
restricted="$(printf '%s' "$restricted" | tr -d '\r')"
[[ "$restricted" == restricted ]]
app_pass="$(docker exec "$container" wp --path="$site" user application-password create riley demo-324 --porcelain | tr -d '\r')"
[[ -n "$app_pass" ]]

jq -n \
  --arg morgan "$morgan_pass" \
  --arg app "$app_pass" \
  --slurpfile seed "$runtime/seed.json" \
  '{product_id: $seed[0].product_id, sku: $seed[0].sku, passwords: {morgan: $morgan, casey: $seed[0].created_passwords.casey, riley: $seed[0].created_passwords.riley, riley_app: $app}}' \
  > "$runtime/secrets.json"
chmod 600 "$runtime/secrets.json"

php_version="$(docker exec "$container" php -r 'echo PHP_VERSION;')"
wp_version="$(docker exec "$container" wp --path="$site" core version | tr -d '\r')"
woo_version="$(docker exec "$container" wp --path="$site" plugin get woocommerce --field=version | tr -d '\r')"
plugin_version="$(docker exec "$container" wp --path="$site" plugin get writeleash --field=version | tr -d '\r')"
# The CLI image's mysql binary is MariaDB 11 and rejects MySQL 8's
# self-signed certificate. Read the server through the PHP connection.
db_version="$(docker exec "$container" wp --path="$site" eval 'global $wpdb; echo $wpdb->get_var( "SELECT VERSION()" );' | tr -d '\r')"
engine="$(docker exec "$container" wp --path="$site" eval 'global $wpdb; echo $wpdb->get_var( "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \"wp_posts\"" );' | tr -d '\r')"
cache="$(docker exec "$container" wp --path="$site" eval 'echo (int) wp_using_ext_object_cache();' | tr -d '\r')"
dropin=no
if docker exec "$container" test -f "$site/wp-content/object-cache.php"; then dropin=yes; fi
commit="$(git -C "$repo" rev-parse HEAD)"
jq -n \
  --arg commit "$commit" \
  --arg php "$php_version" \
  --arg wp "$wp_version" \
  --arg woo "$woo_version" \
  --arg plugin "$plugin_version" \
  --arg db "$db_version" \
  --arg engine "$engine" \
  --arg cache "$cache" \
  --arg dropin "$dropin" \
  --arg woo_sha "$woo_sha" \
  '{
    git_commit: $commit,
    plugin_version_header: $plugin,
    wordpress: $wp,
    wordpress_core_image: "wordpress:7.1.2-php8.2-apache@sha256:da4c3e7b0051b70835b0a188ecdd5bce5ebf6f83d1f3df41553f1a97d237fd19",
    php: $php,
    php_image: "wordpress:cli-php8.2@sha256:aaa79b1239f24fccbb1634ec0a25c3e4fac6271bc9bf2334c40c3c8ef77bce89",
    mysql_version: $db,
    mysql_image: "mysql@sha256:9c3380eac945af0736031b200027f581925927c81e010056214a4bd6b6693714",
    wp_posts_engine: $engine,
    woocommerce: $woo,
    woocommerce_zip_sha256: $woo_sha,
    external_object_cache: ($cache == "1"),
    object_cache_dropin: ($dropin == "yes"),
    object_cache: "WordPress default non-persistent cache. No Redis and no object-cache.php drop-in.",
    wp_debug_log: true,
    wp_environment_type: "local",
    disable_wp_cron: true,
    action_scheduler_async_runner: "disabled by the disposable mu-plugin so the second edit is on screen before Apply",
    permalinks: "/%postname%/, with the demo router so /wp-json/ reaches index.php",
    wordpress_org_distributed_version: "0.2.2",
    demonstration: "unreleased repository build"
  }' > "$runtime/capture/versions.json"

export DEMO324_BASE_URL="$base" DEMO324_CONTAINER="$container" DEMO324_SITE="$site"
export DEMO324_OUT="$runtime/capture" DEMO324_SECRETS="$runtime/secrets.json"
rm -rf "$runtime/capture/video" "$runtime/capture/frames"
echo "starting screen capture"
node "$here/capture.cjs"

webm="$(find "$runtime/capture/video" -name '*.webm' -printf '%T@ %p\n' | sort -n | tail -1 | cut -d' ' -f2-)"
test -n "$webm"
cp "$webm" "$out/writeleash-seatbelt-demo.webm"
if ! gst-launch-1.0 -e filesrc location="$out/writeleash-seatbelt-demo.webm" ! matroskademux ! vp8dec ! videoconvert ! video/x-raw,format=I420 ! openh264enc bitrate=1800000 ! h264parse ! mp4mux ! filesink location="$out/writeleash-seatbelt-demo.mp4"; then
  echo "MP4_ENCODE_FAILED" > "$out/MP4-BLOCKED.txt"
else
  rm -f "$out/MP4-BLOCKED.txt"
fi
python3 "$here/encode_assets.py" "$runtime/capture/frames" "$runtime/capture/poster-source.png" "$out/writeleash-seatbelt-loop.gif" "$out/writeleash-seatbelt-poster.png" > "$runtime/capture/image-report.json"
cp "$runtime/capture/image-report.json" "$out/image-report.json"
python3 - "$out/writeleash-seatbelt-demo.mp4" "$out/writeleash-seatbelt-demo.webm" "$out/video-report.json" <<'PY'
import json, re, subprocess, sys
from pathlib import Path

def discover(path: Path) -> dict:
    text = subprocess.check_output(["gst-discoverer-1.0", str(path)], text=True, stderr=subprocess.STDOUT)
    duration = re.search(r"Duration: (\d+):(\d+):(\d+\.\d+)", text)
    width = re.search(r"Width: (\d+)", text)
    height = re.search(r"Height: (\d+)", text)
    if not duration:
        raise SystemExit("gst-discoverer did not report a duration for " + str(path))
    hours, minutes, seconds = duration.groups()
    return {
        "bytes": path.stat().st_size,
        "duration_s": round(int(hours) * 3600 + int(minutes) * 60 + float(seconds), 3),
        "width": int(width.group(1)) if width else None,
        "height": int(height.group(1)) if height else None,
    }

report = {"mp4": discover(Path(sys.argv[1])), "webm": discover(Path(sys.argv[2]))}
Path(sys.argv[3]).write_text(json.dumps(report, indent=2) + "\n")
print(json.dumps(report))
PY
cp "$runtime/capture/captions.en.vtt" "$runtime/capture/scenes.json" "$runtime/capture/evidence.json" "$runtime/capture/versions.json" "$out/"
cp "$runtime/capture/during-preview.json" "$out/db-during-preview.json"
cp "$runtime/capture/after-colleague.json" "$out/db-after-colleague.json"
cp "$runtime/capture/after-conflict.json" "$out/db-after-conflict.json"
cp "$runtime/capture/before-deny.json" "$out/db-before-deny.json"
cp "$runtime/capture/after-deny.json" "$out/db-after-deny.json"
python3 - "$out" "$runtime/secrets.json" <<'PY'
import json, sys
from pathlib import Path
out = Path(sys.argv[1])
secrets = json.loads(Path(sys.argv[2]).read_text())
needles = [v for v in secrets["passwords"].values() if v]
for path in out.rglob("*"):
    if not path.is_file() or path.suffix.lower() in {".png", ".gif", ".mp4", ".webm"}:
        continue
    text = path.read_text(errors="ignore")
    for needle in needles:
        if needle in text:
            raise SystemExit(f"secret leaked into {path}")
print("secret scan passed")
PY
echo "demo assets written to $out"
