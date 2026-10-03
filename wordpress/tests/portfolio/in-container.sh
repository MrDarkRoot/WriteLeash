#!/bin/sh
set -eu
for host in mysql mariadb; do
  site="/tmp/wl144-$host"
  cp -R /opt/wp-core "$site"
  sh /opt/tests/stage-plugin.sh "$site"
  php /opt/tests/portfolio/stage.php "$site" price-history
  php /opt/tests/portfolio/stage.php "$site" price-campaigns
  wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost="$host"
  wp --path="$site" config set WP_HTTP_BLOCK_EXTERNAL true --raw
  wp --path="$site" config set WP_ACCESSIBLE_HOSTS 127.0.0.1
  wp --path="$site" config set DISABLE_WP_CRON true --raw
  wp --path="$site" core install --url=http://127.0.0.1:8080 --title=Portfolio-Isolation --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
  wp --path="$site" plugin install /opt/woo-zips/woocommerce.11.1.2.zip --activate
  # Prove each satellite works with only Woo (no sibling dependency).
  for plugin in writeleash-price-history writeleash-price-campaigns; do
    wp --path="$site" plugin activate "$plugin"
    wp --path="$site" plugin deactivate "$plugin"
  done
  wp --path="$site" plugin activate writeleash writeleash-price-history writeleash-price-campaigns
  server_version=$(wp --path="$site" eval 'global $wpdb; echo $wpdb->get_var("SELECT VERSION()");')
  case "$host:$server_version" in
    mysql:8.0.44|mariadb:10.11.15-MariaDB-ubu2204) ;;
    *) echo 'Wrong portfolio DB fixture' >&2; exit 1 ;;
  esac
  test "$(wp --path="$site" core version)" = 7.1.2
  test "$(wp --path="$site" plugin get woocommerce --field=version)" = 11.1.2
  ISOLATION_MODE=seed wp --path="$site" eval-file /opt/tests/portfolio/integration.php
  # New PHP processes expose actual Core lifecycle behavior, not simulated hooks.
  for plugin in writeleash-price-history writeleash-price-campaigns writeleash; do
    ISOLATION_MODE=seed wp --path="$site" eval-file /opt/tests/portfolio/integration.php
    wp --path="$site" plugin deactivate "$plugin"
    ISOLATION_MODE=deactivated ISOLATION_PLUGIN="$plugin" wp --path="$site" eval-file /opt/tests/portfolio/integration.php
    wp --path="$site" plugin activate "$plugin"
  done
  # Test every uninstall order on restored installs, including flagship first.
  for first in writeleash writeleash-price-history writeleash-price-campaigns; do
    ISOLATION_MODE=seed wp --path="$site" eval-file /opt/tests/portfolio/integration.php
    wp --path="$site" plugin deactivate "$first"
    wp --path="$site" plugin uninstall "$first" --skip-delete
    ISOLATION_MODE=uninstalled ISOLATION_PLUGIN="$first" wp --path="$site" eval-file /opt/tests/portfolio/integration.php
    wp --path="$site" plugin activate "$first"
  done
  php /opt/tests/release/source-audit.php "$site/wp-content/plugins/writeleash"
  echo "#144 real three-plugin Woo fixture engine=$host target=$ISOLATION_TARGET source=$ISOLATION_SOURCE_SHA PASS"
done
