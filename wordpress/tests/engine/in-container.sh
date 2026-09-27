#!/bin/sh
set -eu
for host in mysql mariadb; do
  site="/tmp/commitcap-engine-$host"
  mkdir -p "$site/wp-content/plugins/commitcap"
  cp -R /opt/wp-core/. "$site/"
  cp -R /opt/commitcap/. "$site/wp-content/plugins/commitcap/"
  wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost="$host"
  wp --path="$site" core install --url="http://$host.example.test" --title=Engine-Test \
    --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/engine/cases.php
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/guard/cases.php
done
