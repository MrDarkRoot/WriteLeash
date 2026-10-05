#!/bin/sh
set -eu
for host in mysql mariadb; do
  site="/tmp/wl112-$host"
  cp -R /opt/wp-core "$site"
  sh /opt/tests/stage-plugin.sh "$site"
  wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost="$host"
  wp --path="$site" config set WP_HTTP_BLOCK_EXTERNAL true --raw
  wp --path="$site" config set WP_ACCESSIBLE_HOSTS 127.0.0.1
  wp --path="$site" config set DISABLE_WP_CRON true --raw
  wp --path="$site" config set WP_MEMORY_LIMIT "$WL112_MEMORY"
  wp --path="$site" config set WP_MAX_MEMORY_LIMIT "$WL112_MEMORY"
  wp --path="$site" core install --url=http://127.0.0.1:8080 --title=Acceptance --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
  wp --path="$site" plugin install "/opt/woo-zips/woocommerce.$WL112_WOO.zip" --activate
  # Core Admin passes an explicit boolean network-wide flag. The archived PHP
  # 7.4 CLI command passes null instead; exercise the actual public WP activation
  # API with the Admin's false flag consistently on every PHP build.
  wp --path="$site" eval 'require_once ABSPATH . "wp-admin/includes/plugin.php"; $r=activate_plugin("writeleash/writeleash.php", "", false); if (is_wp_error($r)) { throw new RuntimeException($r->get_error_message()); }'
  wp --path="$site" plugin install /opt/woo-zips/redis-cache.2.7.0.zip --activate
  wp --path="$site" config set WP_REDIS_HOST redis
  wp --path="$site" config set WP_REDIS_CLIENT predis
  wp --path="$site" config set WP_REDIS_PREFIX "wl112-$host:"
  if [ "$WL112_CACHE" = persistent ]; then wp --path="$site" redis enable; fi
  wp --path="$site" config set WP_DEBUG true --raw
  wp --path="$site" config set WP_DEBUG_LOG true --raw
  wp --path="$site" config set WP_DEBUG_DISPLAY false --raw
  mkdir -p "$site/wp-content/mu-plugins"
  cp /opt/tests/acceptance/instrument.php "$site/wp-content/mu-plugins/wl112.php"
  cp /opt/tests/acceptance/hook-torture.php "$site/wp-content/mu-plugins/wl112-hooks.php"
  export WL112_METRICS="/evidence/$host-requests.jsonl"
  php -d "memory_limit=$WL112_MEMORY" -d "max_execution_time=$WL112_TIME" -S 127.0.0.1:8080 -t "$site" >"/evidence/$host-web.txt" 2>&1 &
  web_pid=$!
  if [ "$WL112_WOO" = 11.1.2 ]; then wp --path="$site" eval-file /opt/tests/acceptance/no-create.php; fi
  # Fixtures have their own memory/time envelope; all Admin requests use the
  # constrained server limits above. Evidence records both independently.
  for size in $WL112_SIZES; do
    WL112_SIZE="$size" wp --path="$site" eval-file /opt/tests/acceptance/journey.php
  done
  if [ "$WL112_WOO" = 11.1.2 ]; then
    wp --path="$site" eval-file /opt/tests/acceptance/support-boundaries.php
    wp --path="$site" eval-file /opt/tests/acceptance/variations.php
    wp --path="$site" eval-file /opt/tests/acceptance/dirty-catalog.php
    wp --path="$site" eval-file /opt/tests/acceptance/torture.php
  fi
  wp --path="$site" eval-file /opt/tests/acceptance/legacy-recovery.php
  kill "$web_pid"
  if [ "$WL112_WOO" = 11.1.2 ]; then wp --path="$site" eval-file /opt/tests/acceptance/lifecycle.php; fi
  php /opt/tests/acceptance/diagnostics.php "$site/wp-content/debug.log" "$host"
done
