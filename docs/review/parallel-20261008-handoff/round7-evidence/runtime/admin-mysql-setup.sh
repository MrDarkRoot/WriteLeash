#!/bin/sh
set -eu
site=/tmp/writeleash-round7-admin-mysql
cp -R /opt/wp-core "$site"
sh /opt/tests/stage-plugin.sh "$site"
wp --path="$site" core config --dbname=round7_admin --dbuser=wp_test --dbpass=disposable_wp_password --dbhost=mysql
wp --path="$site" config set WP_HTTP_BLOCK_EXTERNAL true --raw
wp --path="$site" core install --url=http://round7-admin-mysql.example.test --title=Round7Admin --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
wp --path="$site" config set DISABLE_WP_CRON true --raw
mkdir -p "$site/wp-content/mu-plugins"
printf '%s\n' '<?php add_filter("action_scheduler_allow_async_request_runner", "__return_false");' >"$site/wp-content/mu-plugins/wl111-manual-resume.php"
wp --path="$site" plugin install /opt/woo-zips/woocommerce.11.1.2.zip --activate
wp --path="$site" plugin activate writeleash
wp --path="$site" plugin install /opt/woo-zips/redis-cache.2.7.0.zip --activate
wp --path="$site" config set WP_REDIS_HOST redis
wp --path="$site" config set WP_REDIS_CLIENT predis
wp --path="$site" config set WP_REDIS_PREFIX round7-admin-mysql:
