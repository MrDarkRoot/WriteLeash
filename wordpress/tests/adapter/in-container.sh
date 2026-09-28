#!/bin/sh
# #87: install the pinned Redirection 5.5.2 and run the real Bulk Disable
# adapter suite on each pinned database engine in a disposable WordPress.
set -eu
for host in mysql mariadb; do
  site="/tmp/cc87-$host"
  echo "=== REDIRECTION ADAPTER: $host ==="
  rm -rf "$site"
  mkdir -p "$site/wp-content/plugins/commitcap-for-wordpress"
  cp -R /opt/wp-core/. "$site/"
  cp -R /opt/commitcap-for-wordpress/. "$site/wp-content/plugins/commitcap-for-wordpress/"
  wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost="$host"
  wp --path="$site" core install --url="http://$host.example.test" --title=Adapter-Test \
    --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
  wp --path="$site" plugin install /opt/plugin-zips/redirection.5.5.2.zip --activate --force
  wp --path="$site" plugin activate commitcap-for-wordpress
  server_version=$(wp --path="$site" eval 'global $wpdb; echo $wpdb->get_var( "SELECT VERSION()" );')
  plugin_version=$(wp --path="$site" eval 'echo REDIRECTION_VERSION;')
  echo "Adapter fixture: $host $server_version; Redirection $plugin_version; WordPress $(wp --path="$site" core version); PHP $(php -r 'echo PHP_VERSION;')"
  case "$host:$server_version:$plugin_version" in
    mysql:8.0.44:5.5.2|mariadb:10.11.15-MariaDB-ubu2204:5.5.2) ;;
    *) echo "Wrong fixture: $host $server_version Redirection $plugin_version" >&2; exit 1 ;;
  esac
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/adapter/cases.php
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/adapter/cases-78.php
  wp --path="$site" plugin deactivate redirection
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/adapter/cases-plugin-missing.php
done
echo "Redirection 5.5.2 adapter suite complete."
