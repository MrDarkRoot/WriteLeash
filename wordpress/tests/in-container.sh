#!/bin/sh
set -eu
case "${CC_DB_FAMILY:-}" in
  mysql|mariadb) db="$CC_DB_FAMILY" ;;
  *) echo 'Expected CC_DB_FAMILY=mysql or mariadb' >&2; exit 2 ;;
esac
site=/tmp/writeleash-site
cp -R /opt/wp-core/. "$site/"
sh /opt/tests/legacy/stage.sh "$site"
wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost="$db"
wp --path="$site" core install --url=http://example.test --title=WriteLeash-Test \
  --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
server_version=$(wp --path="$site" eval 'global $wpdb; echo $wpdb->get_var( "SELECT VERSION()" );')
case "$db:$server_version" in
  mysql:8.0.44|mariadb:10.11.15-MariaDB-ubu2204) ;;
  *) echo "Wrong database fixture: requested $db, observed $server_version" >&2; exit 1 ;;
esac
echo "Database fixture: $db $server_version; WordPress $(wp --path="$site" core version); PHP $(php -r 'echo PHP_VERSION;')"
[ "$(wp --path="$site" core version)" = 6.8.3 ]
[ "$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" = 8.2 ]

# The default Free product requires WooCommerce, not Redirection: Core
# enforcement is asserted with WooCommerce missing, then the pinned Woo build
# satisfies the dependency. Redirection stays installed for the Guard
# regression schema check; it is no longer an activation prerequisite.
CC63_PHASE=block wp --path="$site" eval-file /opt/tests/dependency-63.php
wp --path="$site" plugin install /opt/plugin-zips/redirection.5.5.2.zip --activate --force
wp --path="$site" eval-file /opt/tests/redirection-schema.php
# 6.8.3-leg activation shim (see run.sh): Woo 9.9.7 satisfies Core's slug
# gate so the Guard regression leg can activate. Free behavior is never
# exercised against this build; #111 evidence stays 11.1.2 on WP 7.1.2.
export CC63_WOO_VERSION=9.9.7
wp --path="$site" plugin install /opt/plugin-zips/woocommerce.9.9.7.zip --activate
CC63_PHASE=installed wp --path="$site" eval-file /opt/tests/dependency-63.php
wp --path="$site" plugin activate writeleash
CC63_PHASE=dependents wp --path="$site" eval-file /opt/tests/dependency-63.php
wp --path="$site" plugin deactivate woocommerce
CC63_PHASE=lost wp --path="$site" eval-file /opt/tests/dependency-63.php
wp --path="$site" plugin activate woocommerce
wp --path="$site" plugin deactivate writeleash
CC63_PHASE=released wp --path="$site" eval-file /opt/tests/dependency-63.php
# The dependency fixture activated WriteLeash once; restore the uninstalled
# baseline that the failure and lifecycle suites assert on.
wp --path="$site" option delete writeleash_version >/dev/null 2>&1 || true

wp --path="$site" eval-file /opt/tests/identity-62.php
php /opt/tests/release/source-audit.php "$site/wp-content/plugins/writeleash"
php /opt/tests/release/readme-validate.php "$site/wp-content/plugins/writeleash"

for file in "$site"/wp-content/plugins/writeleash/*.php "$site"/wp-content/plugins/writeleash/includes/*.php; do
  php -l "$file"
  # Direct execution must exit before reaching a subsequent marker.
  result=$(php -n -r 'include $argv[1]; print "UNGUARDED";' "$file")
  if [ -n "$result" ]; then
    echo "Direct execution reached marker or emitted output: $file" >&2
    exit 1
  fi
done
echo 'PHP lint and direct access: PASS'

for mode in null invalid version query-exception write network; do
  if result=$(CC_FAILURE="$mode" wp --path="$site" eval-file /opt/tests/failure.php 2>&1); then
    echo "Activation unexpectedly succeeded in $mode failure test: $result" >&2
    exit 1
  fi
  case "$result" in
    *"WriteLeash "*) : ;;
    *) echo "Unexpected $mode failure output: $result" >&2; exit 1 ;;
  esac
  if wp --path="$site" option get writeleash_version >/dev/null 2>&1; then
    echo "Activation left metadata after $mode failure" >&2
    exit 1
  fi
  if wp --path="$site" plugin is-active writeleash; then
    echo "Plugin active after $mode failure" >&2
    exit 1
  fi
  echo "Activation $mode failure: PASS"
done

wp --path="$site" eval-file /opt/tests/uninstall-unactivated.php
wp --path="$site" eval-file /opt/tests/lifecycle.php
