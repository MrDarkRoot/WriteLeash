#!/bin/sh
set -eu
mkdir -p /tmp/wl311-matrix
for host in mysql mariadb; do
  site="/tmp/wl109-$host"
  cp -R /opt/wp-core "$site"
  sh /opt/tests/stage-plugin.sh "$site"
  wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost="$host"
  wp --path="$site" config set WP_HTTP_BLOCK_EXTERNAL true --raw
  wp --path="$site" core install --url="http://$host.example.test" --title=Durable-Jobs --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
  wp --path="$site" config set DISABLE_WP_CRON true --raw
  wp --path="$site" plugin install /opt/plugin-zips/redirection.5.5.2.zip --activate
  wp --path="$site" plugin install /opt/woo-zips/woocommerce.11.1.2.zip --activate
  wp --path="$site" plugin activate writeleash
  wp --path="$site" plugin install /opt/woo-zips/redis-cache.2.7.0.zip --activate
  wp --path="$site" config set WP_REDIS_HOST redis
  wp --path="$site" config set WP_REDIS_CLIENT predis
  wp --path="$site" config set WP_REDIS_PREFIX "wl109-$host:"
  wp --path="$site" config set WP_DEBUG true --raw
  wp --path="$site" config set WP_DEBUG_LOG true --raw
  server_version=$(wp --path="$site" eval 'global $wpdb; echo $wpdb->get_var( "SELECT VERSION()" );')
  case "$host:$server_version" in
    mysql:8.0.44|mariadb:10.11.15-MariaDB-ubu2204) ;;
    *) echo "Wrong #109 DB fixture: $host:$server_version" >&2; exit 1 ;;
  esac
  echo "#109 pinned DB version: $server_version"
  php /opt/tests/jobs/no-replan-audit.php "$site/wp-content/plugins/writeleash"
  php /opt/tests/jobs/price-write-allowlist.php "$site/wp-content/plugins/writeleash"
  php /opt/tests/jobs/grant-unit.php
  for cache in default persistent; do
    if [ "$cache" = persistent ]; then
      wp --path="$site" redis enable
      wp --path="$site" redis status
    fi
    echo "#109 engine=$host cache=$cache"
    WL109_ROOT_USER=root WL109_ROOT_PASSWORD=disposable_root_password WL109_CACHE="$cache" wp --path="$site" eval-file /opt/tests/jobs/integration.php
    WL109_ROOT_USER=root WL109_ROOT_PASSWORD=disposable_root_password WL109_CACHE="$cache" wp --path="$site" eval-file /opt/tests/jobs/grant-integration.php
    WL109_ROOT_USER=root WL109_ROOT_PASSWORD=disposable_root_password WL109_CACHE="$cache" wp --path="$site" eval-file /opt/tests/jobs/perimeter-integration.php
    WL109_ROOT_USER=root WL109_ROOT_PASSWORD=disposable_root_password WL109_CACHE="$cache" wp --path="$site" eval-file /opt/tests/jobs/grant-removal-integration.php
    WL109_CACHE="$cache" wp --path="$site" eval-file /opt/tests/jobs/agent-jobs-rest.php
    WL109_CACHE="$cache" wp --path="$site" eval-file /opt/tests/jobs/stock-integration.php
    # #311: a failing combination must still write its evidence document, so the
    # failure is collected here and the aggregate below is the single verdict.
    matrix_status=0
    WL109_CACHE="$cache" WL311_MATRIX_DIR=/tmp/wl311-matrix WRITELEASH_SOURCE_SHA="${WRITELEASH_SOURCE_SHA:-}" wp --path="$site" eval-file /opt/tests/jobs/matrix-integration.php || matrix_status=$?
    if [ "$matrix_status" -ne 0 ]; then
      echo "#311 matrix combination engine=$host cache=$cache exited $matrix_status" >&2
    fi
    WL109_CACHE="$cache" wp --path="$site" eval-file /opt/tests/jobs/foreign-path-regression.php
    sh /opt/tests/jobs/unicode-run.sh "$host" "$cache" /opt/wp-core /opt/woo-zips
  done
  php /opt/tests/release/source-audit.php "$site/wp-content/plugins/writeleash"
  php /opt/tests/release/debug-audit.php "$site/wp-content/debug.log" "$host"
done
php /opt/tests/jobs/matrix-aggregate.php /tmp/wl311-matrix
echo '#109 durable job engine two-engine/default/Redis gate: PASS'
