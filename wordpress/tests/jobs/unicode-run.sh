#!/bin/sh
# #171 isolated real WordPress/Woo fixture. Existing owner suites deliberately
# drop/recreate job tables; their negative controls must not orphan this fixture's Undo.
set -eu
host=$1
cache=$2
core=$3
zips=$4
case "$host:$cache" in mysql:default|mysql:persistent|mariadb:default|mariadb:persistent) ;; *) exit 2 ;; esac
here=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
site=$(mktemp -d "/tmp/wl171-$host-$cache.XXXXXX")
trap 'rm -rf "$site"' EXIT
namespace=$(php -r 'echo bin2hex(random_bytes(4));')
prefix="wl171_${cache}_${namespace}_"
cp -R "$core/." "$site"
sh "$here/../stage-plugin.sh" "$site"
wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost="${WL171_DB_HOST:-$host}" --dbprefix="$prefix"
wp --path="$site" config set WP_HTTP_BLOCK_EXTERNAL true --raw
wp --path="$site" core install --url="http://$host.example.test" --title=Unicode-Storage --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
wp --path="$site" config set DISABLE_WP_CRON true --raw
wp --path="$site" config set WP_DEBUG true --raw
wp --path="$site" config set WP_DEBUG_LOG true --raw
wp --path="$site" plugin install "$zips/woocommerce.11.1.2.zip" --activate
wp --path="$site" plugin activate writeleash
if [ "$cache" = persistent ]; then
  wp --path="$site" plugin install "$zips/redis-cache.2.7.0.zip" --activate
  wp --path="$site" config set WP_REDIS_HOST "${WL171_REDIS_HOST:-redis}"
  wp --path="$site" config set WP_REDIS_PORT "${WL171_REDIS_PORT:-6379}"
  wp --path="$site" config set WP_REDIS_CLIENT predis
  wp --path="$site" config set WP_REDIS_PREFIX "wl171-$host-$namespace:"
  wp --path="$site" redis enable
  wp --path="$site" redis status
fi
echo "#171 isolated engine=$host cache=$cache"
wp --path="$site" eval-file "$here/unicode.php"
php "$here/../release/debug-audit.php" "$site/wp-content/debug.log" "$host"
