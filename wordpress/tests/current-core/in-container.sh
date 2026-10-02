#!/bin/sh
# #63 current-stable compatibility gate: WordPress 7.1.2 + Redirection 5.5.2 +
# the repository-only historical lab on both pinned database engines. Focused on
# the certified product path and lifecycle; the full adversarial matrix remains
# pinned to the WordPress 6.8.3 baseline (#62).
set -eu
for host in mysql mariadb; do
  site="/tmp/cc63-$host"
  echo "=== CURRENT-CORE COMPATIBILITY: $host ==="
  rm -rf "$site"
  cp -R /opt/wp-core/. "$site/"
  sh /opt/tests/legacy/stage.sh "$site"
  wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost="$host"
  wp --path="$site" core install --url="http://$host.example.test" --title=Current-Core \
    --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
  wp --path="$site" plugin install /opt/plugin-zips/redirection.5.5.2.zip --activate --force
  wp --path="$site" plugin install /opt/plugin-zips/woocommerce.11.1.2.zip --activate
  wp --path="$site" plugin activate writeleash
  wp_version=$(wp --path="$site" core version)
  server_version=$(wp --path="$site" eval 'global $wpdb; echo $wpdb->get_var( "SELECT VERSION()" );')
  plugin_version=$(wp --path="$site" eval 'echo REDIRECTION_VERSION;')
  php_version=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
  echo "#63 current-core $host: WordPress $wp_version; Redirection $plugin_version; $server_version; PHP $php_version"
  [ "$wp_version" = 7.1.2 ]
  [ "$php_version" = 8.2 ]
  case "$host:$server_version:$plugin_version" in
    mysql:8.0.44:5.5.2|mariadb:10.11.15-MariaDB-ubu2204:5.5.2) ;;
    *) echo "Wrong fixture: $host $server_version Redirection $plugin_version" >&2; exit 1 ;;
  esac
  wp --path="$site" eval-file /opt/tests/identity-62.php
  php /opt/tests/release/source-audit.php "$site/wp-content/plugins/writeleash"
  php /opt/tests/release/readme-validate.php "$site/wp-content/plugins/writeleash"

  # Redirection installs its schema through its own setup API (as its wizard
  # does); the accepted 6.8.3 adapter suite uses the same API.
  wp --path="$site" eval-file /opt/tests/redirection-schema.php

  # The historical repository operator workflow is the provisioning path here: the
  # CI harness extracts the script from operator-setup.txt, executes review and
  # apply modes, and leaks no secret.
  sh /opt/tests/current-core/operator-example.sh "$host" "$site"

  wp --path="$site" config set WRITELEASH_DB_USER cc63_writer --type=constant
  wp --path="$site" config set WRITELEASH_DB_PASSWORD cc63_runtime_secret --type=constant >/dev/null
  wp --path="$site" config set WRITELEASH_DB_NAME wp_test --type=constant

  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/current-core/cases.php

  # Dedicated debug-enabled product slice (#63 requires zero WriteLeash
  # diagnostics under WP_DEBUG).
  wp --path="$site" config set WP_DEBUG true --raw >/dev/null
  wp --path="$site" config set WP_DEBUG_LOG true --raw >/dev/null
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/current-core/debug.php
  php /opt/tests/release/debug-audit.php "$site/wp-content/debug.log" "$host"
  wp --path="$site" config set WP_DEBUG false --raw >/dev/null

  status_json=$(wp --path="$site" writeleash status --format=json)
  php -r '$d=json_decode($argv[1], true); if (!is_array($d) || $d["operation_status"] !== "READY" || $d["doctor_state"] !== "PASS" || $d["last_outcome"]["outcome"] !== "COMMITTED") exit(1);' "$status_json"
  doctor_json=$(wp --path="$site" writeleash doctor --format=json)
  php -r '$d=json_decode($argv[1], true); if (!is_array($d) || $d["operation_status"] !== "READY" || $d["doctor_state"] !== "PASS") exit(1);' "$doctor_json"
  case "$status_json$doctor_json" in *cc63_runtime_secret*|*disposable_root_password*) echo "current-core CLI JSON leaked credentials" >&2; exit 1 ;; esac
  echo "#63 current-core $host: WP-CLI status/doctor READY with JSON output and no credentials: PASS"

  # #96: the old CommitCap command is gone, not aliased.
  if wp --path="$site" commitcap status --format=json >/dev/null 2>&1; then
    echo "#96 legacy wp commitcap command is still registered" >&2
    exit 1
  fi
  echo "#96 current-core $host: wp writeleash registered; wp commitcap unregistered: PASS"

  # #61 deactivation boundary remains: WriteLeash inactive, Redirection active.
  wp --path="$site" plugin deactivate writeleash
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/adapter/cases-61-deactivated.php
  wp --path="$site" plugin activate writeleash

  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/current-core/lifecycle.php
done
echo "Current-core WordPress 7.1.2 compatibility suite complete."
