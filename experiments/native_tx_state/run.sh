#!/usr/bin/env bash
set -euo pipefail

EXPERIMENT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE=(docker compose -f "$EXPERIMENT_DIR/docker-compose.yml")
ADMIN_PASSWORD='commitcap_native_admin_experiment_only'
WRITER_PASSWORD='commitcap_writer_experiment_only'
CC_SESSION_DIR=""

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
    assert_scalar "enforcement function owner" \
        "SELECT pg_get_userbyid(p.proowner) FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace WHERE n.nspname = 'commitcap_native' AND p.proname = 'enforce_update_budget';" \
        "commitcap_owner"
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
    printf '  %s: active=%s consumed=%s denied=%s backend_pid=%s\n' \
        "$marker" "$active" "$consumed" "$denied" "$pid"
}

cc_event_count() {
    local pid="$1"

    "${COMPOSE[@]}" logs --no-color postgres 2>&1 | \
        grep -c "commitcap_native_tx_state event pid=$pid " || true
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

cleanup() {
    if [[ -n "${CC_SESSION_DIR:-}" && -d "$CC_SESSION_DIR" ]]; then
        rm -rf "$CC_SESSION_DIR"
    fi
    "${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
}

trap cleanup EXIT
cleanup
"${COMPOSE[@]}" up -d --build --wait
admin_psql -v ON_ERROR_STOP=1 < "$EXPERIMENT_DIR/setup.sql" >/dev/null

printf 'Classification: TEST FIRST\n'
printf 'PostgreSQL: %s\n' "$(admin_psql -At -v ON_ERROR_STOP=1 -c 'SHOW server_version;')"
assert_privilege_envelope

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
[[ "$cc008_output" == *"CommitCap top-level transaction denied after mutation budget violation"* ]] || \
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
[[ "$cc024_output" == *"CommitCap top-level transaction denied after mutation budget violation"* ]] || \
    fail "CC-024 final COMMIT was not rejected"
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
[[ "$cc032_output" == *"CommitCap top-level transaction denied after mutation budget violation"* ]] || \
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
EXECUTE cc025(1, 'cc025');
EXECUTE cc025(2, 'cc025');
EXECUTE cc025(3, 'cc025');
EXECUTE cc025(4, 'cc025');
EXECUTE cc025(5, 'cc025');
EXECUTE cc025(6, 'cc025');
COMMIT;
BEGIN;
UPDATE public.subscriptions SET status = 'cc025_cleanup' WHERE id BETWEEN 1 AND 5;
COMMIT;
SQL
)"
set -e
[[ "$cc025_output" == *"CommitCap mutation budget exceeded (limit 5, attempted 6)"* ]] || \
    fail "CC-025 did not report event-six denial"
cc025_pre_denial_updates="$(printf '%s\n' "$cc025_output" | sed -n '1,/^ERROR:/p' | grep -c '^UPDATE 1$' || true)"
[[ "$cc025_pre_denial_updates" == "5" ]] || \
    fail "CC-025 allowed $cc025_pre_denial_updates prepared executions before denial, expected 5"
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
cc_wait_output a "denied after mutation budget violation" "session A commit rejection"
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
cc_wait_output b "denied after mutation budget violation" "session B commit rejection"
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
printf 'native transaction-state experiment: all required tests PASS\n'
