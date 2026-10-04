#!/bin/sh
set -eu
for host in mysql mariadb; do
  site="/tmp/wl111-$host"
  cp -R /opt/wp-core "$site"
  sh /opt/tests/stage-plugin.sh "$site"
  wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost="$host"
  wp --path="$site" config set WP_HTTP_BLOCK_EXTERNAL true --raw
  wp --path="$site" config set WP_ACCESSIBLE_HOSTS 127.0.0.1
  wp --path="$site" core install --url="http://127.0.0.1:8080" --title=Free-Admin --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
  wp --path="$site" config set DISABLE_WP_CRON true --raw
  # No Redirection: the default Free product must activate and work with Woo only.
  wp --path="$site" plugin install /opt/woo-zips/woocommerce.11.1.2.zip --activate
  wp --path="$site" plugin activate writeleash
  wp --path="$site" eval-file /opt/tests/admin/public-boundary.php
  wp --path="$site" plugin install /opt/woo-zips/redis-cache.2.7.0.zip --activate
  wp --path="$site" config set WP_REDIS_HOST redis
  wp --path="$site" config set WP_REDIS_CLIENT predis
  wp --path="$site" config set WP_REDIS_PREFIX "wl111-$host:"
  wp --path="$site" config set WP_DEBUG true --raw
  wp --path="$site" config set WP_DEBUG_LOG true --raw
  server_version=$(wp --path="$site" eval 'global $wpdb; echo $wpdb->get_var( "SELECT VERSION()" );')
  case "$host:$server_version" in
    mysql:8.0.44|mariadb:10.11.15-MariaDB-ubu2204) ;;
    *) echo "Wrong #111 DB fixture: $host:$server_version" >&2; exit 1 ;;
  esac
  echo "#111 pinned DB version: $server_version"
  php /opt/tests/admin/admin-audit.php "$site/wp-content/plugins/writeleash"
  php /opt/tests/jobs/no-replan-audit.php "$site/wp-content/plugins/writeleash"
  # Read-only proof on the fresh schema: empty states stay silent, zero DDL.
  wp --path="$site" eval-file /opt/tests/admin/sql-audit.php
  # Local HTTP stack for the real-browser Admin E2E (default permalinks need
  # no rewrites; admin.php/admin-post.php/wp-login.php resolve as files).
  php -S 127.0.0.1:8080 -t "$site" >"/tmp/wl111-web-$host.log" 2>&1 &
  web_pid=$!
  for cache in default persistent; do
    if [ "$cache" = persistent ]; then
      wp --path="$site" redis enable
      wp --path="$site" redis status
    fi
    echo "#111 engine=$host cache=$cache"
    WL111_CACHE="$cache" wp --path="$site" eval-file /opt/tests/admin/integration.php
    sh /opt/tests/jobs/unicode-run.sh "$host" "$cache" /opt/wp-core /opt/woo-zips
    WL111_CACHE="$cache" wp --path="$site" eval-file /opt/tests/admin/sql-audit.php
    WL111_CACHE="$cache" WL111_BASE_URL=http://127.0.0.1:8080 WL111_ADMIN_PASSWORD=disposable_admin_password wp --path="$site" eval-file /opt/tests/admin/browser-e2e.php

    # Fresh processes retain real nonce verification and exercise the repaired
    # value-independent transport fixture repeatedly on every DB/cache profile.
    nonce_run=1
    while [ "$nonce_run" -le 10 ]; do
      echo "#125 nonce normalization engine=$host cache=$cache fresh-run=$nonce_run/10"
      WL111_CACHE="$cache" wp --path="$site" eval-file /opt/tests/admin/nonce-normalization.php
      nonce_run=$((nonce_run + 1))
    done
  done
  kill "$web_pid"
  php /opt/tests/release/source-audit.php "$site/wp-content/plugins/writeleash"
  php /opt/tests/release/debug-audit.php "$site/wp-content/debug.log" "$host"
  wp --path="$site" eval-file /opt/tests/admin/public-lifecycle.php
  # A fresh process after lifecycle shutdown restores only the public product.
  wp --path="$site" plugin deactivate writeleash
  wp --path="$site" plugin activate writeleash
  wp --path="$site" plugin install /opt/woo-zips/redirection.5.5.2.zip --activate
  wp --path="$site" eval-file /opt/tests/redirection-schema.php
  wp --path="$site" plugin deactivate writeleash
  WL120_STOCK_ONLY=1 wp --path="$site" eval-file /opt/tests/admin/public-redirection.php
  wp --path="$site" plugin activate writeleash
  wp --path="$site" eval-file /opt/tests/admin/public-redirection.php
  # Repeat in a fresh process with forged historical state present.
  wp --path="$site" eval-file /opt/tests/admin/public-redirection.php
done
echo '#111 Free Admin workflow two-engine/default/Redis gate: PASS'
