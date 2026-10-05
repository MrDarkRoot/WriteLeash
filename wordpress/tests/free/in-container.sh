#!/bin/sh
set -eu
php /opt/tests/free/unit.php
php /opt/tests/free/sale-price.php
for host in mysql mariadb; do
  site="/tmp/wl107-$host"
  cp -R /opt/wp-core "$site"
  sh /opt/tests/stage-plugin.sh "$site"
  wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost="$host"
  wp --path="$site" core install --url="http://$host.example.test" --title=Free-Contract --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
  wp --path="$site" config set DISABLE_WP_CRON true --raw
  wp --path="$site" plugin install /opt/plugin-zips/redirection.5.5.2.zip --activate
  wp --path="$site" plugin install /opt/woo-zips/woocommerce.11.1.2.zip --activate
  wp --path="$site" plugin activate writeleash
  wp --path="$site" config set WP_DEBUG true --raw
  wp --path="$site" config set WP_DEBUG_LOG true --raw
  echo "#107 integration engine: $host"
  server_version=$(wp --path="$site" eval 'global $wpdb; echo $wpdb->get_var( "SELECT VERSION()" );')
  case "$host:$server_version" in
    mysql:8.0.44|mariadb:10.11.15-MariaDB-ubu2204) ;;
    *) echo "Wrong #107 DB fixture: $host:$server_version" >&2; exit 1 ;;
  esac
  echo "#107 pinned DB version: $server_version"
  wp --path="$site" eval-file /opt/tests/free/integration.php
  php /opt/tests/release/source-audit.php "$site/wp-content/plugins/writeleash"
  php /opt/tests/release/debug-audit.php "$site/wp-content/debug.log" "$host"
done
echo '#107 pinned two-engine Woo planning contract PASS'
