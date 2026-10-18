#!/bin/bash
set -euo pipefail
site=/tmp/wlruntime/sites/round7-browser
/tmp/wlruntime/mysql/bin/mysql --no-defaults --socket=/tmp/wlruntime/sock/mysql.sock -u root -pdisposable_root_password -e "CREATE DATABASE round7_browser; GRANT ALL PRIVILEGES ON round7_browser.* TO 'wp_test'@'%'; FLUSH PRIVILEGES;" 2>/dev/null
cp -R /opt/wp-core "$site"
cp -R /opt/writeleash "$site/wp-content/plugins/writeleash"
wp --path="$site" core config --dbname=round7_browser --dbuser=wp_test --dbpass=disposable_wp_password --dbhost=127.0.0.2 --dbprefix=round7_
wp --path="$site" config set WP_HTTP_BLOCK_EXTERNAL true --raw
wp --path="$site" config set WP_ACCESSIBLE_HOSTS 127.0.0.1
wp --path="$site" config set DISABLE_WP_CRON true --raw
wp --path="$site" core install --url=http://127.0.0.1:8189 --title=Round7-Browser --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
wp --path="$site" plugin install /opt/woo-zips/woocommerce.11.1.2.zip --activate
wp --path="$site" plugin activate writeleash
wp --path="$site" core version
wp --path="$site" eval 'echo "PHP=".PHP_VERSION."; Woo=".WC_VERSION."; Database=".$GLOBALS["wpdb"]->db_version()."; ext_object_cache=".(wp_using_ext_object_cache()?"true":"false")."\n";'
