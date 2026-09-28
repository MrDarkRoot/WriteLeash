#!/bin/sh
# Gate #85: install each pinned real plugin into a disposable WordPress and run
# the actual Admin/operator operation with the MySQL general log enabled.
set -eu

site=/tmp/cc-research
mkdir -p "$site/wp-content/plugins/commitcap-for-wordpress"
cp -R /opt/wp-core/. "$site/"
cp -R /opt/commitcap-for-wordpress/. "$site/wp-content/plugins/commitcap-for-wordpress/"
wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost=mysql
wp --path="$site" core install --url=http://research.test --title=CommitCap-Research \
  --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
server_version=$(wp --path="$site" eval 'global $wpdb; echo $wpdb->get_var( "SELECT VERSION()" );')
echo "Research fixture: MySQL $server_version; WordPress $(wp --path="$site" core version); PHP $(php -r 'echo PHP_VERSION;')"

run_candidate() {
  plugin_file="$1"
  slug="$2"
  script="$3"
  echo "=== CANDIDATE $slug: install $plugin_file ==="
  wp --path="$site" plugin install "/opt/plugin-zips/$plugin_file" --activate --force
  wp --path="$site" eval-file "$script"
  echo "=== CANDIDATE $slug: deactivate and delete ==="
  wp --path="$site" plugin deactivate "$slug" || true
  wp --path="$site" plugin delete "$slug" || true
  wp --path="$site" db query "DROP TABLE IF EXISTS wp_relevanssi, wp_relevanssi_log, wp_relevanssi_stopwords, wp_relevanssi_tracking" >/dev/null 2>&1 || true
}

run_candidate redirection.5.5.2.zip redirection /opt/tests/research/candidates/redirection.php
run_candidate fluentform.5.2.9.zip fluentform /opt/tests/research/candidates/fluentform.php
run_candidate relevanssi.4.22.1.zip relevanssi /opt/tests/research/candidates/relevanssi.php

echo "Research run complete."
