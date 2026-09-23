#!/usr/bin/env bash
set -euo pipefail

EXPERIMENT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE=(docker compose -f "$EXPERIMENT_DIR/docker-compose.yml")
ADMIN_PASSWORD='commitcap_native_admin_experiment_only'
WRITER_PASSWORD='commitcap_writer_experiment_only'

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
        "TRUNCATE TABLE public.subscriptions; INSERT INTO public.subscriptions (id, status) SELECT id, 'baseline' FROM generate_series(1, 10) AS ids(id);" \
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
    assert_scalar "protected relation owner" \
        "SELECT pg_get_userbyid(c.relowner) FROM pg_class AS c JOIN pg_namespace AS n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relname = 'subscriptions';" \
        "commitcap_owner"
    assert_scalar "enforcement function owner" \
        "SELECT pg_get_userbyid(p.proowner) FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace WHERE n.nspname = 'commitcap_native' AND p.proname = 'enforce_update_budget';" \
        "commitcap_owner"

    printf 'privilege-envelope checks: PASS\n'
}

cleanup() {
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
