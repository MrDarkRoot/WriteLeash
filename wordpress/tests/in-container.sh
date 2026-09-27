#!/bin/sh
set -eu
site=/tmp/commitcap-site
mkdir -p "$site/wp-content/plugins/commitcap-for-wordpress"
cp -R /opt/wp-core/. "$site/"
cp -R /opt/commitcap-for-wordpress/. "$site/wp-content/plugins/commitcap-for-wordpress/"
wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost=db
wp --path="$site" core install --url=http://example.test --title=CommitCap-Test \
  --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email

for file in "$site"/wp-content/plugins/commitcap-for-wordpress/*.php "$site"/wp-content/plugins/commitcap-for-wordpress/includes/*.php; do
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
    *"CommitCap "*) : ;;
    *) echo "Unexpected $mode failure output: $result" >&2; exit 1 ;;
  esac
  if wp --path="$site" option get commitcap_version >/dev/null 2>&1; then
    echo "Activation left metadata after $mode failure" >&2
    exit 1
  fi
  if wp --path="$site" plugin is-active commitcap-for-wordpress; then
    echo "Plugin active after $mode failure" >&2
    exit 1
  fi
  echo "Activation $mode failure: PASS"
done

wp --path="$site" eval-file /opt/tests/uninstall-unactivated.php
wp --path="$site" eval-file /opt/tests/lifecycle.php
