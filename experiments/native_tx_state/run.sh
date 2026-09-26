#!/usr/bin/env bash
set -euo pipefail

EXPERIMENT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE=(docker compose -f "$EXPERIMENT_DIR/docker-compose.yml")
ADMIN_PASSWORD='commitcap_native_admin_experiment_only'
WRITER_PASSWORD='commitcap_writer_experiment_only'
CC_SESSION_DIR=""

# Optional complete transcript for a pinned tested-commit evidence run.
if [[ -n "${COMMITCAP_LOG:-}" ]]; then
    exec > >(tee "$COMMITCAP_LOG") 2>&1
fi

admin_psql() {
    "${COMPOSE[@]}" exec -T \
        -e "PGPASSWORD=$ADMIN_PASSWORD" \
        postgres psql -X -h 127.0.0.1 -U commitcap_native_admin -d commitcap_native "$@"
}

writer_psql() {
    "${COMPOSE[@]}" exec -T \
        -e "PGPASSWORD=$WRITER_PASSWORD" \
        postgres psql -X -h 127.0.0.1 -U commitcap_writer -d commitcap_native "$@"
}

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

assert_scalar() {
    local label="$1"
    local sql="$2"
    local expected="$3"
    local actual

    actual="$(admin_psql -At -v ON_ERROR_STOP=1 -c "$sql")"
    [[ "$actual" == "$expected" ]] || \
        fail "$label was $actual, expected $expected"
}

assert_value() {
    local label="$1"
    local actual="$2"
    local expected="$3"

    [[ "$actual" == "$expected" ]] || \
        fail "$label was $actual, expected $expected"
}

reset_fixture() {
    admin_psql -v ON_ERROR_STOP=1 -c \
        "TRUNCATE TABLE public.unprotected_audit, public.subscriptions RESTART IDENTITY; INSERT INTO public.subscriptions (id, status) SELECT id, 'baseline' FROM generate_series(1, 10) AS ids(id);" \
        >/dev/null
}

assert_baseline() {
    assert_scalar "durable protected baseline" \
        "SELECT count(*) FILTER (WHERE status = 'baseline') || ':' || count(*) FILTER (WHERE status <> 'baseline') FROM public.subscriptions;" \
        "10:0"
}

# Resets the users fixture without UPDATE statements so the state-transition
# trigger is never fired by trusted-admin setup work.
reset_users_fixture() {
    admin_psql -v ON_ERROR_STOP=1 -c \
        "TRUNCATE TABLE public.users; INSERT INTO public.users (id, tenant_id, role) SELECT id, 10, 'member' FROM generate_series(1, 6) AS ids(id);" \
        >/dev/null
}

assert_users_baseline() {
    assert_scalar "durable users baseline" \
        "SELECT count(*) FILTER (WHERE role = 'member') || ':' || count(*) FILTER (WHERE role <> 'member') FROM public.users;" \
        "6:0"
}

assert_five_updated() {
    local label="$1"
    local status="$2"

    assert_scalar "$label durable state" \
        "SELECT count(*) FILTER (WHERE status = 'baseline') || ':' || count(*) FILTER (WHERE status = '$status') FROM public.subscriptions;" \
        "5:5"
}

assert_privilege_envelope() {
    assert_scalar "writer elevated role attributes" \
        "SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls FROM pg_roles WHERE rolname = 'commitcap_writer';" \
        "f"
    assert_scalar "writer membership in trusted owner" \
        "SELECT pg_has_role('commitcap_writer', 'commitcap_owner', 'MEMBER');" \
        "f"
    assert_scalar "writer trusted schema access" \
        "SELECT has_schema_privilege('commitcap_writer', 'commitcap_native', 'USAGE') OR has_schema_privilege('commitcap_writer', 'commitcap_native', 'CREATE');" \
        "f"
    assert_scalar "writer direct enforcement-function EXECUTE" \
        "SELECT has_function_privilege('commitcap_writer', 'commitcap_native.enforce_update_budget()', 'EXECUTE');" \
        "f"
    assert_scalar "writer required column privileges" \
        "SELECT has_column_privilege('commitcap_writer', 'public.subscriptions', 'id', 'SELECT') AND has_column_privilege('commitcap_writer', 'public.subscriptions', 'status', 'UPDATE');" \
        "t"
    assert_scalar "writer table-wide or unsupported DML privileges" \
        "SELECT has_table_privilege('commitcap_writer', 'public.subscriptions', 'SELECT') OR has_table_privilege('commitcap_writer', 'public.subscriptions', 'UPDATE') OR has_table_privilege('commitcap_writer', 'public.subscriptions', 'INSERT') OR has_table_privilege('commitcap_writer', 'public.subscriptions', 'DELETE') OR has_table_privilege('commitcap_writer', 'public.subscriptions', 'TRUNCATE') OR has_table_privilege('commitcap_writer', 'public.subscriptions', 'TRIGGER');" \
        "f"
    assert_scalar "writer unprotected-audit INSERT column privilege" \
        "SELECT has_column_privilege('commitcap_writer', 'public.unprotected_audit', 'message', 'INSERT');" \
        "t"
    assert_scalar "writer unprotected-audit SELECT privilege" \
        "SELECT has_any_column_privilege('commitcap_writer', 'public.unprotected_audit', 'SELECT');" \
        "f"
    assert_scalar "writer unprotected-audit extra table privileges" \
        "SELECT has_table_privilege('commitcap_writer', 'public.unprotected_audit', 'UPDATE') OR has_table_privilege('commitcap_writer', 'public.unprotected_audit', 'DELETE') OR has_table_privilege('commitcap_writer', 'public.unprotected_audit', 'TRUNCATE') OR has_table_privilege('commitcap_writer', 'public.unprotected_audit', 'REFERENCES') OR has_table_privilege('commitcap_writer', 'public.unprotected_audit', 'TRIGGER');" \
        "f"
    assert_scalar "protected relation owner" \
        "SELECT pg_get_userbyid(c.relowner) FROM pg_class AS c JOIN pg_namespace AS n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relname = 'subscriptions';" \
        "commitcap_owner"
    assert_scalar "users relation owner" \
        "SELECT pg_get_userbyid(c.relowner) FROM pg_class AS c JOIN pg_namespace AS n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relname = 'users';" \
        "commitcap_owner"
    assert_scalar "writer users required column privileges" \
        "SELECT has_column_privilege('commitcap_writer', 'public.users', 'id', 'SELECT') AND has_column_privilege('commitcap_writer', 'public.users', 'role', 'UPDATE');" \
        "t"
    assert_scalar "writer users table-wide or unsupported DML privileges" \
        "SELECT has_table_privilege('commitcap_writer', 'public.users', 'SELECT') OR has_table_privilege('commitcap_writer', 'public.users', 'UPDATE') OR has_table_privilege('commitcap_writer', 'public.users', 'INSERT') OR has_table_privilege('commitcap_writer', 'public.users', 'DELETE') OR has_table_privilege('commitcap_writer', 'public.users', 'TRUNCATE') OR has_table_privilege('commitcap_writer', 'public.users', 'TRIGGER');" \
        "f"
    assert_scalar "enforcement function owner" \
        "SELECT pg_get_userbyid(p.proowner) FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace WHERE n.nspname = 'commitcap_native' AND p.proname = 'enforce_update_budget';" \
        "commitcap_owner"
    assert_scalar "transition function owner" \
        "SELECT pg_get_userbyid(p.proowner) FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace WHERE n.nspname = 'commitcap_native' AND p.proname = 'enforce_role_transition';" \
        "commitcap_owner"
    assert_scalar "writer direct transition-function EXECUTE" \
        "SELECT has_function_privilege('commitcap_writer', 'commitcap_native.enforce_role_transition()', 'EXECUTE');" \
        "f"
    assert_scalar "writer read-only probe EXECUTE" \
        "SELECT has_function_privilege('commitcap_writer', 'commitcap_probe.cc_native_probe()', 'EXECUTE');" \
        "t"
    assert_scalar "writer probe schema CREATE" \
        "SELECT has_schema_privilege('commitcap_writer', 'commitcap_probe', 'CREATE');" \
        "f"
    assert_scalar "probe function SECURITY DEFINER" \
        "SELECT prosecdef FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace WHERE n.nspname = 'commitcap_probe' AND p.proname = 'cc_native_probe';" \
        "f"
    assert_scalar "probe function owner" \
        "SELECT pg_get_userbyid(p.proowner) FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace WHERE n.nspname = 'commitcap_probe' AND p.proname = 'cc_native_probe';" \
        "commitcap_owner"

    printf 'privilege-envelope checks: PASS\n'
}

cc_sqlstate_class() {
    case "$1" in
        22023) printf 'invalid_parameter_value' ;;
        42501) printf 'insufficient_privilege' ;;
        42710) printf 'duplicate_object' ;;
        55000) printf 'object_not_in_prerequisite_state' ;;
        00000) printf 'success' ;;
        *) printf 'unclassified' ;;
    esac
}

cc_denied_attempt() {
    local label="$1"
    local expected_sqlstate="$2"
    local sql="$3"
    local marker
    local output
    local sqlstate

    marker="CCATTEMPT_$(printf '%s' "$label" | tr -c 'A-Za-z0-9' '_')"
    set +e
    output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<SQL
$sql;
\echo $marker :SQLSTATE
SQL
)"
    set -e
    sqlstate="$(printf '%s\n' "$output" | grep -F "$marker " | head -n 1 | awk '{print $2}')"
    [[ -n "$sqlstate" ]] || fail "$label: no SQLSTATE observed (output: $output)"
    [[ "$sqlstate" == "$expected_sqlstate" ]] || \
        fail "$label: SQLSTATE $sqlstate ($(cc_sqlstate_class "$sqlstate")), expected $expected_sqlstate"
    printf '  %-44s SQLSTATE %s %s\n' "$label" "$sqlstate" "$(cc_sqlstate_class "$sqlstate")"
}

# Trusted-admin-only test configuration for CC-030/CC-031. The module is
# preloaded, so these GUCs are defined and validated in every session.
cc_config_set() {
    admin_psql -v ON_ERROR_STOP=1 -c "ALTER ROLE commitcap_writer SET $1 = $2;" >/dev/null
}

cc_config_reset() {
    admin_psql -v ON_ERROR_STOP=1 -c "ALTER ROLE commitcap_writer RESET $1;" >/dev/null
}

cc_config_stored() {
    admin_psql -At -v ON_ERROR_STOP=1 -c \
        "SELECT COALESCE((SELECT string_agg(s, ',' ORDER BY s) FROM unnest(rolconfig) AS s WHERE s LIKE 'commitcap_native.%'), '(none)') FROM pg_roles WHERE rolname = 'commitcap_writer';"
}

cc_config_attempt() {
    local label="$1"
    local sql="$2"
    local expected_sqlstate="$3"
    local output
    local sqlstate

    set +e
    output="$(admin_psql -At -q -v ON_ERROR_STOP=0 2>&1 <<SQL
$sql;
\echo CC_CONFIG_${label} :SQLSTATE
SQL
)"
    set -e
    sqlstate="$(printf '%s\n' "$output" | grep -F "CC_CONFIG_${label} " | head -n 1 | awk '{print $2}')"
    [[ -n "$sqlstate" ]] || fail "CC-031 $label: no SQLSTATE observed (output: $output)"
    [[ "$sqlstate" == "$expected_sqlstate" ]] || \
        fail "CC-031 $label: SQLSTATE $sqlstate ($(cc_sqlstate_class "$sqlstate")), expected $expected_sqlstate"
    printf '  %-44s SQLSTATE %s %s\n' "$label" "$sqlstate" "$(cc_sqlstate_class "$sqlstate")"
}

assert_privilege_audit() {
    assert_scalar "writer database CONNECT" \
        "SELECT has_database_privilege('commitcap_writer', current_database(), 'CONNECT');" \
        "t"
    assert_scalar "writer database CREATE" \
        "SELECT has_database_privilege('commitcap_writer', current_database(), 'CREATE');" \
        "f"
    assert_scalar "writer database TEMPORARY" \
        "SELECT has_database_privilege('commitcap_writer', current_database(), 'TEMPORARY');" \
        "f"
    assert_scalar "writer direct memberships" \
        "SELECT count(*) FROM pg_auth_members AS m JOIN pg_roles AS r ON r.oid = m.member WHERE r.rolname = 'commitcap_writer';" \
        "0"
    assert_scalar "writer reachable roles" \
        "SELECT count(*) FROM pg_roles WHERE rolname <> 'commitcap_writer' AND pg_has_role('commitcap_writer', oid, 'USAGE');" \
        "0"
    assert_scalar "extension owner" \
        "SELECT pg_get_userbyid(extowner) FROM pg_extension WHERE extname = 'commitcap_native_tx_state';" \
        "commitcap_native_admin"
    # #47 adds exactly one trusted product trigger function; assert identities,
    # not just a lower total that could hide an unexpected extension function.
    assert_scalar "extension-owned functions" \
        "SELECT string_agg(p.proname, ',' ORDER BY p.proname) FROM pg_depend AS d JOIN pg_extension AS e ON e.oid = d.refobjid JOIN pg_proc AS p ON p.oid = d.objid WHERE e.extname = 'commitcap_native_tx_state' AND d.classid = 'pg_proc'::regclass AND d.deptype = 'e';" \
        "enforce_refund_delta,enforce_role_transition,enforce_rows_updated,enforce_update_budget"
    assert_scalar "trusted schema owners" \
        "SELECT count(*) FROM pg_namespace WHERE nspname IN ('commitcap_native', 'commitcap_probe') AND pg_get_userbyid(nspowner) <> 'commitcap_owner';" \
        "0"
    assert_scalar "trusted relational state" \
        "SELECT count(*) FROM pg_class AS c JOIN pg_namespace AS n ON n.oid = c.relnamespace WHERE n.nspname IN ('commitcap_native', 'commitcap_probe') AND c.relkind IN ('r', 'p', 'S');" \
        "0"
    assert_scalar "trusted SECURITY DEFINER functions" \
        "SELECT count(*) FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace WHERE n.nspname IN ('commitcap_native', 'commitcap_probe') AND p.prosecdef;" \
        "0"
    assert_scalar "enforcement trigger enabled" \
        "SELECT tgenabled::text FROM pg_trigger WHERE tgname = 'subscriptions_update_budget';" \
        "O"
    assert_scalar "users transition trigger enabled" \
        "SELECT tgenabled::text FROM pg_trigger WHERE tgname = 'users_role_transition';" \
        "O"
    assert_scalar "test budget GUC context" \
        "SELECT context FROM pg_settings WHERE name = 'commitcap_native.test_budget';" \
        "superuser"
    assert_scalar "test seed GUC context" \
        "SELECT context FROM pg_settings WHERE name = 'commitcap_native.test_seed_consumed';" \
        "superuser"

    printf 'privilege-audit checks: PASS\n'
}

# --- Two-session concurrency harness -----------------------------------------
# Uses host named pipes and background psql processes. The sessions are
# synchronized by output markers and pg_stat_activity, not by sleeps that
# assume database state. An admin connection observes lock waits.

cc_session_start() {
    local name="$1"

    mkfifo "$CC_SESSION_DIR/$name.in"
    : > "$CC_SESSION_DIR/$name.out"
    "${COMPOSE[@]}" exec -T \
        -e "PGPASSWORD=$WRITER_PASSWORD" \
        postgres psql -X -A -t -h 127.0.0.1 -U commitcap_writer -d commitcap_native -v ON_ERROR_STOP=0 \
        < "$CC_SESSION_DIR/$name.in" > "$CC_SESSION_DIR/$name.out" 2>&1 &
}

cc_session_attach() {
    case "$1" in
        a) exec 8>"$CC_SESSION_DIR/a.in" ;;
        b) exec 9>"$CC_SESSION_DIR/b.in" ;;
        *) fail "unknown session $1" ;;
    esac
}

cc_session_stop() {
    case "$1" in
        a) printf '\\q\n' >&8 2>/dev/null || true; exec 8>&- ;;
        b) printf '\\q\n' >&9 2>/dev/null || true; exec 9>&- ;;
        *) fail "unknown session $1" ;;
    esac
}

cc_send() {
    local name="$1"
    local sql="$2"

    case "$name" in
        a) printf '%s\n' "$sql" >&8 ;;
        b) printf '%s\n' "$sql" >&9 ;;
        *) fail "unknown session $name" ;;
    esac
}

cc_wait_output() {
    local name="$1"
    local pattern="$2"
    local label="$3"
    local waited=0

    while ! grep -qF -- "$pattern" "$CC_SESSION_DIR/$name.out" 2>/dev/null; do
        if (( waited >= 300 )); then
            printf 'session %s output:\n' "$name"
            cat "$CC_SESSION_DIR/$name.out" || true
            fail "timed out waiting for $label"
        fi
        sleep 0.1
        waited=$((waited + 1))
    done
}

cc_sync() {
    local name="$1"
    local marker="$2"

    cc_send "$name" "SELECT '$marker';"
    cc_wait_output "$name" "$marker" "sync marker $marker"
}

cc_probe_line() {
    local name="$1"
    local marker="$2"

    cc_send "$name" "SELECT '$marker:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END || '|' || backend_pid FROM commitcap_probe.cc_native_probe();"
    cc_wait_output "$name" "$marker:" "probe $marker"
    grep -F "$marker:" "$CC_SESSION_DIR/$name.out" | tail -n 1
}

cc_probe_field() {
    local name="$1"
    local marker="$2"
    local field="$3"
    local line
    local value

    line="$(cc_probe_line "$name" "$marker")"
    value="${line##*"$marker:"}"
    case "$field" in
        active)   printf '%s' "${value%%|*}" ;;
        consumed) printf '%s' "$(printf '%s' "$value" | cut -d'|' -f2)" ;;
        denied)   printf '%s' "$(printf '%s' "$value" | cut -d'|' -f3)" ;;
        pid)      printf '%s' "$(printf '%s' "$value" | cut -d'|' -f4)" ;;
        *) fail "unknown probe field $field" ;;
    esac
}

cc_probe_assert() {
    local name="$1"
    local marker="$2"
    local expected_active="$3"
    local expected_consumed="$4"
    local expected_denied="$5"
    local expected_pid="${6:-}"
    local line
    local value
    local active
    local consumed
    local denied
    local pid

    line="$(cc_probe_line "$name" "$marker")"
    value="${line##*"$marker:"}"
    IFS='|' read -r active consumed denied pid <<<"$value"
    [[ "$active" == "$expected_active" && "$consumed" == "$expected_consumed" && "$denied" == "$expected_denied" ]] || \
        fail "$marker was active=$active consumed=$consumed denied=$denied pid=$pid, expected $expected_active/$expected_consumed/$expected_denied"
    if [[ -n "$expected_pid" ]]; then
        [[ "$pid" == "$expected_pid" ]] || \
            fail "$marker used backend pid $pid, expected reused backend $expected_pid"
    fi
    printf '  %s: active=%s consumed=%s denied=%s backend_pid=%s\n' \
        "$marker" "$active" "$consumed" "$denied" "$pid"
}

cc_event_count() {
    local pid="$1"

    "${COMPOSE[@]}" logs --no-color postgres 2>&1 | \
        grep -c "commitcap_native_tx_state event pid=$pid " || true
}

cc_lifecycle_count() {
    local pid="$1"

    "${COMPOSE[@]}" logs --no-color postgres 2>&1 | \
        grep -c "commitcap_native_tx_state lifecycle event=.* pid=$pid " || true
}

cc_lifecycle_since() {
    local pid="$1"
    local before="$2"
    local after
    local new_count

    after="$(cc_lifecycle_count "$pid")"
    new_count=$((after - before))
    [[ "$new_count" -gt 0 ]] || fail "no lifecycle callbacks recorded for pid $pid"
    "${COMPOSE[@]}" logs --no-color postgres 2>&1 | \
        grep "commitcap_native_tx_state lifecycle event=.* pid=$pid " | tail -n "$new_count"
}

cc_count_tag() {
    local name="$1"
    local tag="$2"

    grep -cxF -- "$tag" "$CC_SESSION_DIR/$name.out" || true
}

cc_wait_blocked() {
    local blocked_pid="$1"
    local blocker_pid="$2"
    local label="$3"
    local waited=0
    local line

    while true; do
        line="$(admin_psql -At -v ON_ERROR_STOP=1 -c \
            "SELECT COALESCE(wait_event_type, '') || ':' || COALESCE(wait_event, '') || ':' || COALESCE(array_to_string(pg_blocking_pids(pid), ','), '') FROM pg_stat_activity WHERE pid = $blocked_pid;")"
        if [[ "$line" == Lock:* ]]; then
            local blockers="${line##*:}"
            if [[ ",$blockers," == *",$blocker_pid,"* ]]; then
                printf '  %s: pid %s wait=%s blocked_by=%s\n' \
                    "$label" "$blocked_pid" "${line#Lock:}" "$blockers"
                return 0
            fi
        fi
        if (( waited >= 300 )); then
            fail "$label: timed out waiting for pid $blocked_pid to block on pid $blocker_pid (last: $line)"
        fi
        sleep 0.1
        waited=$((waited + 1))
    done
}

cc_lifecycle_check() {
    local label="$1"

    cc_send a "BEGIN;"
    cc_send a "UPDATE public.subscriptions SET status = '${label}_life_a' WHERE id BETWEEN 1 AND 5;"
    cc_send a "COMMIT;"
    cc_sync a "CCLIFE_A_${label}"
    cc_send b "BEGIN;"
    cc_send b "UPDATE public.subscriptions SET status = '${label}_life_b' WHERE id BETWEEN 6 AND 10;"
    cc_send b "COMMIT;"
    cc_sync b "CCLIFE_B_${label}"
    assert_scalar "CC life $label A rows" \
        "SELECT count(*) FILTER (WHERE id BETWEEN 1 AND 5 AND status = '${label}_life_a') || ':' || count(*) FILTER (WHERE status NOT IN ('${label}_life_a', '${label}_life_b')) FROM public.subscriptions;" \
        "5:0"
    assert_scalar "CC life $label B rows" \
        "SELECT count(*) FILTER (WHERE id BETWEEN 6 AND 10 AND status = '${label}_life_b') FROM public.subscriptions;" \
        "5"
    printf '  lifecycle %s: PASS\n' "$label"
}

# Verifies that a fresh top-level transaction starts at consumed=0, denied=false
# and can still commit five protected row-update events.
cc_lifecycle_probe() {
    local label="$1"
    local status="$2"
    local output

    set +e
    output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<SQL
BEGIN;
SELECT '${label}_START:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
UPDATE public.subscriptions SET status = '$status' WHERE id BETWEEN 1 AND 5;
SELECT '${label}_END:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
COMMIT;
SQL
)"
    set -e
    [[ "$output" == *"${label}_START:f|0|f"* ]] || \
        fail "$label lifecycle start was not consumed=0 denied=false (output: $output)"
    [[ "$output" == *"${label}_END:t|5|f"* ]] || \
        fail "$label lifecycle transaction did not consume five events (output: $output)"
    assert_five_updated "$label lifecycle durable" "$status"
    printf '  %s lifecycle: PASS (fresh start; five events committed)\n' "$label"
}

cleanup() {
    if [[ -n "${CC_SESSION_DIR:-}" && -d "$CC_SESSION_DIR" ]]; then
        rm -rf "$CC_SESSION_DIR"
    fi
    "${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
}

trap 'status=$?; cleanup; printf "Native suite exit status: %s\n" "$status"' EXIT
cleanup
"${COMPOSE[@]}" up -d --build --wait
admin_psql -v ON_ERROR_STOP=1 < "$EXPERIMENT_DIR/setup.sql" >/dev/null

printf 'Classification: TEST FIRST\n'
printf 'Command: ./experiments/native_tx_state/run.sh\n'
printf 'Tested commit: %s\n' "$(git -C "$EXPERIMENT_DIR/../.." rev-parse HEAD)"
printf 'Base image: %s\n' "$(docker image inspect postgres:16.4-alpine --format '{{index .RepoDigests 0}}')"
printf 'PostgreSQL: %s\n' "$(admin_psql -At -v ON_ERROR_STOP=1 -c 'SHOW server_version;')"
printf 'Outside this suite: capability-wide CC-034/035, managed deployment, ungranted INSERT/DELETE/COPY/TRUNCATE accounting, other PostgreSQL majors and poolers.\n'
assert_privilege_envelope
assert_privilege_audit

printf '\n--- CC-027 / CC-028 / CC-029: privilege boundary ---\n'
reset_fixture

printf '\nCC-027 privilege bypass attempts:\n'
cc_denied_attempt alter_disable_trigger_all 42501 "ALTER TABLE public.subscriptions DISABLE TRIGGER ALL"
cc_denied_attempt alter_disable_trigger_user 42501 "ALTER TABLE public.subscriptions DISABLE TRIGGER USER"
cc_denied_attempt alter_enable_replica_trigger 42501 "ALTER TABLE public.subscriptions ENABLE REPLICA TRIGGER subscriptions_update_budget"
cc_denied_attempt drop_trigger 42501 "DROP TRIGGER subscriptions_update_budget ON public.subscriptions"
cc_denied_attempt alter_table_owner 42501 "ALTER TABLE public.subscriptions OWNER TO commitcap_writer"
cc_denied_attempt drop_table 42501 "DROP TABLE public.subscriptions"
cc_denied_attempt alter_drop_column 42501 "ALTER TABLE public.subscriptions DROP COLUMN status"
cc_denied_attempt truncate_table 42501 "TRUNCATE public.subscriptions"
cc_denied_attempt insert_row 42501 "INSERT INTO public.subscriptions (id, status) VALUES (99, 'x')"
cc_denied_attempt drop_function 42501 "DROP FUNCTION commitcap_native.enforce_update_budget()"
cc_denied_attempt alter_function 42501 "ALTER FUNCTION commitcap_native.enforce_update_budget() SET search_path = public"
cc_denied_attempt replace_function 42501 "CREATE OR REPLACE FUNCTION commitcap_native.enforce_update_budget() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RETURN NEW; END'"
cc_denied_attempt create_in_trusted_schema 42501 "CREATE TABLE commitcap_native.steal (x int)"
cc_denied_attempt drop_trusted_schema 42501 "DROP SCHEMA commitcap_native"
cc_denied_attempt alter_schema_owner 42501 "ALTER SCHEMA commitcap_native OWNER TO commitcap_writer"
cc_denied_attempt drop_probe_schema 42501 "DROP SCHEMA commitcap_probe"
cc_denied_attempt alter_probe_function 42501 "ALTER FUNCTION commitcap_probe.cc_native_probe() SET search_path = public"
cc_denied_attempt create_shadow_schema 42501 "CREATE SCHEMA commitcap_writer_shadow"
cc_denied_attempt create_shadow_function 42501 "CREATE FUNCTION public.enforce_update_budget() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RETURN NEW; END'"
cc_denied_attempt create_shadow_table 42501 "CREATE TABLE public.subscriptions_shadow (id int)"
cc_denied_attempt set_replication_role 42501 "SET session_replication_role = replica"
cc_denied_attempt alter_extension_update 42501 "ALTER EXTENSION commitcap_native_tx_state UPDATE"
cc_denied_attempt alter_extension_set_schema 42501 "ALTER EXTENSION commitcap_native_tx_state SET SCHEMA commitcap_probe"
cc_denied_attempt drop_extension 42501 "DROP EXTENSION commitcap_native_tx_state"
cc_denied_attempt create_extension_pgcrypto 42501 "CREATE EXTENSION pgcrypto"
cc_denied_attempt create_extension_existing 42710 "CREATE EXTENSION commitcap_native_tx_state WITH SCHEMA public"
cc_denied_attempt insert_accounting_state 42501 "INSERT INTO commitcap_native.update_budget_state (transaction_id, row_updates) VALUES (1, 1)"
cc_denied_attempt drop_users_trigger 42501 "DROP TRIGGER users_role_transition ON public.users"
cc_denied_attempt alter_users_disable_trigger 42501 "ALTER TABLE public.users DISABLE TRIGGER ALL"
cc_denied_attempt drop_transition_function 42501 "DROP FUNCTION commitcap_native.enforce_role_transition()"
cc_denied_attempt replace_transition_function 42501 "CREATE OR REPLACE FUNCTION commitcap_native.enforce_role_transition() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RETURN NEW; END'"

assert_scalar "CC-027 protected relation owner after attempts" \
    "SELECT pg_get_userbyid(c.relowner) FROM pg_class AS c JOIN pg_namespace AS n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relname = 'subscriptions';" \
    "commitcap_owner"
assert_scalar "CC-027 trigger state after attempts" \
    "SELECT tgenabled::text FROM pg_trigger WHERE tgname = 'subscriptions_update_budget';" \
    "O"
assert_scalar "CC-027 users trigger state after attempts" \
    "SELECT tgenabled::text FROM pg_trigger WHERE tgname = 'users_role_transition';" \
    "O"
assert_scalar "CC-027 enforcement function after attempts" \
    "SELECT pg_get_userbyid(p.proowner) || ':' || l.lanname FROM pg_proc AS p JOIN pg_language AS l ON l.oid = p.prolang JOIN pg_namespace AS n ON n.oid = p.pronamespace WHERE n.nspname = 'commitcap_native' AND p.proname = 'enforce_update_budget';" \
    "commitcap_owner:c"
assert_scalar "CC-027 transition function after attempts" \
    "SELECT pg_get_userbyid(p.proowner) || ':' || l.lanname FROM pg_proc AS p JOIN pg_language AS l ON l.oid = p.prolang JOIN pg_namespace AS n ON n.oid = p.pronamespace WHERE n.nspname = 'commitcap_native' AND p.proname = 'enforce_role_transition';" \
    "commitcap_owner:c"
assert_scalar "CC-027 extension after attempts" \
    "SELECT count(*) FROM pg_extension WHERE extname = 'commitcap_native_tx_state';" \
    "1"
assert_baseline
set +e
cc027_regression_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc027_regression' WHERE id BETWEEN 1 AND 6;
COMMIT;
SQL
)"
set -e
[[ "$cc027_regression_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-027 post-attack enforcement regression did not deny event six"
assert_baseline
set +e
cc027_transition_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.users SET role = 'admin' WHERE id = 1;
COMMIT;
SQL
)"
set -e
[[ "$cc027_transition_output" == *"CommitCap forbidden state transition (* -> admin)"* ]] || \
    fail "CC-027 post-attack transition enforcement did not deny the admin transition"
assert_users_baseline
printf 'CC-027: PASS (all attempts denied; trusted objects unchanged; enforcement intact)\n'

printf '\nCC-028 role transition attempts:\n'
cc028_roles="$(admin_psql -At -v ON_ERROR_STOP=1 -c \
    "SELECT rolname FROM pg_roles WHERE rolname <> 'commitcap_writer' ORDER BY rolname;")"
cc028_role_count=0
while IFS= read -r cc028_role; do
    [[ -n "$cc028_role" ]] || continue
    cc_denied_attempt "set_role_$cc028_role" 42501 "SET ROLE $cc028_role"
    cc028_role_count=$((cc028_role_count + 1))
done <<<"$cc028_roles"
cc_denied_attempt set_session_authorization 42501 "SET SESSION AUTHORIZATION commitcap_owner"
set +e
cc028_identity="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
SET ROLE NONE;
SELECT 'CC028_IDENTITY:' || session_user || ':' || current_user;
SQL
)"
set -e
[[ "$cc028_identity" == *"CC028_IDENTITY:commitcap_writer:commitcap_writer"* ]] || \
    fail "CC-028 writer identity changed unexpectedly: $cc028_identity"
printf '  %-44s %s\n' "set_role_none" "identity unchanged (commitcap_writer:commitcap_writer)"
printf 'CC-028: PASS (no reachable role; %s other roles attempted)\n' "$cc028_role_count"

printf '\nCC-029 two-phase commit exclusion:\n'
assert_scalar "max_prepared_transactions" "SHOW max_prepared_transactions;" "0"
set +e
cc029_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc029' WHERE id = 1;
PREPARE TRANSACTION 'cc029_test';
\echo CC029_SQLSTATE :SQLSTATE
ROLLBACK;
SQL
)"
set -e
cc029_sqlstate="$(printf '%s\n' "$cc029_output" | grep -F 'CC029_SQLSTATE ' | head -n 1 | awk '{print $2}')"
[[ "$cc029_sqlstate" == "55000" ]] || \
    fail "CC-029 PREPARE TRANSACTION returned $cc029_sqlstate ($(cc_sqlstate_class "$cc029_sqlstate")), expected 55000"
cc029_message="$(printf '%s\n' "$cc029_output" | grep -F 'prepared transactions are disabled' | head -n 1 | sed 's/^ERROR:  //')"
printf '  %-44s SQLSTATE %s %s\n' "PREPARE_TRANSACTION" "$cc029_sqlstate" "$(cc_sqlstate_class "$cc029_sqlstate")"
printf '  %-44s %s\n' "server message" "$cc029_message"
assert_scalar "CC-029 prepared transactions" \
    "SELECT count(*) FROM pg_prepared_xacts;" "0"
assert_scalar "CC-029 prepared transaction by gid" \
    "SELECT count(*) FROM pg_prepared_xacts WHERE gid = 'cc029_test';" "0"
assert_baseline
printf 'CC-029: PASS (two-phase commit unavailable; no prepared transaction; no durable mutation)\n'

printf '\nCC-026 MERGE WHEN MATCHED UPDATE accounting:\n'

reset_fixture
cc026_explain_output="$(writer_psql -v ON_ERROR_STOP=1 2>&1 <<'SQL'
BEGIN;
EXPLAIN (ANALYZE, COSTS OFF)
MERGE INTO public.subscriptions AS target
USING (VALUES (1, 'cc026_explain'), (2, 'cc026_explain'), (3, 'cc026_explain'), (4, 'cc026_explain'), (5, 'cc026_explain')) AS source(id, status)
ON target.id = source.id
WHEN MATCHED THEN UPDATE SET status = source.status;
ROLLBACK;
SQL
)"
[[ "$cc026_explain_output" == *"subscriptions_update_budget"*"calls=5"* ]] || \
    fail "CC-026 row-event instrumentation did not report five enforcement-trigger calls"
printf '  row-event instrumentation: 5 MERGE update actions invoked the enforcement trigger 5 times\n'
assert_baseline

reset_fixture
set +e
cc026_five_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
MERGE INTO public.subscriptions AS target
USING (VALUES (1, 'cc026_allowed'), (2, 'cc026_allowed'), (3, 'cc026_allowed'), (4, 'cc026_allowed'), (5, 'cc026_allowed')) AS source(id, status)
ON target.id = source.id
WHEN MATCHED THEN UPDATE SET status = source.status;
SELECT 'CC026_STATE:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
COMMIT;
SQL
)"
set -e
[[ "$cc026_five_output" == *"MERGE 5"* ]] || \
    fail "CC-026 five-event MERGE did not report five updated rows"
[[ "$cc026_five_output" == *"CC026_STATE:t|5|f"* ]] || \
    fail "CC-026 five-event MERGE state was not consumed=5 denied=false (output: $cc026_five_output)"
assert_scalar "CC-026 five-event durable" \
    "SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions;" \
    "1=cc026_allowed,2=cc026_allowed,3=cc026_allowed,4=cc026_allowed,5=cc026_allowed,6=baseline,7=baseline,8=baseline,9=baseline,10=baseline"
printf 'CC-026 five-event MERGE: PASS\n'

reset_fixture
set +e
cc026_six_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
MERGE INTO public.subscriptions AS target
USING (VALUES (1, 'cc026_denied'), (2, 'cc026_denied'), (3, 'cc026_denied'), (4, 'cc026_denied'), (5, 'cc026_denied'), (6, 'cc026_denied')) AS source(id, status)
ON target.id = source.id
WHEN MATCHED THEN UPDATE SET status = source.status;
COMMIT;
SQL
)"
set -e
[[ "$cc026_six_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-026 six-event MERGE did not report event-six denial"
[[ "$cc026_six_output" == *"ROLLBACK"* ]] || \
    fail "CC-026 six-event MERGE did not abort the transaction"
assert_baseline
printf 'CC-026 six-event MERGE: PASS\n'

reset_fixture
set +e
cc026_mixed_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc026_mixed' WHERE id BETWEEN 1 AND 3;
MERGE INTO public.subscriptions AS target
USING (VALUES (4, 'cc026_mixed'), (5, 'cc026_mixed')) AS source(id, status)
ON target.id = source.id
WHEN MATCHED THEN UPDATE SET status = source.status;
SELECT 'CC026_STATE:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
MERGE INTO public.subscriptions AS target
USING (VALUES (6, 'cc026_mixed')) AS source(id, status)
ON target.id = source.id
WHEN MATCHED THEN UPDATE SET status = source.status;
COMMIT;
SQL
)"
set -e
[[ "$cc026_mixed_output" == *"CC026_STATE:t|5|f"* ]] || \
    fail "CC-026 mixed UPDATE+MERGE did not share the budget (output: $cc026_mixed_output)"
[[ "$cc026_mixed_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-026 mixed UPDATE+MERGE did not deny the sixth event"
[[ "$cc026_mixed_output" == *"ROLLBACK"* ]] || \
    fail "CC-026 mixed UPDATE+MERGE did not abort the transaction"
assert_baseline
printf 'CC-026 mixed UPDATE + MERGE: PASS\n'

reset_fixture
set +e
cc026_multi_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
MERGE INTO public.subscriptions AS target
USING (VALUES (1, 'cc026_multi'), (2, 'cc026_multi'), (3, 'cc026_multi')) AS source(id, status)
ON target.id = source.id
WHEN MATCHED THEN UPDATE SET status = source.status;
MERGE INTO public.subscriptions AS target
USING (VALUES (4, 'cc026_multi'), (5, 'cc026_multi')) AS source(id, status)
ON target.id = source.id
WHEN MATCHED THEN UPDATE SET status = source.status;
SELECT 'CC026_STATE:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
MERGE INTO public.subscriptions AS target
USING (VALUES (6, 'cc026_multi')) AS source(id, status)
ON target.id = source.id
WHEN MATCHED THEN UPDATE SET status = source.status;
COMMIT;
SQL
)"
set -e
[[ "$cc026_multi_output" == *"CC026_STATE:t|5|f"* ]] || \
    fail "CC-026 multiple MERGE statements did not share the budget (output: $cc026_multi_output)"
[[ "$cc026_multi_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-026 multiple MERGE statements did not deny the sixth event"
[[ "$cc026_multi_output" == *"ROLLBACK"* ]] || \
    fail "CC-026 multiple MERGE statements did not abort the transaction"
assert_baseline
printf 'CC-026 multiple MERGE statements: PASS\n'
printf 'CC-026: PASS (MERGE WHEN MATCHED UPDATE shares transaction-wide accounting)\n'

printf '\nCC-004 / CC-005 / CC-006 / CC-007: row-update event definition:\n'

reset_fixture
set +e
cc004_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc004' WHERE id BETWEEN 1 AND 5;
UPDATE public.subscriptions SET status = 'cc004_excess' WHERE id = 6;
COMMIT;
SQL
)"
set -e
[[ "$cc004_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-004 did not report the event-six denial"
[[ "$cc004_output" == *"ROLLBACK"* ]] || \
    fail "CC-004 denied transaction did not abort"
assert_scalar "CC-004 durable denied state" \
    "SELECT count(*) FILTER (WHERE status = 'baseline') || ':' || count(*) FILTER (WHERE status = 'cc004') || ':' || count(*) FILTER (WHERE status = 'cc004_excess') FROM public.subscriptions;" \
    "10:0:0"
cc_lifecycle_probe CC004 cc004_life
printf 'CC-004: PASS (fresh admin observed no durable mutation from the denied transaction)\n'

reset_fixture
set +e
cc005_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc005_zero' WHERE id = 999999;
SELECT 'CC005_ZERO:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
UPDATE public.subscriptions SET status = 'cc005_allowed' WHERE id BETWEEN 1 AND 5;
SELECT 'CC005_FIVE:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
COMMIT;
SQL
)"
set -e
[[ "$cc005_output" == *"UPDATE 0"* ]] || \
    fail "CC-005 zero-row UPDATE did not report UPDATE 0"
[[ "$cc005_output" == *"CC005_ZERO:f|0|f"* ]] || \
    fail "CC-005 zero-row UPDATE consumed authority (output: $cc005_output)"
[[ "$cc005_output" == *"CC005_FIVE:t|5|f"* ]] || \
    fail "CC-005 five-event update state was not consumed=5 denied=false (output: $cc005_output)"
assert_scalar "CC-005 durable state" \
    "SELECT count(*) FILTER (WHERE status = 'cc005_allowed') || ':' || count(*) FILTER (WHERE status = 'baseline') || ':' || count(*) FILTER (WHERE status = 'cc005_zero') FROM public.subscriptions;" \
    "5:5:0"
printf 'CC-005: PASS (zero-row UPDATE produced zero events; five events then committed)\n'

reset_fixture
set +e
cc006_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc006_1' WHERE id = 1;
UPDATE public.subscriptions SET status = 'cc006_2' WHERE id = 1;
UPDATE public.subscriptions SET status = 'cc006_3' WHERE id = 1;
UPDATE public.subscriptions SET status = 'cc006_4' WHERE id = 1;
UPDATE public.subscriptions SET status = 'cc006_5' WHERE id = 1;
SELECT 'CC006_FIVE:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
UPDATE public.subscriptions SET status = 'cc006_6' WHERE id = 1;
COMMIT;
SQL
)"
set -e
cc006_update_count="$(printf '%s\n' "$cc006_output" | grep -c '^UPDATE 1$' || true)"
[[ "$cc006_update_count" == "5" ]] || \
    fail "CC-006 completed $cc006_update_count same-row updates before denial, expected 5"
[[ "$cc006_output" == *"CC006_FIVE:t|5|f"* ]] || \
    fail "CC-006 five same-row events did not consume five units (output: $cc006_output)"
[[ "$cc006_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-006 sixth same-row event was not denied"
[[ "$cc006_output" == *"ROLLBACK"* ]] || \
    fail "CC-006 denied transaction did not abort"
assert_baseline
cc_lifecycle_probe CC006 cc006_life
printf 'CC-006: PASS (same-row events counted individually; sixth denied)\n'

reset_fixture
set +e
cc007_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
EXPLAIN (ANALYZE, COSTS OFF) UPDATE public.subscriptions SET status = 'baseline' WHERE id BETWEEN 1 AND 5;
SELECT 'CC007_FIVE:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
UPDATE public.subscriptions SET status = 'baseline' WHERE id = 6;
COMMIT;
SQL
)"
set -e
[[ "$cc007_output" == *"subscriptions_update_budget"*"calls=5"* ]] || \
    fail "CC-007 no-op instrumentation did not report five enforcement-trigger calls"
[[ "$cc007_output" == *"CC007_FIVE:t|5|f"* ]] || \
    fail "CC-007 five no-op events did not consume five units (output: $cc007_output)"
[[ "$cc007_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-007 sixth no-op event was not denied"
[[ "$cc007_output" == *"ROLLBACK"* ]] || \
    fail "CC-007 denied transaction did not abort"
assert_baseline
cc_lifecycle_probe CC007 cc007_life
printf 'CC-007: PASS (no-op assignments consumed authority; sixth denied)\n'

printf '\nCC-030 / CC-031: budget bounds and overflow safety:\n'
cc_config_reset commitcap_native.test_budget
cc_config_reset commitcap_native.test_seed_consumed
assert_value "test configuration clean start" "$(cc_config_stored)" "(none)"

printf '\nCC-030 zero budget:\n'
cc_config_set commitcap_native.test_budget 0
assert_value "CC-030 stored budget" "$(cc_config_stored)" "commitcap_native.test_budget=0"
reset_fixture
set +e
cc030_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
SELECT 'CC030_BUDGET:' || current_setting('commitcap_native.test_budget');
SELECT 'CC030_START:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
UPDATE public.subscriptions SET status = 'cc030' WHERE id = 1;
COMMIT;
SQL
)"
set -e
[[ "$cc030_output" == *"CC030_BUDGET:0"* ]] || \
    fail "CC-030 writer session did not see budget 0 (output: $cc030_output)"
[[ "$cc030_output" == *"CC030_START:f|0|f"* ]] || \
    fail "CC-030 transaction did not start at consumed=0 denied=false"
[[ "$cc030_output" == *"CommitCap mutation budget exceeded (limit 0, attempted 1)"* ]] || \
    fail "CC-030 first event was not denied under budget 0"
[[ "$cc030_output" == *"ROLLBACK"* ]] || \
    fail "CC-030 denied transaction did not abort"
printf '  zero-budget: %s; %s\n' \
    "$(printf '%s\n' "$cc030_output" | grep -F 'CC030_BUDGET:' | head -n 1)" \
    "$(printf '%s\n' "$cc030_output" | grep -F 'CommitCap mutation budget exceeded' | head -n 1 | sed 's/^ERROR:  //')"
assert_baseline
cc_config_reset commitcap_native.test_budget
assert_value "CC-030 restored configuration" "$(cc_config_stored)" "(none)"
cc_lifecycle_probe CC030 cc030_life
reset_fixture
set +e
cc030_restored_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc030_restored' WHERE id BETWEEN 1 AND 6;
COMMIT;
SQL
)"
set -e
[[ "$cc030_restored_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-030 restored budget did not deny the sixth event"
assert_baseline
printf 'CC-030: PASS (budget 0 denies the first event; default budget restored)\n'

printf '\nCC-031 budget validation and overflow safety:\n'
cc_config_set commitcap_native.test_budget 5
assert_value "CC-031 stored valid budget" "$(cc_config_stored)" "commitcap_native.test_budget=5"
cc_config_attempt negative "ALTER ROLE commitcap_writer SET commitcap_native.test_budget = -1" 22023
cc_config_attempt max_plus_one "ALTER ROLE commitcap_writer SET commitcap_native.test_budget = 2147483648" 22023
cc_config_attempt huge "ALTER ROLE commitcap_writer SET commitcap_native.test_budget = 999999999999999999999999" 22023
cc_config_attempt non_numeric "ALTER ROLE commitcap_writer SET commitcap_native.test_budget = 'not-a-number'" 22023
cc_config_attempt out_of_range_fraction "ALTER ROLE commitcap_writer SET commitcap_native.test_budget = '2147483647.9'" 22023
assert_value "CC-031 configuration after invalid attempts" "$(cc_config_stored)" "commitcap_native.test_budget=5"
reset_fixture
cc_lifecycle_probe CC031 cc031_life
reset_fixture
set +e
cc031_unchanged_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc031_unchanged' WHERE id BETWEEN 1 AND 6;
COMMIT;
SQL
)"
set -e
[[ "$cc031_unchanged_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-031 prior valid budget was not preserved after rejected inputs"
assert_baseline
printf '  prior valid budget preserved and still enforced\n'
cc_config_attempt in_range_fraction "ALTER ROLE commitcap_writer SET commitcap_native.test_budget = '1.5'" 00000
assert_value "CC-031 fractional stored form" "$(cc_config_stored)" "commitcap_native.test_budget=1.5"
assert_value "CC-031 fractional activated value" \
    "$(writer_psql -At -v ON_ERROR_STOP=1 -c "SHOW commitcap_native.test_budget;")" "2"
cc_config_reset commitcap_native.test_budget
cc_config_attempt negative_fraction "ALTER ROLE commitcap_writer SET commitcap_native.test_budget = '-0.5'" 00000
assert_value "CC-031 negative fractional stored form" "$(cc_config_stored)" "commitcap_native.test_budget=-0.5"
assert_value "CC-031 negative fractional activated value" \
    "$(writer_psql -At -v ON_ERROR_STOP=1 -c "SHOW commitcap_native.test_budget;")" "0"
printf '  PostgreSQL integer GUC grammar rounds in-range fractions; activated values stay in [0, 2147483647]\n'
cc_config_reset commitcap_native.test_budget
cc_config_set commitcap_native.test_budget 2147483647
assert_value "CC-031 stored maximum budget" "$(cc_config_stored)" "commitcap_native.test_budget=2147483647"
cc_config_set commitcap_native.test_seed_consumed 2147483646
assert_value "CC-031 stored near-maximum seed" "$(cc_config_stored)" \
    "commitcap_native.test_budget=2147483647,commitcap_native.test_seed_consumed=2147483646"
reset_fixture
set +e
cc031_max_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
SELECT 'CC031_BUDGET:' || current_setting('commitcap_native.test_budget') || ':' || current_setting('commitcap_native.test_seed_consumed');
UPDATE public.subscriptions SET status = 'cc031_max' WHERE id = 1;
SELECT 'CC031_AFTER1:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
UPDATE public.subscriptions SET status = 'cc031_max' WHERE id = 2;
COMMIT;
SQL
)"
set -e
[[ "$cc031_max_output" == *"CC031_BUDGET:2147483647:2147483646"* ]] || \
    fail "CC-031 near-maximum configuration was not active in the writer session"
[[ "$cc031_max_output" == *"CC031_AFTER1:t|2147483647|f"* ]] || \
    fail "CC-031 first near-maximum event did not consume exactly to the maximum"
[[ "$cc031_max_output" == *"CommitCap mutation budget exceeded (limit 2147483647, attempted 2147483648)"* ]] || \
    fail "CC-031 second near-maximum event was not denied without wrap"
[[ "$cc031_max_output" == *"ROLLBACK"* ]] || \
    fail "CC-031 near-maximum denied transaction did not abort"
printf '  near-maximum: %s; %s\n' \
    "$(printf '%s\n' "$cc031_max_output" | grep -F 'CC031_BUDGET:' | head -n 1)" \
    "$(printf '%s\n' "$cc031_max_output" | grep -F 'CC031_AFTER1:' | head -n 1)"
printf '  near-maximum denial: %s\n' \
    "$(printf '%s\n' "$cc031_max_output" | grep -F 'CommitCap mutation budget exceeded' | head -n 1 | sed 's/^ERROR:  //')"
assert_baseline
set +e
cc031_commit_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc031_commit' WHERE id = 1;
SELECT 'CC031_COMMIT:t|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
COMMIT;
SQL
)"
set -e
[[ "$cc031_commit_output" == *"CC031_COMMIT:t|2147483647|f"* ]] || \
    fail "CC-031 near-maximum commit transaction did not reach the maximum"
printf '  near-maximum commit: %s\n' \
    "$(printf '%s\n' "$cc031_commit_output" | grep -F 'CC031_COMMIT:' | head -n 1)"
assert_scalar "CC-031 near-maximum commit durable" \
    "SELECT count(*) FILTER (WHERE id = 1 AND status = 'cc031_commit') || ':' || count(*) FILTER (WHERE status <> 'baseline') FROM public.subscriptions;" \
    "1:1"
cc_config_reset commitcap_native.test_seed_consumed
cc_config_reset commitcap_native.test_budget
assert_value "CC-031 restored configuration" "$(cc_config_stored)" "(none)"
reset_fixture
cc_lifecycle_probe CC031R cc031_restored
cc_denied_attempt config_writer_set 42501 "SET commitcap_native.test_budget = 100"
cc_denied_attempt config_writer_reset 42501 "RESET commitcap_native.test_budget"
cc_denied_attempt config_writer_alter_role 42501 "ALTER ROLE commitcap_writer SET commitcap_native.test_budget = 100"
cc_denied_attempt config_writer_alter_database 42501 "ALTER DATABASE commitcap_native SET commitcap_native.test_budget = 100"
printf 'CC-031: PASS (bounds rejected before activation; near-maximum counter did not wrap)\n'
printf 'Regression suite follows; all of it runs after the privilege, MERGE, row-event, and budget experiments.\n'

reset_fixture
writer_psql -v ON_ERROR_STOP=1 -c \
    "BEGIN; UPDATE public.subscriptions SET status = 'cc001' WHERE id BETWEEN 1 AND 5; COMMIT;" \
    >/dev/null
assert_five_updated "CC-001" "cc001"
printf 'CC-001: PASS\n'

reset_fixture
set +e
cc002_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc002' WHERE id BETWEEN 1 AND 6;
COMMIT;
SQL
)"
set -e
[[ "$cc002_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-002 did not report event-six denial"
[[ "$cc002_output" == *"ROLLBACK"* ]] || fail "CC-002 did not abort"
assert_baseline
printf 'CC-002: PASS\n'

reset_fixture
set +e
cc003_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc003' WHERE id = 1;
UPDATE public.subscriptions SET status = 'cc003' WHERE id = 2;
UPDATE public.subscriptions SET status = 'cc003' WHERE id = 3;
UPDATE public.subscriptions SET status = 'cc003' WHERE id = 4;
UPDATE public.subscriptions SET status = 'cc003' WHERE id = 5;
UPDATE public.subscriptions SET status = 'cc003' WHERE id = 6;
COMMIT;
SQL
)"
set -e
[[ "$cc003_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-003 did not report cumulative event-six denial"
cc003_success_count="$(printf '%s\n' "$cc003_output" | grep -c '^UPDATE 1$' || true)"
[[ "$cc003_success_count" == "5" ]] || \
    fail "CC-003 completed $cc003_success_count updates before denial, expected 5"
assert_baseline
printf 'CC-003: PASS\n'

reset_fixture
set +e
cc008_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc008_allowed' WHERE id BETWEEN 1 AND 5;
SAVEPOINT before_denial;
UPDATE public.subscriptions SET status = 'cc008_excess' WHERE id = 6;
ROLLBACK TO SAVEPOINT before_denial;
SELECT 'cc008_after_recovery' AS recovery_marker;
COMMIT;
SQL
)"
set -e
[[ "$cc008_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-008 did not report event-six denial"
[[ "$cc008_output" == *"cc008_after_recovery"* ]] || \
    fail "CC-008 did not recover execution after ROLLBACK TO"
[[ "$cc008_output" == *"CommitCap top-level transaction denied after mutation authority violation"* ]] || \
    fail "CC-008 final COMMIT was not rejected"
cc008_commit_count="$(printf '%s\n' "$cc008_output" | grep -c '^COMMIT$' || true)"
[[ "$cc008_commit_count" == "0" ]] || fail "CC-008 emitted a COMMIT command tag"
assert_baseline
printf 'CC-008: PASS\n'

reset_fixture
writer_psql -v ON_ERROR_STOP=1 <<'SQL' >/dev/null
BEGIN;
SAVEPOINT allowed_work;
UPDATE public.subscriptions SET status = 'cc009_rolled_back' WHERE id = 1;
UPDATE public.subscriptions SET status = 'cc009_rolled_back' WHERE id = 2;
UPDATE public.subscriptions SET status = 'cc009_rolled_back' WHERE id = 3;
ROLLBACK TO SAVEPOINT allowed_work;
UPDATE public.subscriptions SET status = 'cc009_committed' WHERE id = 4;
UPDATE public.subscriptions SET status = 'cc009_committed' WHERE id = 5;
UPDATE public.subscriptions SET status = 'cc009_committed' WHERE id = 6;
UPDATE public.subscriptions SET status = 'cc009_committed' WHERE id = 7;
UPDATE public.subscriptions SET status = 'cc009_committed' WHERE id = 8;
COMMIT;
SQL
assert_five_updated "CC-009" "cc009_committed"
assert_scalar "CC-009 rolled-back state" \
    "SELECT count(*) FROM public.subscriptions WHERE status = 'cc009_rolled_back';" \
    "0"
printf 'CC-009: PASS\n'

reset_fixture
set +e
cc024_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc024_allowed' WHERE id BETWEEN 1 AND 5;
DO $block$
BEGIN
    BEGIN
        UPDATE public.subscriptions SET status = 'cc024_excess' WHERE id = 6;
        RAISE EXCEPTION 'CC-024 harness expected CommitCap denial';
    EXCEPTION
        WHEN OTHERS THEN
            IF SQLERRM NOT LIKE 'CommitCap mutation budget exceeded%' THEN
                RAISE;
            END IF;
            RAISE NOTICE 'CC024_CAUGHT: %', SQLERRM;
    END;
END
$block$;
SELECT 'cc024_after_recovery' AS recovery_marker;
COMMIT;
SQL
)"
set -e
[[ "$cc024_output" == *"CC024_CAUGHT: CommitCap mutation budget exceeded"* ]] || \
    fail "CC-024 handler did not catch event-six denial"
[[ "$cc024_output" == *"cc024_after_recovery"* ]] || \
    fail "CC-024 did not continue after exception recovery"
[[ "$cc024_output" == *"CommitCap top-level transaction denied after mutation authority violation"* ]] || \
    fail "CC-024 final COMMIT was not rejected"
cc024_commit_evidence="$(printf '%s\n' "$cc024_output" | grep -A3 -F 'CommitCap top-level transaction denied after mutation authority violation' | head -n 4)"
[[ "$cc024_commit_evidence" == *"policy / metric: subscriptions.rows_updated"* && \
   "$cc024_commit_evidence" == *"result: ABORTED"* ]] || \
    fail "CC-024 rejected COMMIT did not carry policy-scoped ABORTED evidence: $cc024_commit_evidence"
cc024_commit_count="$(printf '%s\n' "$cc024_output" | grep -c '^COMMIT$' || true)"
[[ "$cc024_commit_count" == "0" ]] || fail "CC-024 emitted a COMMIT command tag"
assert_baseline
printf 'CC-024: PASS\n'

reset_fixture
set +e
cc032_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
INSERT INTO public.unprotected_audit (message)
VALUES ('cc032_before_denial');
UPDATE public.subscriptions SET status = 'cc032_allowed' WHERE id BETWEEN 1 AND 5;
SAVEPOINT before_denial;
UPDATE public.subscriptions SET status = 'cc032_excess' WHERE id = 6;
ROLLBACK TO SAVEPOINT before_denial;
SELECT 'cc032_after_recovery' AS recovery_marker;
COMMIT;
SQL
)"
set -e
[[ "$cc032_output" == *"INSERT 0 1"* ]] || \
    fail "CC-032 did not execute the unprotected mutation before denial"
[[ "$cc032_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-032 did not report event-six denial"
[[ "$cc032_output" == *"cc032_after_recovery"* ]] || \
    fail "CC-032 did not recover execution after ROLLBACK TO"
[[ "$cc032_output" == *"CommitCap top-level transaction denied after mutation authority violation"* ]] || \
    fail "CC-032 final COMMIT was not rejected"
cc032_commit_count="$(printf '%s\n' "$cc032_output" | grep -c '^COMMIT$' || true)"
[[ "$cc032_commit_count" == "0" ]] || fail "CC-032 emitted a COMMIT command tag"
assert_baseline
assert_scalar "CC-032 unprotected durable state" \
    "SELECT count(*) FROM public.unprotected_audit WHERE message = 'cc032_before_denial';" \
    "0"
printf 'CC-032: PASS (protected and unprotected effects rolled back)\n'

reset_fixture
set +e
cc032_cleanup_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc032_denied_before_cleanup' WHERE id = 1;
UPDATE public.subscriptions SET status = 'cc032_denied_before_cleanup' WHERE id = 2;
UPDATE public.subscriptions SET status = 'cc032_denied_before_cleanup' WHERE id = 3;
UPDATE public.subscriptions SET status = 'cc032_denied_before_cleanup' WHERE id = 4;
UPDATE public.subscriptions SET status = 'cc032_denied_before_cleanup' WHERE id = 5;
UPDATE public.subscriptions SET status = 'cc032_denied_before_cleanup' WHERE id = 6;
COMMIT;
BEGIN;
UPDATE public.subscriptions SET status = 'cc032_after_abort_cleanup' WHERE id BETWEEN 6 AND 10;
COMMIT;
SQL
)"
set -e
[[ "$cc032_cleanup_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-032 cleanup probe did not report denial"
cc032_cleanup_commit_count="$(printf '%s\n' "$cc032_cleanup_output" | grep -c '^COMMIT$' || true)"
[[ "$cc032_cleanup_commit_count" == "1" ]] || \
    fail "CC-032 cleanup probe did not commit exactly one replacement transaction"
assert_scalar "CC-032 next-transaction cleanup state" \
    "SELECT count(*) FILTER (WHERE status = 'baseline') || ':' || count(*) FILTER (WHERE status = 'cc032_after_abort_cleanup') FROM public.subscriptions;" \
    "5:5"
printf 'CC-032 next-transaction cleanup: PASS\n'

reset_fixture
cc025_allowed_output="$(writer_psql -v ON_ERROR_STOP=1 2>&1 <<'SQL'
BEGIN;
PREPARE cc025_allowed(bigint, text) AS
UPDATE public.subscriptions SET status = $2 WHERE id = $1;
EXECUTE cc025_allowed(1, 'cc025_allowed');
EXECUTE cc025_allowed(2, 'cc025_allowed');
EXECUTE cc025_allowed(3, 'cc025_allowed');
EXECUTE cc025_allowed(4, 'cc025_allowed');
EXECUTE cc025_allowed(5, 'cc025_allowed');
COMMIT;
SQL
)"
cc025_allowed_executions="$(printf '%s\n' "$cc025_allowed_output" | grep -c '^UPDATE 1$' || true)"
[[ "$cc025_allowed_executions" == "5" ]] || \
    fail "CC-025 allowed run executed $cc025_allowed_executions prepared updates, expected 5"
assert_five_updated "CC-025 allowed" "cc025_allowed"
printf 'CC-025 allowed prepared executions: PASS\n'

reset_fixture
set +e
cc025_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
PREPARE cc025(bigint, text) AS
UPDATE public.subscriptions SET status = $2 WHERE id = $1;
SELECT 'CC025_START:' || active || ':' || consumed || ':' || denied FROM commitcap_probe.cc_native_probe();
\echo CC025_EXEC_BEGIN
EXECUTE cc025(1, 'cc025');
EXECUTE cc025(2, 'cc025');
EXECUTE cc025(3, 'cc025');
EXECUTE cc025(4, 'cc025');
EXECUTE cc025(5, 'cc025');
EXECUTE cc025(6, 'cc025');
\echo CC025_EXEC_END
COMMIT;
BEGIN;
SELECT 'CC025_CLEAN_START:' || active || ':' || consumed || ':' || denied FROM commitcap_probe.cc_native_probe();
UPDATE public.subscriptions SET status = 'cc025_cleanup' WHERE id BETWEEN 1 AND 5;
COMMIT;
SQL
)"
set -e
[[ "$cc025_output" == *"CC025_START:false:0:false"* && \
   "$cc025_output" == *"CC025_CLEAN_START:false:0:false"* ]] || \
    fail "CC-025 writer did not begin both transactions with fresh authority"
[[ "$cc025_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-025 did not report event-six denial"
[[ "$cc025_output" == *"policy / metric: subscriptions.rows_updated"* && \
   "$cc025_output" == *"granted: 5"* && \
   "$cc025_output" == *"consumed before attempt: 5"* && \
   "$cc025_output" == *"attempted effect: 6 row-update events"* ]] || \
    fail "CC-025 denial lacked exact event-six authority evidence"
# psql UPDATE tags and \echo markers are stdout; server ERROR is stderr.
# Their merged order is not a transaction chronology. Count only the stdout
# block enclosing the six prepared EXECUTEs, independent of ERROR placement.
cc025_begin_count="$(printf '%s\n' "$cc025_output" | grep -c '^CC025_EXEC_BEGIN$' || true)"
cc025_end_count="$(printf '%s\n' "$cc025_output" | grep -c '^CC025_EXEC_END$' || true)"
[[ "$cc025_begin_count" == "1" && "$cc025_end_count" == "1" ]] || \
    fail "CC-025 prepared-execution stdout markers missing or duplicated"
cc025_prepared_updates="$(printf '%s\n' "$cc025_output" | sed -n '/^CC025_EXEC_BEGIN$/,/^CC025_EXEC_END$/p' | grep -c '^UPDATE 1$' || true)"
[[ "$cc025_prepared_updates" == "5" ]] || \
    fail "CC-025 allowed $cc025_prepared_updates prepared executions, expected 5"
cc025_rollback_count="$(printf '%s\n' "$cc025_output" | grep -c '^ROLLBACK$' || true)"
[[ "$cc025_rollback_count" == "1" ]] || \
    fail "CC-025 denied transaction did not produce exactly one ROLLBACK"
cc025_commit_count="$(printf '%s\n' "$cc025_output" | grep -c '^COMMIT$' || true)"
[[ "$cc025_commit_count" == "1" ]] || \
    fail "CC-025 cleanup transaction did not produce exactly one COMMIT"
assert_scalar "CC-025 durable state" \
    "SELECT count(*) FILTER (WHERE status = 'baseline') || ':' || count(*) FILTER (WHERE status = 'cc025_cleanup') || ':' || count(*) FILTER (WHERE status = 'cc025') FROM public.subscriptions;" \
    "5:5:0"
printf 'CC-025: PASS (prepared executions shared one budget; denial rolled back at COMMIT)\n'

reset_fixture
cc011_allowed_output="$(writer_psql -v ON_ERROR_STOP=1 2>&1 <<'SQL'
BEGIN;
WITH updated AS (
    UPDATE public.subscriptions
    SET status = 'cc011_allowed'
    WHERE id BETWEEN 1 AND 5
    RETURNING id
)
SELECT count(*) FROM updated;
COMMIT;
SQL
)"
cc011_allowed_count="$(printf '%s\n' "$cc011_allowed_output" | grep -c '^ *5$' || true)"
[[ "$cc011_allowed_count" == "1" ]] || \
    fail "CC-011 allowed CTE did not report five returned row-update events"
assert_five_updated "CC-011 allowed" "cc011_allowed"
printf 'CC-011 allowed data-modifying CTE: PASS\n'

reset_fixture
set +e
cc011_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
WITH updated AS (
    UPDATE public.subscriptions
    SET status = 'cc011'
    WHERE id BETWEEN 1 AND 6
    RETURNING id
)
SELECT count(*) FROM updated;
COMMIT;
BEGIN;
UPDATE public.subscriptions SET status = 'cc011_cleanup' WHERE id BETWEEN 1 AND 5;
COMMIT;
SQL
)"
set -e
[[ "$cc011_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-011 did not report event-six denial"
cc011_commit_count="$(printf '%s\n' "$cc011_output" | grep -c '^COMMIT$' || true)"
[[ "$cc011_commit_count" == "1" ]] || \
    fail "CC-011 cleanup transaction did not produce exactly one COMMIT"
assert_scalar "CC-011 durable state" \
    "SELECT count(*) FILTER (WHERE status = 'baseline') || ':' || count(*) FILTER (WHERE status = 'cc011_cleanup') || ':' || count(*) FILTER (WHERE status = 'cc011') FROM public.subscriptions;" \
    "5:5:0"
printf 'CC-011: PASS (data-modifying CTE shared one budget; denial rolled back at COMMIT)\n'

reset_fixture
cc033_allowed_output="$(writer_psql -v ON_ERROR_STOP=1 2>&1 <<'SQL'
BEGIN;
EXPLAIN (ANALYZE, COSTS OFF)
UPDATE public.subscriptions
SET status = 'cc033_allowed'
WHERE id BETWEEN 1 AND 5;
COMMIT;
SQL
)"
[[ "$cc033_allowed_output" == *"subscriptions_update_budget"*"calls=5"* ]] || \
    fail "CC-033 allowed EXPLAIN (ANALYZE) did not invoke the protected update trigger five times"
assert_five_updated "CC-033 allowed" "cc033_allowed"
printf 'CC-033 allowed EXPLAIN (ANALYZE): PASS\n'

reset_fixture
set +e
cc033_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
EXPLAIN (ANALYZE, COSTS OFF)
UPDATE public.subscriptions
SET status = 'cc033'
WHERE id BETWEEN 1 AND 6;
COMMIT;
BEGIN;
UPDATE public.subscriptions SET status = 'cc033_cleanup' WHERE id BETWEEN 1 AND 5;
COMMIT;
SQL
)"
set -e
[[ "$cc033_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-033 did not report event-six denial"
cc033_commit_count="$(printf '%s\n' "$cc033_output" | grep -c '^COMMIT$' || true)"
[[ "$cc033_commit_count" == "1" ]] || \
    fail "CC-033 cleanup transaction did not produce exactly one COMMIT"
assert_scalar "CC-033 durable state" \
    "SELECT count(*) FILTER (WHERE status = 'baseline') || ':' || count(*) FILTER (WHERE status = 'cc033_cleanup') || ':' || count(*) FILTER (WHERE status = 'cc033') || ':' || count(*) FILTER (WHERE status = 'cc033_allowed') FROM public.subscriptions;" \
    "5:5:0:0"
printf 'CC-033: PASS (EXPLAIN ANALYZE shared one budget; denial rolled back at COMMIT)\n'

printf '\n--- CC-019 / CC-020: concurrent sessions ---\n'
reset_fixture
CC_SESSION_DIR="$(mktemp -d "${TMPDIR:-/tmp}/commitcap_native_sessions.XXXXXX")"
cc_session_start a
cc_session_start b
cc_session_attach a
cc_session_attach b

cc_probe_assert a CCPID_A f 0 f
cc_probe_assert b CCPID_B f 0 f
cc_a_pid="$(cc_probe_field a CCPID_A2 pid)"
cc_b_pid="$(cc_probe_field b CCPID_B2 pid)"
[[ "$cc_a_pid" != "$cc_b_pid" ]] || fail "CC-019 sessions shared backend pid $cc_a_pid"
printf 'CC-019 backend A pid: %s\n' "$cc_a_pid"
printf 'CC-019 backend B pid: %s\n' "$cc_b_pid"

printf '\nCC-019 case 1: independent concurrent budgets\n'
cc_send a "BEGIN;"
cc_send a "SELECT 'CCISO_A:' || current_setting('transaction_isolation');"
cc_wait_output a "CCISO_A:" "isolation level for session A"
cc_a_iso="$(grep -F 'CCISO_A:' "$CC_SESSION_DIR/a.out" | tail -n 1 | cut -d: -f2)"
cc_send b "BEGIN;"
cc_send b "SELECT 'CCISO_B:' || current_setting('transaction_isolation');"
cc_wait_output b "CCISO_B:" "isolation level for session B"
cc_b_iso="$(grep -F 'CCISO_B:' "$CC_SESSION_DIR/b.out" | tail -n 1 | cut -d: -f2)"
[[ "$cc_a_iso" == "read committed" && "$cc_b_iso" == "read committed" ]] || \
    fail "unexpected isolation level A=$cc_a_iso B=$cc_b_iso"
printf 'CC-019 isolation level: A=%s B=%s\n' "$cc_a_iso" "$cc_b_iso"

cc_send a "UPDATE public.subscriptions SET status = 'cc019_a' WHERE id BETWEEN 1 AND 4;"
cc_probe_assert a CCP19_A1 t 4 f
cc_send b "UPDATE public.subscriptions SET status = 'cc019_b' WHERE id BETWEEN 6 AND 9;"
cc_probe_assert b CCP19_B1 t 4 f
cc_probe_assert a CCP19_A2 t 4 f
cc_probe_assert b CCP19_B2 t 4 f
cc_send a "UPDATE public.subscriptions SET status = 'cc019_a' WHERE id = 5;"
cc_probe_assert a CCP19_A3 t 5 f
cc_send a "COMMIT;"
cc_sync a CCP19_A_COMMIT
cc_probe_assert a CCP19_A4 f 0 f
cc_send b "UPDATE public.subscriptions SET status = 'cc019_b' WHERE id = 10;"
cc_probe_assert b CCP19_B3 t 5 f
cc_send b "COMMIT;"
cc_sync b CCP19_B_COMMIT
cc_probe_assert b CCP19_B4 f 0 f
assert_scalar "CC-019 case 1 durable" \
    "SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions;" \
    "1=cc019_a,2=cc019_a,3=cc019_a,4=cc019_a,5=cc019_a,6=cc019_b,7=cc019_b,8=cc019_b,9=cc019_b,10=cc019_b"
cc_lifecycle_check cc019a
printf 'CC-019 independent budgets: PASS\n'

printf '\nCC-019 case 2: session A denied, session B commits\n'
reset_fixture
cc_send a "BEGIN;"
cc_send a "UPDATE public.subscriptions SET status = 'cc019_deny_a' WHERE id BETWEEN 1 AND 4;"
cc_send a "UPDATE public.subscriptions SET status = 'cc019_deny_a' WHERE id = 5;"
cc_send a "SAVEPOINT before_denial;"
cc_send a "UPDATE public.subscriptions SET status = 'cc019_deny_a' WHERE id = 6;"
cc_wait_output a "CommitCap mutation budget exceeded" "session A event-six denial"
cc_send a "ROLLBACK TO SAVEPOINT before_denial;"
cc_probe_assert a CCP19D_A1 t 5 t
cc_send b "BEGIN;"
cc_send b "UPDATE public.subscriptions SET status = 'cc019_ok_b' WHERE id BETWEEN 7 AND 10;"
cc_probe_assert b CCP19D_B1 t 4 f
cc_send b "UPDATE public.subscriptions SET status = 'cc019_ok_b' WHERE id = 7;"
cc_probe_assert b CCP19D_B2 t 5 f
cc_send b "COMMIT;"
cc_sync b CCP19D_B_COMMIT
cc_probe_assert b CCP19D_B3 f 0 f
cc_send a "COMMIT;"
cc_wait_output a "denied after mutation authority violation" "session A commit rejection"
cc_probe_assert a CCP19D_A2 f 0 f
assert_scalar "CC-019 case 2 durable" \
    "SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions;" \
    "1=baseline,2=baseline,3=baseline,4=baseline,5=baseline,6=baseline,7=cc019_ok_b,8=cc019_ok_b,9=cc019_ok_b,10=cc019_ok_b"
cc_lifecycle_check cc019d
printf 'CC-019 denial isolation A to B: PASS\n'

printf '\nCC-019 case 3: session B denied, session A commits\n'
reset_fixture
cc_send b "BEGIN;"
cc_send b "UPDATE public.subscriptions SET status = 'cc019_rev_b' WHERE id BETWEEN 1 AND 5;"
cc_send b "SAVEPOINT before_denial;"
cc_send b "UPDATE public.subscriptions SET status = 'cc019_rev_b' WHERE id = 6;"
cc_wait_output b "CommitCap mutation budget exceeded" "session B event-six denial"
cc_send b "ROLLBACK TO SAVEPOINT before_denial;"
cc_probe_assert b CCP19R_B1 t 5 t
cc_send a "BEGIN;"
cc_send a "UPDATE public.subscriptions SET status = 'cc019_rev_a' WHERE id BETWEEN 7 AND 10;"
cc_send a "UPDATE public.subscriptions SET status = 'cc019_rev_a' WHERE id = 7;"
cc_probe_assert a CCP19R_A1 t 5 f
cc_send a "COMMIT;"
cc_sync a CCP19R_A_COMMIT
cc_probe_assert a CCP19R_A2 f 0 f
cc_send b "COMMIT;"
cc_wait_output b "denied after mutation authority violation" "session B commit rejection"
cc_probe_assert b CCP19R_B2 f 0 f
assert_scalar "CC-019 case 3 durable" \
    "SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions;" \
    "1=baseline,2=baseline,3=baseline,4=baseline,5=baseline,6=baseline,7=cc019_rev_a,8=cc019_rev_a,9=cc019_rev_a,10=cc019_rev_a"
cc_lifecycle_check cc019r
printf 'CC-019 denial isolation B to A: PASS\n'

printf '\nCC-020 scenario A: session A commits while session B waits on its row lock\n'
reset_fixture
cc_deadlocks_before="$(admin_psql -At -v ON_ERROR_STOP=1 -c \
    "SELECT deadlocks FROM pg_stat_database WHERE datname = current_database();")"
cc_send a "BEGIN;"
cc_send a "UPDATE public.subscriptions SET status = 'cc020a_a' WHERE id = 1;"
cc_probe_assert a CCP20A_A1 t 1 f
cc_b_events_before="$(cc_event_count "$cc_b_pid")"
cc_send b "BEGIN;"
cc_send b "UPDATE public.subscriptions SET status = 'cc020a_b' WHERE id = 1;"
cc_wait_blocked "$cc_b_pid" "$cc_a_pid" "CC-020 scenario A"
# Only lets the server log absorb any event written before the wait; the
# blocked state itself is verified in pg_stat_activity.
sleep 0.5
cc_b_events_blocked="$(cc_event_count "$cc_b_pid")"
printf 'CC-020 scenario A: B trigger events while blocked=%s\n' \
    "$((cc_b_events_blocked - cc_b_events_before))"
cc_probe_assert a CCP20A_A2 t 1 f
cc_a_commits_before="$(cc_count_tag a COMMIT)"
cc_send a "COMMIT;"
cc_sync a CCP20A_A_COMMIT
[[ "$(( $(cc_count_tag a COMMIT) - cc_a_commits_before ))" == "1" ]] || \
    fail "CC-020 scenario A: session A did not commit"
cc_probe_assert a CCP20A_A3 f 0 f
cc_sync b CCP20A_B_RESUMED
cc_b_events_resumed="$(cc_event_count "$cc_b_pid")"
printf 'CC-020 scenario A: B trigger events after resume=%s\n' \
    "$((cc_b_events_resumed - cc_b_events_blocked))"
cc_b_consumed="$(cc_probe_field b CCP20A_B1 consumed)"
printf 'CC-020 scenario A: B consumed=%s for the contended row event\n' "$cc_b_consumed"
[[ "$cc_b_consumed" == "1" || "$cc_b_consumed" == "2" ]] || \
    fail "CC-020 scenario A: B consumed $cc_b_consumed for one contended update"
cc_b_remaining=$((5 - cc_b_consumed))
cc_i=2
while (( cc_i <= 1 + cc_b_remaining )); do
    cc_send b "UPDATE public.subscriptions SET status = 'cc020a_b' WHERE id = $cc_i;"
    cc_i=$((cc_i + 1))
done
cc_probe_assert b CCP20A_B2 t 5 f
cc_send b "COMMIT;"
cc_sync b CCP20A_B_COMMIT
cc_probe_assert b CCP20A_B3 f 0 f
cc_expected=""
cc_i=1
while (( cc_i <= 10 )); do
    if (( cc_i <= 1 + cc_b_remaining )); then cc_status='cc020a_b'; else cc_status='baseline'; fi
    cc_expected="${cc_expected}${cc_expected:+,}$cc_i=$cc_status"
    cc_i=$((cc_i + 1))
done
assert_scalar "CC-020 scenario A durable" \
    "SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions;" \
    "$cc_expected"
cc_deadlocks_after="$(admin_psql -At -v ON_ERROR_STOP=1 -c \
    "SELECT deadlocks FROM pg_stat_database WHERE datname = current_database();")"
printf 'CC-020 scenario A: deadlock count %s -> %s\n' "$cc_deadlocks_before" "$cc_deadlocks_after"
cc_lifecycle_check cc020a
printf 'CC-020 row-lock contention with A commit: PASS\n'

printf '\nCC-020 scenario B: session A denied while session B waits on its row lock\n'
reset_fixture
cc_send a "BEGIN;"
cc_send a "UPDATE public.subscriptions SET status = 'cc020b_a' WHERE id BETWEEN 1 AND 4;"
cc_send a "UPDATE public.subscriptions SET status = 'cc020b_a' WHERE id = 5;"
cc_probe_assert a CCP20B_A1 t 5 f
cc_b_events_before="$(cc_event_count "$cc_b_pid")"
cc_send b "BEGIN;"
cc_send b "UPDATE public.subscriptions SET status = 'cc020b_b' WHERE id = 5;"
cc_wait_blocked "$cc_b_pid" "$cc_a_pid" "CC-020 scenario B"
# Only lets the server log absorb any event written before the wait; the
# blocked state itself is verified in pg_stat_activity.
sleep 0.5
cc_b_events_blocked="$(cc_event_count "$cc_b_pid")"
printf 'CC-020 scenario B: B trigger events while blocked=%s\n' \
    "$((cc_b_events_blocked - cc_b_events_before))"
cc_send a "UPDATE public.subscriptions SET status = 'cc020b_a' WHERE id = 6;"
cc_wait_output a "CommitCap mutation budget exceeded" "session A event-six denial under contention"
cc_a_rollbacks_before="$(cc_count_tag a ROLLBACK)"
cc_send a "COMMIT;"
cc_sync a CCP20B_A_ENDED
[[ "$(( $(cc_count_tag a ROLLBACK) - cc_a_rollbacks_before ))" == "1" ]] || \
    fail "CC-020 scenario B: session A COMMIT was not rejected as ROLLBACK"
cc_probe_assert a CCP20B_A2 f 0 f
cc_sync b CCP20B_B_RESUMED
cc_b_events_resumed="$(cc_event_count "$cc_b_pid")"
printf 'CC-020 scenario B: B trigger events after resume=%s\n' \
    "$((cc_b_events_resumed - cc_b_events_blocked))"
cc_b_consumed="$(cc_probe_field b CCP20B_B1 consumed)"
printf 'CC-020 scenario B: B consumed=%s for the contended row event\n' "$cc_b_consumed"
[[ "$cc_b_consumed" == "1" || "$cc_b_consumed" == "2" ]] || \
    fail "CC-020 scenario B: B consumed $cc_b_consumed for one contended update"
cc_b_remaining=$((5 - cc_b_consumed))
cc_i=6
while (( cc_i <= 5 + cc_b_remaining )); do
    cc_send b "UPDATE public.subscriptions SET status = 'cc020b_b' WHERE id = $cc_i;"
    cc_i=$((cc_i + 1))
done
cc_probe_assert b CCP20B_B2 t 5 f
cc_send b "COMMIT;"
cc_sync b CCP20B_B_COMMIT
cc_probe_assert b CCP20B_B3 f 0 f
cc_expected=""
cc_i=1
while (( cc_i <= 10 )); do
    if (( cc_i >= 5 && cc_i <= 5 + cc_b_remaining )); then cc_status='cc020b_b'; else cc_status='baseline'; fi
    cc_expected="${cc_expected}${cc_expected:+,}$cc_i=$cc_status"
    cc_i=$((cc_i + 1))
done
assert_scalar "CC-020 scenario B durable" \
    "SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions;" \
    "$cc_expected"
cc_deadlocks_after="$(admin_psql -At -v ON_ERROR_STOP=1 -c \
    "SELECT deadlocks FROM pg_stat_database WHERE datname = current_database();")"
printf 'CC-020 scenario B: deadlock count %s -> %s\n' "$cc_deadlocks_before" "$cc_deadlocks_after"
cc_lifecycle_check cc020b
printf 'CC-020 denial under contention: PASS\n'

cc_session_stop a
cc_session_stop b
printf 'CC-019 / CC-020 concurrent sessions: all requested cases PASS\n'

printf '\n--- CC-021: backend reuse across top-level transactions ---\n'
rm -rf "$CC_SESSION_DIR"
CC_SESSION_DIR="$(mktemp -d "${TMPDIR:-/tmp}/commitcap_native_reuse.XXXXXX")"
reset_fixture
cc_session_start a
cc_session_attach a
cc_send a "SELECT 'CC21_BACKEND_PID:' || pg_backend_pid();"
cc_wait_output a "CC21_BACKEND_PID:" "CC-021 backend pid"
cc21_pid="$(grep -F 'CC21_BACKEND_PID:' "$CC_SESSION_DIR/a.out" | tail -n 1 | cut -d: -f2)"
printf 'CC-021 reused backend pid: %s\n' "$cc21_pid"
cc_probe_assert a CC21_PID f 0 f "$cc21_pid"

printf '\nCC-021 scenario A: COMMIT then reuse\n'
reset_fixture
cc_life_before="$(cc_lifecycle_count "$cc21_pid")"
cc_send a "BEGIN;"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_a1' WHERE id BETWEEN 1 AND 5;"
cc_probe_assert a CC21_A_TX1 t 5 f "$cc21_pid"
cc_send a "COMMIT;"
cc_sync a CC21_A_TX1_END
cc_probe_assert a CC21_A_TX2_START f 0 f "$cc21_pid"
cc_send a "BEGIN;"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_a2' WHERE id BETWEEN 6 AND 10;"
cc_probe_assert a CC21_A_TX2 t 5 f "$cc21_pid"
cc_send a "COMMIT;"
cc_sync a CC21_A_TX2_END
cc_probe_assert a CC21_A_AFTER f 0 f "$cc21_pid"
assert_scalar "CC-021 scenario A durable" \
    "SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions;" \
    "1=cc021_a1,2=cc021_a1,3=cc021_a1,4=cc021_a1,5=cc021_a1,6=cc021_a2,7=cc021_a2,8=cc021_a2,9=cc021_a2,10=cc021_a2"
cc21_life="$(cc_lifecycle_since "$cc21_pid" "$cc_life_before")"
printf '  callback sequence:\n'
printf '%s\n' "$cc21_life" | sed 's/^/    /'
printf '%s\n' "$cc21_life" | grep -q 'event=XACT_PRE_COMMIT .*denied=false' || \
    fail "CC-021 scenario A did not log an allowed PRE_COMMIT"
printf '%s\n' "$cc21_life" | grep -q 'event=XACT_COMMIT .*denied=false' || \
    fail "CC-021 scenario A did not log a top-level COMMIT"
printf 'CC-021 scenario A: PASS\n'

printf '\nCC-021 scenario B: ROLLBACK then reuse\n'
reset_fixture
cc_life_before="$(cc_lifecycle_count "$cc21_pid")"
cc_send a "BEGIN;"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_b_aborted' WHERE id BETWEEN 1 AND 4;"
cc_probe_assert a CC21_B_TX1 t 4 f "$cc21_pid"
cc_send a "ROLLBACK;"
cc_sync a CC21_B_TX1_END
cc_probe_assert a CC21_B_TX2_START f 0 f "$cc21_pid"
cc_send a "BEGIN;"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_b' WHERE id BETWEEN 1 AND 5;"
cc_probe_assert a CC21_B_TX2 t 5 f "$cc21_pid"
cc_send a "COMMIT;"
cc_sync a CC21_B_TX2_END
cc_probe_assert a CC21_B_AFTER f 0 f "$cc21_pid"
assert_scalar "CC-021 scenario B durable" \
    "SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions;" \
    "1=cc021_b,2=cc021_b,3=cc021_b,4=cc021_b,5=cc021_b,6=baseline,7=baseline,8=baseline,9=baseline,10=baseline"
cc21_life="$(cc_lifecycle_since "$cc21_pid" "$cc_life_before")"
printf '  callback sequence:\n'
printf '%s\n' "$cc21_life" | sed 's/^/    /'
printf '%s\n' "$cc21_life" | grep -q 'event=XACT_ABORT .*denied=false' || \
    fail "CC-021 scenario B did not log the TX1 abort"
printf '%s\n' "$cc21_life" | grep -q 'event=XACT_COMMIT .*denied=false' || \
    fail "CC-021 scenario B did not log the TX2 commit"
printf 'CC-021 scenario B: PASS\n'

printf '\nCC-021 scenario C: denied ABORT then reuse\n'
reset_fixture
cc_life_before="$(cc_lifecycle_count "$cc21_pid")"
cc21_rollbacks_before="$(cc_count_tag a ROLLBACK)"
cc_send a "BEGIN;"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_c_denied' WHERE id BETWEEN 1 AND 5;"
cc_probe_assert a CC21_C_TX1 t 5 f "$cc21_pid"
cc21_denials_before="$(grep -cF 'CommitCap mutation budget exceeded' "$CC_SESSION_DIR/a.out" || true)"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_c_denied' WHERE id = 6;"
cc_send a "COMMIT;"
cc_sync a CC21_C_TX1_END
[[ "$(( $(grep -cF 'CommitCap mutation budget exceeded' "$CC_SESSION_DIR/a.out" || true) - cc21_denials_before ))" == "1" ]] || \
    fail "CC-021 scenario C did not report the event-six denial"
[[ "$(( $(cc_count_tag a ROLLBACK) - cc21_rollbacks_before ))" == "1" ]] || \
    fail "CC-021 scenario C COMMIT was not converted to ROLLBACK"
cc_probe_assert a CC21_C_TX2_START f 0 f "$cc21_pid"
cc_send a "BEGIN;"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_c' WHERE id BETWEEN 6 AND 10;"
cc_probe_assert a CC21_C_TX2 t 5 f "$cc21_pid"
cc_send a "COMMIT;"
cc_sync a CC21_C_TX2_END
cc_probe_assert a CC21_C_AFTER f 0 f "$cc21_pid"
assert_scalar "CC-021 scenario C durable" \
    "SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions;" \
    "1=baseline,2=baseline,3=baseline,4=baseline,5=baseline,6=cc021_c,7=cc021_c,8=cc021_c,9=cc021_c,10=cc021_c"
cc21_life="$(cc_lifecycle_since "$cc21_pid" "$cc_life_before")"
printf '  callback sequence:\n'
printf '%s\n' "$cc21_life" | sed 's/^/    /'
printf '%s\n' "$cc21_life" | grep -q 'event=XACT_ABORT .*denied=true' || \
    fail "CC-021 scenario C did not log the denied TX1 abort"
printf '%s\n' "$cc21_life" | grep -q 'event=XACT_COMMIT .*denied=false' || \
    fail "CC-021 scenario C did not log the TX2 commit"
printf 'CC-021 scenario C: PASS\n'

printf '\nCC-021 scenario D: savepoint sticky denial then reuse\n'
reset_fixture
cc_life_before="$(cc_lifecycle_count "$cc21_pid")"
cc_send a "BEGIN;"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_d_denied' WHERE id BETWEEN 1 AND 5;"
cc_send a "SAVEPOINT before_denial;"
cc21_denials_before="$(grep -cF 'CommitCap mutation budget exceeded' "$CC_SESSION_DIR/a.out" || true)"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_d_denied' WHERE id = 6;"
cc_send a "ROLLBACK TO SAVEPOINT before_denial;"
cc_probe_assert a CC21_D_TX1_RECOVERED t 5 t "$cc21_pid"
[[ "$(( $(grep -cF 'CommitCap mutation budget exceeded' "$CC_SESSION_DIR/a.out" || true) - cc21_denials_before ))" == "1" ]] || \
    fail "CC-021 scenario D did not report the event-six denial"
cc21_rejections_before="$(grep -cF 'denied after mutation authority violation' "$CC_SESSION_DIR/a.out" || true)"
cc_send a "COMMIT;"
cc_sync a CC21_D_TX1_END
[[ "$(( $(grep -cF 'denied after mutation authority violation' "$CC_SESSION_DIR/a.out" || true) - cc21_rejections_before ))" == "1" ]] || \
    fail "CC-021 scenario D COMMIT was not rejected at PRE_COMMIT"
cc_probe_assert a CC21_D_TX2_START f 0 f "$cc21_pid"
cc_send a "BEGIN;"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_d' WHERE id BETWEEN 6 AND 10;"
cc_probe_assert a CC21_D_TX2 t 5 f "$cc21_pid"
cc_send a "COMMIT;"
cc_sync a CC21_D_TX2_END
cc_probe_assert a CC21_D_AFTER f 0 f "$cc21_pid"
assert_scalar "CC-021 scenario D durable" \
    "SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions;" \
    "1=baseline,2=baseline,3=baseline,4=baseline,5=baseline,6=cc021_d,7=cc021_d,8=cc021_d,9=cc021_d,10=cc021_d"
cc21_life="$(cc_lifecycle_since "$cc21_pid" "$cc_life_before")"
printf '  callback sequence:\n'
printf '%s\n' "$cc21_life" | sed 's/^/    /'
printf '%s\n' "$cc21_life" | grep -q 'event=XACT_PRE_COMMIT .*denied=true' || \
    fail "CC-021 scenario D did not log denied=true at PRE_COMMIT"
printf '%s\n' "$cc21_life" | grep -q 'event=XACT_ABORT .*denied=true' || \
    fail "CC-021 scenario D did not log the denied abort"
printf '%s\n' "$cc21_life" | grep -q 'event=XACT_COMMIT .*denied=false' || \
    fail "CC-021 scenario D did not log the TX2 commit"
printf 'CC-021 scenario D: PASS\n'

printf '\nCC-021 scenario E: PL/pgSQL caught denial then reuse\n'
reset_fixture
cc_life_before="$(cc_lifecycle_count "$cc21_pid")"
cc_send a "BEGIN;"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_e_denied' WHERE id BETWEEN 1 AND 5;"
cc_send a "DO \$cc21\$ BEGIN BEGIN UPDATE public.subscriptions SET status = 'cc021_e_denied' WHERE id = 6; RAISE EXCEPTION 'expected CommitCap denial'; EXCEPTION WHEN OTHERS THEN IF SQLERRM NOT LIKE 'CommitCap mutation budget exceeded%' THEN RAISE; END IF; RAISE NOTICE 'CC21_E_CAUGHT: %', SQLERRM; END; END \$cc21\$;"
cc_wait_output a "CC21_E_CAUGHT: CommitCap mutation budget exceeded" "CC-021 scenario E caught denial"
cc_probe_assert a CC21_E_TX1 t 5 t "$cc21_pid"
cc21_rejections_before="$(grep -cF 'denied after mutation authority violation' "$CC_SESSION_DIR/a.out" || true)"
cc_send a "COMMIT;"
cc_sync a CC21_E_TX1_END
[[ "$(( $(grep -cF 'denied after mutation authority violation' "$CC_SESSION_DIR/a.out" || true) - cc21_rejections_before ))" == "1" ]] || \
    fail "CC-021 scenario E COMMIT was not rejected at PRE_COMMIT"
cc_probe_assert a CC21_E_TX2_START f 0 f "$cc21_pid"
cc_send a "BEGIN;"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_e' WHERE id BETWEEN 6 AND 10;"
cc_probe_assert a CC21_E_TX2 t 5 f "$cc21_pid"
cc_send a "COMMIT;"
cc_sync a CC21_E_TX2_END
cc_probe_assert a CC21_E_AFTER f 0 f "$cc21_pid"
assert_scalar "CC-021 scenario E durable" \
    "SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions;" \
    "1=baseline,2=baseline,3=baseline,4=baseline,5=baseline,6=cc021_e,7=cc021_e,8=cc021_e,9=cc021_e,10=cc021_e"
cc21_life="$(cc_lifecycle_since "$cc21_pid" "$cc_life_before")"
printf '  callback sequence:\n'
printf '%s\n' "$cc21_life" | sed 's/^/    /'
printf '%s\n' "$cc21_life" | grep -q 'event=XACT_PRE_COMMIT .*denied=true' || \
    fail "CC-021 scenario E did not log denied=true at PRE_COMMIT"
printf '%s\n' "$cc21_life" | grep -q 'event=XACT_ABORT .*denied=true' || \
    fail "CC-021 scenario E did not log the denied abort"
printf '%s\n' "$cc21_life" | grep -q 'event=XACT_COMMIT .*denied=false' || \
    fail "CC-021 scenario E did not log the TX2 commit"
printf 'CC-021 scenario E: PASS\n'

printf '\nCC-021 autocommit reuse\n'
reset_fixture
cc_life_before="$(cc_lifecycle_count "$cc21_pid")"
cc_probe_assert a CC21_F_START f 0 f "$cc21_pid"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_f1' WHERE id BETWEEN 1 AND 5;"
cc_sync a CC21_F_TX1
cc_probe_assert a CC21_F_AFTER1 f 0 f "$cc21_pid"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_f2' WHERE id BETWEEN 6 AND 10;"
cc_sync a CC21_F_TX2
cc_probe_assert a CC21_F_AFTER2 f 0 f "$cc21_pid"
assert_scalar "CC-021 autocommit two transactions durable" \
    "SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions;" \
    "1=cc021_f1,2=cc021_f1,3=cc021_f1,4=cc021_f1,5=cc021_f1,6=cc021_f2,7=cc021_f2,8=cc021_f2,9=cc021_f2,10=cc021_f2"
cc21_denials_before="$(grep -cF 'CommitCap mutation budget exceeded' "$CC_SESSION_DIR/a.out" || true)"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_f3' WHERE id BETWEEN 1 AND 6;"
cc_sync a CC21_F_TX3_DENIED
[[ "$(( $(grep -cF 'CommitCap mutation budget exceeded' "$CC_SESSION_DIR/a.out" || true) - cc21_denials_before ))" == "1" ]] || \
    fail "CC-021 autocommit six-event statement was not denied"
cc_probe_assert a CC21_F_AFTER_DENIAL f 0 f "$cc21_pid"
assert_scalar "CC-021 autocommit denial durable" \
    "SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions;" \
    "1=cc021_f1,2=cc021_f1,3=cc021_f1,4=cc021_f1,5=cc021_f1,6=cc021_f2,7=cc021_f2,8=cc021_f2,9=cc021_f2,10=cc021_f2"
cc_send a "UPDATE public.subscriptions SET status = 'cc021_f4' WHERE id = 1;"
cc_sync a CC21_F_TX4
cc_probe_assert a CC21_F_AFTER4 f 0 f "$cc21_pid"
assert_scalar "CC-021 autocommit recovery durable" \
    "SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions;" \
    "1=cc021_f4,2=cc021_f1,3=cc021_f1,4=cc021_f1,5=cc021_f1,6=cc021_f2,7=cc021_f2,8=cc021_f2,9=cc021_f2,10=cc021_f2"
cc21_life="$(cc_lifecycle_since "$cc21_pid" "$cc_life_before")"
printf '  callback sequence:\n'
printf '%s\n' "$cc21_life" | sed 's/^/    /'
printf '%s\n' "$cc21_life" | grep -q 'event=XACT_COMMIT .*denied=false' || \
    fail "CC-021 autocommit did not log a committed transaction"
printf '%s\n' "$cc21_life" | grep -q 'event=XACT_ABORT .*denied=true' || \
    fail "CC-021 autocommit did not log the denied abort"
printf 'CC-021 autocommit reuse: PASS\n'

cc_send a "SELECT 'CC21_FINAL_PID:' || pg_backend_pid();"
cc_wait_output a "CC21_FINAL_PID:" "CC-021 final backend pid"
cc21_final_pid="$(grep -F 'CC21_FINAL_PID:' "$CC_SESSION_DIR/a.out" | tail -n 1 | cut -d: -f2)"
[[ "$cc21_final_pid" == "$cc21_pid" ]] || \
    fail "CC-021 backend changed from $cc21_pid to $cc21_final_pid"
cc_probe_assert a CC21_FINAL f 0 f "$cc21_pid"
cc_session_stop a
printf 'CC-021: PASS (same backend %s reused; every scenario started at consumed=0 denied=false)\n' "$cc21_pid"

reset_fixture
writer_psql -v ON_ERROR_STOP=1 <<'SQL' >/dev/null
BEGIN;
UPDATE public.subscriptions SET status = 'cleanup_tx1_commit' WHERE id BETWEEN 1 AND 3;
COMMIT;
BEGIN;
UPDATE public.subscriptions SET status = 'cleanup_tx2_after_commit' WHERE id BETWEEN 4 AND 8;
COMMIT;
SQL
assert_scalar "same-backend cleanup after COMMIT" \
    "SELECT count(*) FILTER (WHERE status = 'cleanup_tx1_commit') || ':' || count(*) FILTER (WHERE status = 'cleanup_tx2_after_commit') FROM public.subscriptions;" \
    "3:5"
printf 'lifecycle cleanup after top-level COMMIT: PASS\n'

reset_fixture
writer_psql -v ON_ERROR_STOP=1 <<'SQL' >/dev/null
BEGIN;
UPDATE public.subscriptions SET status = 'cleanup_tx1_abort' WHERE id BETWEEN 1 AND 3;
ROLLBACK;
BEGIN;
UPDATE public.subscriptions SET status = 'cleanup_tx2_after_abort' WHERE id BETWEEN 4 AND 8;
COMMIT;
SQL
assert_scalar "same-backend cleanup after ABORT" \
    "SELECT count(*) FILTER (WHERE status = 'cleanup_tx1_abort') || ':' || count(*) FILTER (WHERE status = 'cleanup_tx2_after_abort') FROM public.subscriptions;" \
    "0:5"
printf 'lifecycle cleanup after top-level ABORT: PASS\n'

reset_fixture
writer_psql -v ON_ERROR_STOP=1 <<'SQL' >/dev/null
BEGIN;
UPDATE public.subscriptions SET status = 'release_parent' WHERE id = 1;
SAVEPOINT release_me;
UPDATE public.subscriptions SET status = 'release_child' WHERE id = 2;
RELEASE SAVEPOINT release_me;
ROLLBACK;
SQL
assert_baseline
printf 'SAVEPOINT RELEASE lifecycle probe: PASS\n'

printf '\n--- CC-020 / CC-021 / CC-022: state-transition authority ---\n'
printf 'These use the canonical docs/test-plan.md IDs, not legacy experiment labels.\n'

printf '\nCC-020 allowed role transition:\n'
reset_users_fixture
set +e
ccst020_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.users SET role = 'moderator' WHERE id = 1;
SELECT 'CCST020_STATE:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
COMMIT;
SQL
)"
set -e
[[ "$ccst020_output" == *"UPDATE 1"* ]] || \
    fail "CC-020 allowed transition did not update one row"
[[ "$ccst020_output" == *"CCST020_STATE:t|1|f"* ]] || \
    fail "CC-020 allowed transition did not consume exactly one row-update event (output: $ccst020_output)"
[[ "$ccst020_output" == *"COMMIT"* ]] || \
    fail "CC-020 allowed transition did not commit"
assert_scalar "CC-020 durable role" \
    "SELECT role FROM public.users WHERE id = 1;" \
    "moderator"
assert_scalar "CC-020 untouched sibling roles" \
    "SELECT count(*) FROM public.users WHERE id <> 1 AND role = 'member';" \
    "5"
printf 'CC-020: PASS (member -> moderator committed; one row-update event consumed)\n'

printf '\nCC-021 forbidden admin promotion:\n'
reset_users_fixture
set +e
ccst021_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.users SET role = 'admin' WHERE id = 1;
COMMIT;
SQL
)"
set -e
[[ "$ccst021_output" == *"CommitCap forbidden state transition (* -> admin)"* ]] || \
    fail "CC-021 did not deny the admin transition"
[[ "$ccst021_output" == *"ROLLBACK"* ]] || \
    fail "CC-021 did not abort the top-level transaction"
assert_users_baseline
printf 'CC-021 denial: PASS (forbidden transition denied; no durable change)\n'

printf 'CC-021 savepoint recovery keeps denial sticky:\n'
reset_users_fixture
set +e
ccst021_recovery_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
SAVEPOINT ccst021_sp;
UPDATE public.users SET role = 'admin' WHERE id = 1;
ROLLBACK TO SAVEPOINT ccst021_sp;
SELECT 'CCST021_RECOVERY_STATE:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
COMMIT;
SQL
)"
set -e
[[ "$ccst021_recovery_output" == *"CommitCap forbidden state transition (* -> admin)"* ]] || \
    fail "CC-021 recovery case did not report the forbidden transition denial"
[[ "$ccst021_recovery_output" == *"CCST021_RECOVERY_STATE:t|0|t"* ]] || \
    fail "CC-021 recovery case did not keep denied=true with no consumption (output: $ccst021_recovery_output)"
[[ "$ccst021_recovery_output" == *"CommitCap top-level transaction denied after mutation authority violation"* ]] || \
    fail "CC-021 recovery case did not reject COMMIT at pre-commit"
assert_users_baseline
printf 'CC-021 savepoint recovery: PASS (denial sticky; COMMIT rejected; no durable change)\n'

printf 'CC-021 later protected event is rejected while denied:\n'
reset_users_fixture
set +e
ccst021_later_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
SAVEPOINT ccst021_later_sp;
UPDATE public.users SET role = 'admin' WHERE id = 1;
ROLLBACK TO SAVEPOINT ccst021_later_sp;
UPDATE public.users SET role = 'moderator' WHERE id = 2;
COMMIT;
SQL
)"
set -e
[[ "$ccst021_later_output" == *"CommitCap forbidden state transition (* -> admin)"* ]] || \
    fail "CC-021 later-event case did not report the forbidden transition denial"
[[ "$ccst021_later_output" == *"CommitCap top-level transaction already denied"* ]] || \
    fail "CC-021 later-event case did not reject the following protected event"
assert_users_baseline
printf 'CC-021 later event: PASS (sticky denial rejects further protected mutation)\n'

printf 'CC-021 PL/pgSQL caught denial keeps denial sticky:\n'
reset_users_fixture
set +e
ccst021_caught_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
DO $ccst021$
BEGIN
    BEGIN
        UPDATE public.users SET role = 'admin' WHERE id = 1;
        RAISE EXCEPTION 'expected CommitCap denial';
    EXCEPTION WHEN OTHERS THEN
        IF SQLERRM NOT LIKE 'CommitCap forbidden state transition%' THEN
            RAISE;
        END IF;
        RAISE NOTICE 'CCST021_CAUGHT: %', SQLERRM;
    END;
END
$ccst021$;
COMMIT;
SQL
)"
set -e
[[ "$ccst021_caught_output" == *"CCST021_CAUGHT: CommitCap forbidden state transition (* -> admin)"* ]] || \
    fail "CC-021 caught-exception case did not observe the forbidden transition denial"
[[ "$ccst021_caught_output" == *"CommitCap top-level transaction denied after mutation authority violation"* ]] || \
    fail "CC-021 caught-exception case did not reject COMMIT at pre-commit"
assert_users_baseline
printf 'CC-021 PL/pgSQL caught denial: PASS (denial sticky; COMMIT rejected; no durable change)\n'
printf 'CC-021: PASS (forbidden admin promotion denied; recovery cannot restore commit authority)\n'

printf '\nCC-022 bulk forbidden transition within row-count budget:\n'
reset_users_fixture
set +e
ccst022_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.users
SET role = CASE WHEN id = 3 THEN 'admin' ELSE 'moderator' END
WHERE id BETWEEN 1 AND 3;
COMMIT;
SQL
)"
set -e
[[ "$ccst022_output" == *"CommitCap forbidden state transition (* -> admin)"* ]] || \
    fail "CC-022 bulk transition did not deny the admin row"
if [[ "$ccst022_output" == *"CommitCap mutation budget exceeded"* ]]; then
    fail "CC-022 denial was caused by row-count budget, not transition authority"
fi
[[ "$ccst022_output" == *"ROLLBACK"* ]] || \
    fail "CC-022 did not abort the top-level transaction"
assert_users_baseline
printf 'CC-022: PASS (three-row update denied by transition authority; all rows rolled back)\n'

printf 'CC-022 allowed siblings consume accounting then roll back with the denial:\n'
reset_users_fixture
set +e
ccst022_recovery_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.users SET role = 'moderator' WHERE id IN (1, 2);
SAVEPOINT ccst022_sp;
UPDATE public.users SET role = 'admin' WHERE id = 3;
ROLLBACK TO SAVEPOINT ccst022_sp;
SELECT 'CCST022_RECOVERY_STATE:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
COMMIT;
SQL
)"
set -e
[[ "$ccst022_recovery_output" == *"CCST022_RECOVERY_STATE:t|2|t"* ]] || \
    fail "CC-022 recovery case did not preserve consumed=2 denied=true (output: $ccst022_recovery_output)"
[[ "$ccst022_recovery_output" == *"CommitCap top-level transaction denied after mutation authority violation"* ]] || \
    fail "CC-022 recovery case did not reject COMMIT"
assert_users_baseline
printf 'CC-022 sibling rollback: PASS (allowed events consumed; forbidden row denied; all rolled back)\n'

printf '\nState-transition / row-count composition:\n'
reset_users_fixture
set +e
ccst_budget_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.users SET role = 'moderator' WHERE id = 1;
UPDATE public.users SET role = 'moderator' WHERE id = 2;
UPDATE public.users SET role = 'moderator' WHERE id = 3;
UPDATE public.users SET role = 'moderator' WHERE id = 4;
UPDATE public.users SET role = 'moderator' WHERE id = 5;
UPDATE public.users SET role = 'moderator' WHERE id = 6;
COMMIT;
SQL
)"
set -e
[[ "$ccst_budget_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "row-count composition did not deny the sixth allowed transition event"
assert_users_baseline
printf 'allowed transitions consume the shared transaction-wide budget: PASS\n'

set +e
ccst_cleanup_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.users SET role = 'admin' WHERE id = 1;
COMMIT;
BEGIN;
SELECT 'CCST_CLEANUP_START:' || CASE WHEN active THEN 't' ELSE 'f' END || '|' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
UPDATE public.users SET role = 'moderator' WHERE id BETWEEN 1 AND 2;
COMMIT;
SQL
)"
set -e
[[ "$ccst_cleanup_output" == *"CCST_CLEANUP_START:f|0|f"* ]] || \
    fail "state-transition denial did not clean up before the next transaction"
assert_scalar "state-transition cleanup durable state" \
    "SELECT count(*) FILTER (WHERE role = 'moderator') || ':' || count(*) FILTER (WHERE role = 'member') FROM public.users;" \
    "2:4"
printf 'state-transition lifecycle cleanup after ABORT: PASS\n'
printf 'CC-020 / CC-021 / CC-022 state-transition tests: PASS\n'

# Canonical CC-012 boundary: release an inner allowed subtransaction into its
# parent, then roll back the parent. Both allowed deltas must unwind; denial
# inside the nested frame must instead poison the top-level transaction.
printf '\n--- CC-012 nested savepoints (coverage audit) ---\n'
reset_fixture
writer_psql -v ON_ERROR_STOP=1 <<'SQL' >/dev/null
BEGIN;
SAVEPOINT outer_allowed;
UPDATE public.subscriptions SET status = 'cc012_rolled' WHERE id BETWEEN 1 AND 2;
SAVEPOINT inner_allowed;
UPDATE public.subscriptions SET status = 'cc012_rolled' WHERE id BETWEEN 3 AND 4;
RELEASE SAVEPOINT inner_allowed;
ROLLBACK TO SAVEPOINT outer_allowed;
UPDATE public.subscriptions SET status = 'cc012_durable' WHERE id BETWEEN 6 AND 10;
COMMIT;
SQL
assert_scalar "CC-012 nested allowed rollback durable rows" \
    "SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions;" \
    "1=baseline,2=baseline,3=baseline,4=baseline,5=baseline,6=cc012_durable,7=cc012_durable,8=cc012_durable,9=cc012_durable,10=cc012_durable"
printf 'CC-012 nested allowed rollback: PASS (inner RELEASE + outer ROLLBACK restored all four events; five replacement updates committed)\n'

reset_fixture
set +e
cc012_denial_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc012_denied' WHERE id BETWEEN 1 AND 5;
SAVEPOINT outer_denial;
SAVEPOINT inner_denial;
UPDATE public.subscriptions SET status = 'cc012_denied' WHERE id = 6;
ROLLBACK TO SAVEPOINT inner_denial;
ROLLBACK TO SAVEPOINT outer_denial;
SELECT 'CC012_DENIED:' || consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_probe();
INSERT INTO public.unprotected_audit (message) VALUES ('cc012_sibling');
COMMIT;
SQL
)"
set -e
[[ "$cc012_denial_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-012 nested denial did not reject sixth event"
[[ "$cc012_denial_output" == *"CC012_DENIED:5|t"* ]] || \
    fail "CC-012 inner and outer rollback erased sticky denial"
[[ "$cc012_denial_output" == *"CommitCap top-level transaction denied after mutation authority violation"* ]] || \
    fail "CC-012 nested denial was not rejected at COMMIT"
assert_baseline
assert_scalar "CC-012 nested denied sibling audit" \
    "SELECT count(*) FROM public.unprotected_audit WHERE message = 'cc012_sibling';" "0"
printf 'CC-012 nested denial: PASS (inner and outer recovery kept denied=true; COMMIT rejected; admin saw baseline and audit=0)\n'

printf 'Additional unsupported-path privilege probes:\n'
cc_denied_attempt coverage_copy_from 42501 "COPY public.subscriptions (id, status) FROM STDIN"
cc_denied_attempt coverage_delete_returning 42501 "DELETE FROM public.subscriptions WHERE id = 1 RETURNING id"
cc_denied_attempt coverage_upsert 42501 "INSERT INTO public.subscriptions (id, status) VALUES (1, 'bad') ON CONFLICT (id) DO UPDATE SET status = EXCLUDED.status"
assert_baseline

# Canonical numeric cases and independent-policy counter tests are in a
# separate sourced script so the historical legacy CC-* namespace above stays
# unchanged. The script uses the same writer/admin sessions and assertions.
# PR #12 keyed the row counters and the numeric metric per protected table;
# its independence cases assert positive forward- and reverse-order policy
# independence (A and B) with exact probe values and fresh-admin oracles.
source "$EXPERIMENT_DIR/numeric_cases.sh"

# Integrated independent-policy acceptance (#11, case C): replaces the
# historical shared-counter known FAIL, which PR #12 fixed by keying counters
# per protected table. Perform permitted operations across all three policies,
# exceed exactly one policy's budget inside a savepoint, recover with ROLLBACK
# TO SAVEPOINT, and verify the denial stays sticky, poisons top-level COMMIT,
# and leaves no durable protected or sibling transactional change. Permitted
# consumption in the other policies must remain independently accounted
# (3|1|40.00), not credited against the violated policy.
printf '\n--- independent-policy individual violation with sibling audit ---\n'
reset_fixture
reset_users_fixture
reset_refunds
set +e
cc_policy_violation_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc_indep_violation' WHERE id BETWEEN 1 AND 3;
UPDATE public.users SET role = 'moderator' WHERE id = 1;
UPDATE public.refunds SET amount = 40.00 WHERE id = 1;
SAVEPOINT exceed_row_policy;
UPDATE public.subscriptions SET status = 'cc_indep_violation' WHERE id BETWEEN 4 AND 6;
ROLLBACK TO SAVEPOINT exceed_row_policy;
SELECT 'CC_POLICY_VIOLATION_STATE:' || subscriptions_consumed || '|' || users_consumed || '|' || refunds_positive_delta || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_policy_probe();
INSERT INTO public.unprotected_audit (message) VALUES ('cc_policy_violation_sibling');
COMMIT;
SQL
)"
set -e
[[ "$cc_policy_violation_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "independent-policy violation did not deny the row-policy excess: $cc_policy_violation_output"
[[ "$cc_policy_violation_output" == *"CC_POLICY_VIOLATION_STATE:3|1|40.00|t"* ]] || \
    fail "independent-policy violation lost independent accounting or sticky denial: $cc_policy_violation_output"
[[ "$cc_policy_violation_output" == *"CommitCap top-level transaction denied after mutation authority violation"* ]] || \
    fail "independent-policy poisoned COMMIT was not rejected"
printf '  writer state: %s\n' \
    "$(printf '%s\n' "$cc_policy_violation_output" | grep -F 'CC_POLICY_VIOLATION_STATE:' | head -n 1)"
printf '  writer final COMMIT: %s\n' \
    "$(printf '%s\n' "$cc_policy_violation_output" | grep -F 'denied after mutation authority violation' | head -n 1)"
assert_baseline
assert_users_baseline
assert_refunds "independent-policy violation poisoned COMMIT" "$refunds_baseline"
assert_scalar "independent-policy violation sibling audit" \
    "SELECT count(*) FROM public.unprotected_audit WHERE message = 'cc_policy_violation_sibling';" "0"
printf 'independent-policy individual violation: PASS (3|1|40.00 independently accounted; sticky denial after ROLLBACK TO; poisoned COMMIT rejected; fresh admin saw all protected baselines and sibling audit=0)\n'

# Issue #34 denial evidence. Each denial shape must expose the policy key and,
# where the mechanism knows it exactly, the captured budget, consumption before
# the attempt and the measured attempted effect. The evidence is emitted from
# backend-local enforcement state; the writer cannot forge it. Every shape ends
# with savepoint recovery and a rejected top-level COMMIT carrying policy-scoped
# ABORTED evidence, and every shape is verified from a fresh trusted-admin
# connection. The values below are exact enforcement values, not inferred ones.
printf '\n--- issue #34 human-readable denial evidence ---\n'

evidence_commit_block() {
    printf '%s\n' "$1" | grep -A3 -F 'CommitCap top-level transaction denied after mutation authority violation' | head -n 4
}

evidence_assert() {
    local label="$1" output="$2" expected
    shift 2
    for expected in "$@"; do
        [[ "$output" == *"$expected"* ]] || \
            fail "$label denial evidence missing [$expected]: $output"
    done
}

printf '\nDenial evidence: broad row-budget violation with savepoint recovery\n'
reset_fixture
set +e
cc_ev_row_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc_evidence_row' WHERE id BETWEEN 1 AND 5;
SAVEPOINT evidence_row;
UPDATE public.subscriptions SET status = 'cc_evidence_row_excess' WHERE id = 6;
ROLLBACK TO SAVEPOINT evidence_row;
COMMIT;
SQL
)"
set -e
evidence_assert "row" "$cc_ev_row_output" \
    'CommitCap mutation budget exceeded (limit 5, attempted 6)' \
    'DETAIL:  CommitCap denied transaction' \
    'policy / metric: subscriptions.rows_updated' \
    'granted: 5' \
    'consumed before attempt: 5' \
    'attempted effect: 6 row-update events' \
    'result: DENIED; top-level COMMIT will be rejected'
cc_ev_row_commit="$(evidence_commit_block "$cc_ev_row_output")"
[[ "$cc_ev_row_commit" == *"policy / metric: subscriptions.rows_updated"* && \
   "$cc_ev_row_commit" == *"result: ABORTED"* ]] || \
    fail "row denied COMMIT did not carry policy-scoped ABORTED evidence: $cc_ev_row_commit"
cc_ev_row_commit_count="$(printf '%s\n' "$cc_ev_row_output" | grep -c '^COMMIT$' || true)"
[[ "$cc_ev_row_commit_count" == "0" ]] || fail "row denial emitted a COMMIT command tag"
assert_baseline
assert_scalar "row denial evidence fresh-admin audit" \
    "SELECT count(*) FROM public.unprotected_audit;" "0"
printf 'row denial evidence: PASS (subscriptions.rows_updated, granted 5, consumed 5, attempted 6; COMMIT ABORTED; fresh admin baseline)\n'

printf '\nDenial evidence: forbidden state transition with savepoint recovery\n'
reset_fixture
reset_users_fixture
set +e
cc_ev_transition_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc_evidence_sibling' WHERE id = 1;
SAVEPOINT evidence_transition;
UPDATE public.users SET role = 'admin' WHERE id = 1;
ROLLBACK TO SAVEPOINT evidence_transition;
COMMIT;
SQL
)"
set -e
evidence_assert "transition" "$cc_ev_transition_output" \
    'CommitCap forbidden state transition (* -> admin)' \
    'DETAIL:  CommitCap denied transaction' \
    'policy / metric: users.role (* -> admin)' \
    'attempted effect: 1 forbidden row transition to admin' \
    'result: DENIED; top-level COMMIT will be rejected'
cc_ev_transition_commit="$(evidence_commit_block "$cc_ev_transition_output")"
[[ "$cc_ev_transition_commit" == *"policy / metric: users.role (* -> admin)"* && \
   "$cc_ev_transition_commit" == *"result: ABORTED"* ]] || \
    fail "transition denied COMMIT did not carry policy-scoped ABORTED evidence: $cc_ev_transition_commit"
assert_baseline
assert_users_baseline
printf 'transition denial evidence: PASS (users.role * -> admin; COMMIT ABORTED; fresh admin baseline rows)\n'

printf '\nDenial evidence: numeric positive-delta violation with savepoint recovery\n'
reset_fixture
reset_refunds
set +e
cc_ev_numeric_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = 'cc_evidence_numeric_sibling' WHERE id = 1;
UPDATE public.refunds SET amount = 80.00 WHERE id = 1;
SAVEPOINT evidence_numeric;
UPDATE public.refunds SET amount = 21.00 WHERE id = 2;
ROLLBACK TO SAVEPOINT evidence_numeric;
COMMIT;
SQL
)"
set -e
evidence_assert "numeric" "$cc_ev_numeric_output" \
    'CommitCap numeric delta budget exceeded' \
    'DETAIL:  CommitCap denied transaction' \
    'policy / metric: refunds.amount positive_delta' \
    'granted: 100.00' \
    'consumed before attempt: 80.00' \
    'attempted effect: +21.00 positive delta' \
    'result: DENIED; top-level COMMIT will be rejected'
cc_ev_numeric_commit="$(evidence_commit_block "$cc_ev_numeric_output")"
[[ "$cc_ev_numeric_commit" == *"policy / metric: refunds.amount positive_delta"* && \
   "$cc_ev_numeric_commit" == *"result: ABORTED"* ]] || \
    fail "numeric denied COMMIT did not carry policy-scoped ABORTED evidence: $cc_ev_numeric_commit"
assert_baseline
assert_refunds "numeric denial evidence poisoned COMMIT" "$refunds_baseline"
printf 'numeric denial evidence: PASS (refunds.amount positive_delta, granted 100.00, consumed 80.00, attempted +21.00; COMMIT ABORTED; fresh admin baseline)\n'

# The writer can change session-visible knobs it may legally set, read its own
# probe state and attempt to change the experiment GUC, but it cannot alter the
# evidence, suppress it, or reach commit. The rejected set_config is an
# autocommit statement, so the later transaction is unaffected.
printf '\nDenial evidence: writer session-state manipulation cannot suppress or bypass evidence\n'
reset_fixture
set +e
cc_ev_tamper_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
SET client_min_messages = 'error';
SET search_path = public;
SELECT set_config('commitcap_native.test_budget', '100', true);
\echo tamper_config_SQLSTATE :SQLSTATE
BEGIN;
UPDATE public.subscriptions SET status = 'cc_evidence_tamper' WHERE id BETWEEN 1 AND 5;
SAVEPOINT tamper_probe;
UPDATE public.subscriptions SET status = 'cc_evidence_tamper' WHERE id = 6;
ROLLBACK TO SAVEPOINT tamper_probe;
SAVEPOINT tamper_other_policy;
UPDATE public.users SET role = 'moderator' WHERE id = 1;
ROLLBACK TO SAVEPOINT tamper_other_policy;
SELECT 'TAMPER_STATE:' || subscriptions_consumed || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_policy_probe();
COMMIT;
\echo tamper_commit_SQLSTATE :SQLSTATE
SQL
)"
set -e
evidence_assert "tamper" "$cc_ev_tamper_output" \
    'tamper_config_SQLSTATE 42501' \
    'CommitCap mutation budget exceeded (limit 5, attempted 6)' \
    'policy / metric: subscriptions.rows_updated' \
    'granted: 5' \
    'consumed before attempt: 5' \
    'attempted effect: 6 row-update events' \
    'CommitCap top-level transaction already denied' \
    'TAMPER_STATE:5|t' \
    'tamper_commit_SQLSTATE 54000'
# The later users-policy event must report the original subscriptions denial,
# not a fabricated or misattributed policy.
cc_ev_tamper_repeat="$(printf '%s\n' "$cc_ev_tamper_output" | grep -A2 -F 'CommitCap top-level transaction already denied' | head -n 3)"
[[ "$cc_ev_tamper_repeat" == *"policy / metric: subscriptions.rows_updated"* ]] || \
    fail "already-denied evidence did not retain the original policy: $cc_ev_tamper_repeat"
cc_ev_tamper_commit="$(evidence_commit_block "$cc_ev_tamper_output")"
[[ "$cc_ev_tamper_commit" == *"policy / metric: subscriptions.rows_updated"* && \
   "$cc_ev_tamper_commit" == *"result: ABORTED"* ]] || \
    fail "tamper denied COMMIT did not carry policy-scoped ABORTED evidence: $cc_ev_tamper_commit"
assert_baseline
assert_users_baseline
printf 'writer state-manipulation evidence: PASS (GUC change denied 42501; client_min_messages/search_path changed; later other-policy event kept the original policy; same evidence and sticky denial; COMMIT 54000; fresh admin baselines)\n'

# A forbidden transition is checked before normal users row accounting. Once
# another policy has already denied this top-level transaction, that path must
# reject as already denied rather than report a new transition as the cause.
printf '\nFirst-cause attribution: row denial remains authoritative across later transition\n'
reset_fixture
reset_users_fixture
reset_refunds
set +e
cc_ev_first_cause_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
INSERT INTO public.unprotected_audit(message) VALUES ('first_cause_sibling');
UPDATE public.subscriptions SET status = 'first_cause_allowed' WHERE id BETWEEN 1 AND 5;
SAVEPOINT first_denial;
UPDATE public.subscriptions SET status = 'first_cause_excess' WHERE id = 6;
\echo first_SQLSTATE :SQLSTATE
ROLLBACK TO SAVEPOINT first_denial;
SAVEPOINT later_transition;
UPDATE public.users SET role = 'admin' WHERE id = 1;
\echo transition_SQLSTATE :SQLSTATE
ROLLBACK TO SAVEPOINT later_transition;
SELECT 'FIRST_CAUSE_STATE:' || subscriptions_consumed || '|' || users_consumed || '|' || refunds_positive_delta || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_policy_probe();
COMMIT;
\echo commit_SQLSTATE :SQLSTATE
SQL
)"
set -e
evidence_assert "first-cause row then transition" "$cc_ev_first_cause_output" \
    'first_SQLSTATE 54000' \
    'CommitCap mutation budget exceeded (limit 5, attempted 6)' \
    'transition_SQLSTATE 54000' \
    'CommitCap top-level transaction already denied' \
    'FIRST_CAUSE_STATE:5|0|0.00|t' \
    'commit_SQLSTATE 54000'
cc_ev_later_transition="$(printf '%s\n' "$cc_ev_first_cause_output" | grep -A3 -F 'CommitCap top-level transaction already denied' | head -n 4)"
[[ "$cc_ev_later_transition" == *'policy / metric: subscriptions.rows_updated'* && \
   "$cc_ev_later_transition" == *'result: DENIED; top-level COMMIT will be rejected'* && \
   "$cc_ev_first_cause_output" != *'policy / metric: users.role (* -> admin)'* ]] || \
    fail "later forbidden transition misattributed the first denial: $cc_ev_first_cause_output"
cc_ev_first_cause_commit="$(evidence_commit_block "$cc_ev_first_cause_output")"
[[ "$cc_ev_first_cause_commit" == *'policy / metric: subscriptions.rows_updated'* && \
   "$cc_ev_first_cause_commit" == *'result: ABORTED'* ]] || \
    fail "first-cause COMMIT evidence lost original row policy: $cc_ev_first_cause_commit"
[[ "$(printf '%s\n' "$cc_ev_first_cause_output" | grep -c '^COMMIT$' || true)" == 0 ]] || \
    fail 'first-cause denied transaction emitted a COMMIT tag'
assert_baseline
assert_users_baseline
assert_refunds 'row then forbidden transition poisoned COMMIT' "$refunds_baseline"
assert_scalar 'first-cause sibling audit durable' \
    'SELECT count(*) FROM public.unprotected_audit;' '0'
printf 'first-cause attribution: row denial remains authoritative across later transition: PASS (immediate and PRE_COMMIT evidence; fresh-admin protected baselines and audit=0)\n'
printf 'denial evidence (issue #34): PASS\n'

lifecycle_logs="$("${COMPOSE[@]}" logs --no-color postgres 2>&1 | grep 'commitcap_native_tx_state lifecycle' || true)"
for required_event in \
    XACT_PRE_COMMIT XACT_COMMIT XACT_ABORT \
    SUBXACT_START SUBXACT_PRE_COMMIT SUBXACT_COMMIT SUBXACT_ABORT
do
    [[ "$lifecycle_logs" == *"event=$required_event"* ]] || \
        fail "lifecycle trace did not contain $required_event"
done

printf '%s\n' '--- callback lifecycle trace ---'
printf '%s\n' "$lifecycle_logs"
printf '%s\n' '--- end callback lifecycle trace ---'
source "$EXPERIMENT_DIR/product_update_cases.sh"
printf 'native transaction-state experiment: all required tests PASS including independent per-policy acceptance in both orders and individual-violation recovery (PG16.4 research fixture)\n'
