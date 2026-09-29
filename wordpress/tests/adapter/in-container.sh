#!/bin/sh
# #87: install the pinned Redirection 5.5.2 and run the real Bulk Disable
# adapter suite on each pinned database engine in a disposable WordPress.
set -eu
# #61 concurrency safety: always release the trusted row-lock barrier and reap
# background helpers even when an assertion aborts the suite.
CC61_PIDS=""
CC61_MARKERS=""
CC61_FILES=""
cc61_cleanup() {
  for cc61_marker in $CC61_MARKERS; do
    touch "$cc61_marker" 2>/dev/null || true
  done
  for cc61_pid in $CC61_PIDS; do
    kill "$cc61_pid" 2>/dev/null || true
  done
  wait 2>/dev/null || true
  rm -f $CC61_FILES 2>/dev/null || true
}
trap cc61_cleanup EXIT
for host in mysql mariadb; do
  site="/tmp/cc87-$host"
  echo "=== REDIRECTION ADAPTER: $host ==="
  rm -rf "$site"
  cp -R /opt/wp-core/. "$site/"
  sh /opt/tests/stage-plugin.sh "$site"
  wp --path="$site" core config --dbname=wp_test --dbuser=wp_test --dbpass=disposable_wp_password --dbhost="$host"
  wp --path="$site" core install --url="http://$host.example.test" --title=Adapter-Test \
    --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email
  wp --path="$site" plugin install /opt/plugin-zips/redirection.5.5.2.zip --activate --force
  wp --path="$site" plugin activate commitcap
  server_version=$(wp --path="$site" eval 'global $wpdb; echo $wpdb->get_var( "SELECT VERSION()" );')
  plugin_version=$(wp --path="$site" eval 'echo REDIRECTION_VERSION;')
  echo "Adapter fixture: $host $server_version; Redirection $plugin_version; WordPress $(wp --path="$site" core version); PHP $(php -r 'echo PHP_VERSION;')"
  case "$host:$server_version:$plugin_version" in
    mysql:8.0.44:5.5.2|mariadb:10.11.15-MariaDB-ubu2204:5.5.2) ;;
    *) echo "Wrong fixture: $host $server_version Redirection $plugin_version" >&2; exit 1 ;;
  esac
  [ "$(wp --path="$site" core version)" = 6.8.3 ]
  [ "$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" = 8.2 ]
  wp --path="$site" eval-file /opt/tests/identity-62.php
  if [ "${CC62_PLUGIN_CHECK:-0}" = 1 ]; then
    wp --path="$site" plugin install /opt/plugin-zips/plugin-check.2.1.0.zip --activate --force
    check_version=$(wp --path="$site" plugin get plugin-check --field=version)
    [ "$check_version" = 2.1.0 ]
    echo "#62 $host: Plugin Check $check_version stable/static against installed commitcap/"
    # Capture both output and exit: PCP returns nonzero for findings, classified
    # by code below (never hidden or passed to --ignore-codes).
    check_json="/tmp/cc62-plugin-check-$host.json"
    if wp --path="$site" plugin check commitcap --format=json >"$check_json"; then
      check_exit=0
    else
      check_exit=$?
    fi
    php /opt/tests/release/plugin-check-results.php "$host" "$check_exit" "$check_json"
    rm -f "$check_json"
  fi
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
  # Debug-enabled *product* requests in their own CLI process. Keep the
  # adversarial wrong-credential fixture under its original WP_DEBUG setting:
  # core's wpdb constructor intentionally wp_die()s instead of returning there.
  wp --path="$site" config set WP_DEBUG true --raw >/dev/null
  wp --path="$site" config set WP_DEBUG_LOG true --raw >/dev/null
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/adapter/debug-62.php
  php /opt/tests/release/debug-audit.php "$site/wp-content/debug.log" "$host"
  wp --path="$site" config set WP_DEBUG false --raw >/dev/null
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
  # #61 final threat-model adversarial matrix on the production surfaces.
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/adapter/cases-61-threat-model.php
  # Deactivation boundary: CommitCap inactive -> certified global Disable is stock.
  wp --path="$site" plugin deactivate commitcap
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/adapter/cases-61-deactivated.php
  wp --path="$site" plugin activate commitcap
  # Deterministic concurrency: trusted two-stage row-lock barrier. Two real
  # certified Redirection requests must both be observed blocked on the
  # certified target UPDATE in distinct restricted sessions before release.
  CC_ENGINE_HOST="$host" CC61_PHASE=seed wp --path="$site" eval-file /opt/tests/adapter/concurrent-61.php
  cc61_ready_a="/tmp/cc61-lock-a-$host.ready"
  cc61_ready_b="/tmp/cc61-lock-b-$host.ready"
  cc61_release_a="/tmp/cc61-release-a-$host"
  cc61_release_b="/tmp/cc61-release-b-$host"
  cc61_lock_a_log="/tmp/cc61-lock-a-$host.log"
  cc61_lock_b_log="/tmp/cc61-lock-b-$host.log"
  cc61_run_a_json="/tmp/cc61-run-1-$host.json"
  cc61_run_a_err="/tmp/cc61-run-1-$host.err"
  cc61_run_b_json="/tmp/cc61-run-2-$host.json"
  cc61_run_b_err="/tmp/cc61-run-2-$host.err"
  CC61_MARKERS="$cc61_release_a $cc61_release_b"
  CC61_FILES="$cc61_ready_a $cc61_ready_b $cc61_release_a $cc61_release_b $cc61_lock_a_log $cc61_lock_b_log $cc61_run_a_json $cc61_run_a_err $cc61_run_b_json $cc61_run_b_err"
  rm -f $CC61_FILES
  CC_ENGINE_HOST="$host" CC61_PHASE=lock_a CC61_READY="$cc61_ready_a" CC61_RELEASE="$cc61_release_a" \
    wp --path="$site" eval-file /opt/tests/adapter/concurrent-61.php >"$cc61_lock_a_log" 2>&1 &
  cc61_lock_a_pid=$!
  CC_ENGINE_HOST="$host" CC61_PHASE=lock_b CC61_READY="$cc61_ready_b" CC61_RELEASE="$cc61_release_b" \
    wp --path="$site" eval-file /opt/tests/adapter/concurrent-61.php >"$cc61_lock_b_log" 2>&1 &
  cc61_lock_b_pid=$!
  CC61_PIDS="$cc61_lock_a_pid $cc61_lock_b_pid"
  cc61_wait_ready() {
    cc61_attempt=0
    while [ ! -f "$1" ] && [ "$cc61_attempt" -lt 150 ]; do
      sleep 0.2
      cc61_attempt=$((cc61_attempt + 1))
    done
    [ -f "$1" ]
  }
  if ! cc61_wait_ready "$cc61_ready_a" || ! cc61_wait_ready "$cc61_ready_b"; then
    echo "trusted barrier locks were not acquired" >&2
    cat "$cc61_lock_a_log" "$cc61_lock_b_log" >&2
    exit 1
  fi
  CC_ENGINE_HOST="$host" CC61_PHASE=barrier wp --path="$site" eval-file /opt/tests/adapter/concurrent-61.php
  CC_ENGINE_HOST="$host" CC61_PHASE=run wp --path="$site" eval-file /opt/tests/adapter/concurrent-61.php >"$cc61_run_a_json" 2>"$cc61_run_a_err" &
  cc61_run_a_pid=$!
  CC_ENGINE_HOST="$host" CC61_PHASE=run wp --path="$site" eval-file /opt/tests/adapter/concurrent-61.php >"$cc61_run_b_json" 2>"$cc61_run_b_err" &
  cc61_run_b_pid=$!
  CC61_PIDS="$CC61_PIDS $cc61_run_a_pid $cc61_run_b_pid"
  # Stage 1: both restricted sessions must queue on the row-1 barrier before it
  # is released, so neither can race ahead through the Doctor probe.
  cc61_probe_json=$(CC_ENGINE_HOST="$host" CC61_PHASE=await_overlap CC61_STAGE=probe wp --path="$site" eval-file /opt/tests/adapter/concurrent-61.php)
  php -r '$d=json_decode($argv[1], true); $s=$d["sessions"]; if (count($s) < 2 || $s[0]["id"] === $s[1]["id"]) exit(1);' "$cc61_probe_json"
  echo "#61 $host: both restricted requests queued on trusted row-1 barrier (doctor probe): $(printf '%s' "$cc61_probe_json" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo implode(",", array_column($d["sessions"], "id"));')"
  touch "$cc61_release_a"
  # Stage 2: both distinct restricted sessions must now be blocked on the
  # certified Redirection UPDATE while the rows-2-6 barrier is still held.
  cc61_update_json=$(CC_ENGINE_HOST="$host" CC61_PHASE=await_overlap CC61_STAGE=update wp --path="$site" eval-file /opt/tests/adapter/concurrent-61.php)
  php -r '$d=json_decode($argv[1], true); $s=$d["sessions"]; if (count($s) < 2 || $s[0]["id"] === $s[1]["id"] || false === stripos($s[0]["info"], "status=\x27disabled\x27") || false === stripos($s[1]["info"], "status=\x27disabled\x27")) exit(1);' "$cc61_update_json"
  cc61_ids=$(printf '%s' "$cc61_update_json" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo implode(",", array_column($d["sessions"], "id"));')
  echo "#61 $host: concurrent overlap proven: runtime connection ids=$cc61_ids both blocked on certified Redirection UPDATE while rows-2-6 barrier held"
  touch "$cc61_release_b"
  cc61_status=0
  wait "$cc61_run_a_pid" || cc61_status=1
  wait "$cc61_run_b_pid" || cc61_status=1
  wait "$cc61_lock_a_pid" || cc61_status=1
  wait "$cc61_lock_b_pid" || cc61_status=1
  if [ "$cc61_status" -ne 0 ]; then
    echo "concurrent certified request or barrier release failed" >&2
    cat "$cc61_run_a_json" "$cc61_run_a_err" "$cc61_run_b_json" "$cc61_run_b_err" "$cc61_lock_a_log" "$cc61_lock_b_log" >&2
    exit 1
  fi
  for cc61_run in "1-$host" "2-$host"; do
    cc61_json=$(cat "/tmp/cc61-run-$cc61_run.json")
    php -r '$d=json_decode($argv[1], true); if (!is_array($d) || $d["status"] !== 200 || $d["outcome"] !== "COMMITTED" || $d["consumed"] !== 6 || $d["logical_budget"] !== 10 || !in_array($d["affected_rows"], array(0, 6, null), true)) exit(1);' "$cc61_json"
  done
  echo "#61 $host: barrier released after proof; both requests COMMITTED (consumed 6, L=10)"
  CC_ENGINE_HOST="$host" CC61_PHASE=verify wp --path="$site" eval-file /opt/tests/adapter/concurrent-61.php
  CC61_PIDS=""
  CC61_MARKERS=""
  CC61_FILES=""
  rm -f "$cc61_ready_a" "$cc61_ready_b" "$cc61_release_a" "$cc61_release_b" "$cc61_lock_a_log" "$cc61_lock_b_log" "$cc61_run_a_json" "$cc61_run_a_err" "$cc61_run_b_json" "$cc61_run_b_err"
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
  # Actual WordPress uninstall: local options removed, trusted DB objects kept.
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/adapter/cases-60-uninstall.php
  CC_ENGINE_HOST="$host" wp --path="$site" eval-file /opt/tests/adapter/cases-60-reinstall.php
done
echo "Redirection 5.5.2 adapter suite complete."
