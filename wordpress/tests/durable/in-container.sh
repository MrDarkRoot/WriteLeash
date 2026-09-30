#!/bin/sh
set -eu
for host in mysql mariadb; do
  site="/tmp/wl108-$host"
  cp -R /opt/wp-core "$site"
  sh /opt/tests/stage-plugin.sh "$site"
  wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost="$host"
  wp --path="$site" config set WP_HTTP_BLOCK_EXTERNAL true --raw
  wp --path="$site" core install --url="http://$host.example.test" --title=Durable-Price --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
  wp --path="$site" config set DISABLE_WP_CRON true --raw
  wp --path="$site" plugin install /opt/plugin-zips/redirection.5.5.2.zip --activate
  wp --path="$site" plugin install /opt/woo-zips/woocommerce.11.1.2.zip --activate
  wp --path="$site" plugin activate writeleash
  wp --path="$site" plugin install /opt/woo-zips/redis-cache.2.7.0.zip --activate
  wp --path="$site" config set WP_REDIS_HOST redis
  wp --path="$site" config set WP_REDIS_CLIENT predis
  wp --path="$site" config set WP_REDIS_PREFIX "wl108-$host:"
  wp --path="$site" config set WP_DEBUG true --raw
  wp --path="$site" config set WP_DEBUG_LOG true --raw
  server_version=$(wp --path="$site" eval 'global $wpdb; echo $wpdb->get_var( "SELECT VERSION()" );')
  case "$host:$server_version" in
    mysql:8.0.44|mariadb:10.11.15-MariaDB-ubu2204) ;;
    *) echo "Wrong #108 DB fixture: $host:$server_version" >&2; exit 1 ;;
  esac
  echo "#108 pinned DB version: $server_version"
  for cache in default persistent; do
    if [ "$cache" = persistent ]; then
      wp --path="$site" redis enable
      wp --path="$site" redis status
    fi
    echo "#108 engine=$host cache=$cache"
    WL108_CACHE="$cache" wp --path="$site" eval-file /opt/tests/durable/integration.php
  done
  php /opt/tests/release/source-audit.php "$site/wp-content/plugins/writeleash"
  php /opt/tests/release/debug-audit.php "$site/wp-content/debug.log" "$host"
done
echo '#108 actual Woo CRUD + durable journal two-engine/default/Redis gate: PASS'
