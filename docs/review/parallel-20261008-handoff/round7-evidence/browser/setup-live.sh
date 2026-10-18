#!/bin/bash
set -euo pipefail
export PATH=/tmp/wlruntime/bin:$PATH
export WP_CLI_ALLOW_ROOT=1
export LD_LIBRARY_PATH=/tmp/wlruntime/lib
site=/tmp/wlruntime/sites/round7-live
/tmp/wlruntime/mysql/bin/mysql --no-defaults --socket=/tmp/wlruntime/sock/mysql.sock -uroot -pdisposable_root_password -e "CREATE DATABASE round7_live; GRANT ALL PRIVILEGES ON round7_live.* TO 'wp_test'@'%'; FLUSH PRIVILEGES;" 2>/dev/null
cp -a /tmp/wlruntime/sites/round7-browser "$site"
wp --path="$site" config set DB_NAME round7_live
wp --path="$site" core install --url=http://127.0.0.1:8190 --title=Round7-Live --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
wp --path="$site" plugin activate woocommerce writeleash
