#!/bin/sh
set -eu
site=/tmp/writeleash-round7-wc-1044
cp -R /opt/wp-core "$site"
sh /opt/tests/stage-plugin.sh "$site"
wp --path="$site" core config --dbname=round7_wc_1044 --dbuser=wp_test --dbpass=disposable_wp_password --dbhost=mysql
wp --path="$site" config set WP_HTTP_BLOCK_EXTERNAL true --raw
wp --path="$site" core install --url=http://round7-1044.example.test --title=Round7Version --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
wp --path="$site" config set DISABLE_WP_CRON true --raw
wp --path="$site" plugin install /opt/woo-zips/woocommerce.10.4.4.zip --activate
wp --path="$site" plugin activate writeleash
wp --path="$site" plugin install /opt/woo-zips/redis-cache.2.7.0.zip --activate
wp --path="$site" config set WP_REDIS_HOST redis
wp --path="$site" config set WP_REDIS_CLIENT predis
wp --path="$site" config set WP_REDIS_PREFIX round7-1044:
wp --path="$site" option update woocommerce_feature_product_instance_caching_enabled yes
