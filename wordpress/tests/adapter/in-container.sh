#!/bin/sh
# #87: install the pinned Redirection 5.5.2 and run the real Bulk Disable
# adapter suite on each pinned database engine in a disposable WordPress.
set -eu
for host in mysql mariadb; do
  site="/tmp/cc87-$host"
  echo "=== REDIRECTION ADAPTER: $host ==="
  rm -rf "$site"
  mkdir -p "$site/wp-content/plugins/commitcap-for-wordpress"
  cp -R /opt/wp-core/. "$site/"
  cp -R /opt/commitcap-for-wordpress/. "$site/wp-content/plugins/commitcap-for-wordpress/"
  wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost="$host"
  wp --path="$site" core install --url="http://$host.example.test" --title=Adapter-Test \
    --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
  wp --path="$site" plugin install /opt/plugin-zips/redirection.5.5.2.zip --activate --force
  wp --path="$site" plugin activate commitcap-for-wordpress
  server_version=$(wp --path="$site" eval 'global $wpdb; echo $wpdb->get_var( "SELECT VERSION()" );')
  plugin_version=$(wp --path="$site" eval 'echo REDIRECTION_VERSION;')
  echo "Adapter fixture: $host $server_version; Redirection $plugin_version; WordPress $(wp --path="$site" core version); PHP $(php -r 'echo PHP_VERSION;')"
  case "$host:$server_version:$plugin_version" in
    mysql:8.0.44:5.5.2|mariadb:10.11.15-MariaDB-ubu2204:5.5.2) ;;
    *) echo "Wrong fixture: $host $server_version Redirection $plugin_version" >&2; exit 1 ;;
  esac
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/adapter/cases.php
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/adapter/cases-78.php
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/adapter/cases-58.php
  # No runtime config yet: actual CLI entrypoints fail closed without a fatal.
  status_json=$(wp --path="$site" commitcap status --format=json)
  php -r '$d=json_decode($argv[1], true); if (!is_array($d) || $d["runtime_available"] !== false || $d["demo_status"] !== "NOT_READY") exit(1);' "$status_json"
  if missing_doctor=$(wp --path="$site" commitcap doctor --format=json); then echo "CLI Doctor succeeded without runtime" >&2; exit 1; fi
  if missing_demo=$(wp --path="$site" commitcap demo --format=json); then echo "CLI demo succeeded without runtime" >&2; exit 1; fi
  php -r '$d=json_decode($argv[1], true); if (!is_array($d) || $d["reason"] !== "runtime_unavailable") exit(1);' "$missing_demo"
  echo "#60 $host: CLI missing runtime status structured; doctor/demo nonzero with zero mutation: PASS"
  wp --path="$site" config set COMMITCAP_DB_USER cc87_writer --type=constant
  wp --path="$site" config set COMMITCAP_DB_PASSWORD cc87_secret --type=constant >/dev/null
  wp --path="$site" config set COMMITCAP_DB_NAME wp_test --type=constant
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/adapter/cases-60.php
  status_json=$(wp --path="$site" commitcap status --format=json)
  php -r '$d=json_decode($argv[1], true); if (!is_array($d) || $d["operation_status"] !== "READY" || $d["doctor_state"] !== "PASS" || $d["last_outcome"]["outcome"] !== "COMMITTED" || $d["demo_state"] !== "A") exit(1);' "$status_json"
  doctor_json=$(wp --path="$site" commitcap doctor --format=json)
  php -r '$d=json_decode($argv[1], true); if (!is_array($d) || $d["operation_status"] !== "READY" || $d["doctor_state"] !== "PASS") exit(1);' "$doctor_json"
  case "$status_json$doctor_json" in *cc87_secret*|*disposable_root_password*) echo "CLI JSON leaked credentials" >&2; exit 1 ;; esac
  human=$(wp --path="$site" commitcap status)
  case "$human" in *operation_id:*doctor_state:*last_outcome:*) ;; *) echo "Human CLI status incomplete" >&2; exit 1 ;; esac
  case "$human" in *cc87_secret*|*disposable_root_password*) echo "CLI secret leak" >&2; exit 1 ;; esac
  echo "#60 $host: CLI status/doctor READY human+JSON, last production outcome, no credentials: PASS"
  if wp --path="$site" commitcap demo --format=csv; then echo "CLI accepted invalid format" >&2; exit 1; fi
  if wp --path="$site" commitcap demo arbitrary; then echo "CLI accepted arbitrary argument" >&2; exit 1; fi
  for failure in p grant doctor; do
    CC_ENGINE_HOST="$host" CC60_PHASE="${failure}_bad" wp --path="$site" eval-file /opt/tests/adapter/cli-fixture-60.php
    drift_json=$(wp --path="$site" commitcap status --format=json)
    case "$failure" in p) reason=physical_ceiling_mismatch ;; grant) reason=target_privileges_mismatch ;; doctor) reason=doctor_not_ready ;; esac
    php -r '$d=json_decode($argv[1], true); if (!is_array($d) || $d["operation_status"] !== "NOT_READY" || $d["operation_reason"] !== $argv[2]) exit(1);' "$drift_json" "$reason"
    if wp --path="$site" commitcap doctor --format=json >/dev/null; then echo "CLI Doctor accepted $failure drift" >&2; exit 1; fi
    CC_ENGINE_HOST="$host" CC60_PHASE="${failure}_fix" wp --path="$site" eval-file /opt/tests/adapter/cli-fixture-60.php
  done
  echo "#60 $host: CLI doctor rejects live P/grant/policy drift; repaired production READY: PASS"
  CC_ENGINE_HOST="$host" CC60_PHASE=begin wp --path="$site" eval-file /opt/tests/adapter/cli-fixture-60.php
  for expected in B A B; do
    demo_json=$(wp --path="$site" commitcap demo --format=json)
    php -r '$d=json_decode($argv[1], true); if (!is_array($d) || $d["status"] !== "COMPLETE" || $d["safe_state"] !== $argv[2] || $d["safe"]["consumed"] !== 5 || $d["denied"]["consumed"] !== 6 || $d["denied"]["denial_kind"] !== "logical" || $d["denied"]["durability_verified_by_fresh_observer"] !== false) exit(1);' "$demo_json" "$expected"
    case "$demo_json" in *cc87_secret*|*disposable_root_password*) echo "CLI demo JSON leaked credentials" >&2; exit 1 ;; esac
    CC_ENGINE_HOST="$host" CC60_PHASE=observe CC60_EXPECTED="$expected" wp --path="$site" eval-file /opt/tests/adapter/cli-fixture-60.php
  done
  CC_ENGINE_HOST="$host" CC60_PHASE=end wp --path="$site" eval-file /opt/tests/adapter/cli-fixture-60.php
  CC_ENGINE_HOST="$host" CC60_PHASE=cleanup wp --path="$site" eval-file /opt/tests/adapter/cli-fixture-60.php
  if wp --path="$site" commitcap demo --format=json >/dev/null; then echo "CLI demo ran after trusted cleanup" >&2; exit 1; fi
  wp --path="$site" plugin deactivate redirection
  unsupported_json=$(wp --path="$site" commitcap status --format=json)
  php -r '$d=json_decode($argv[1], true); if (!is_array($d) || $d["operation_status"] !== "UNSUPPORTED" || $d["operation_reason"] !== "redirection_version_unsupported") exit(1);' "$unsupported_json"
  if wp --path="$site" commitcap doctor --format=json >/dev/null; then echo "CLI Doctor accepted missing Redirection version" >&2; exit 1; fi
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/adapter/cases-plugin-missing.php
done
echo "Redirection 5.5.2 adapter suite complete."
