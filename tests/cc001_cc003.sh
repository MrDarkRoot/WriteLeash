#!/usr/bin/env bash
set -euo pipefail

# Clean-state reproduction:
#   docker compose down -v --remove-orphans
#   docker compose up -d --wait
#   ./tests/cc001_cc003.sh
#   docker compose down -v --remove-orphans

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

ADMIN_PASSWORD='commitcap_admin_experiment_only'
WRITER_PASSWORD='commitcap_writer_experiment_only'

admin_psql() {
    docker compose exec -T \
        -e "PGPASSWORD=$ADMIN_PASSWORD" \
        postgres psql -X -h 127.0.0.1 -U commitcap_admin -d commitcap "$@"
}

writer_psql() {
    docker compose exec -T \
        -e "PGPASSWORD=$WRITER_PASSWORD" \
        postgres psql -X -h 127.0.0.1 -U commitcap_writer -d commitcap "$@"
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

initialize_experiment() {
    local existing_objects

    existing_objects="$(admin_psql -At -v ON_ERROR_STOP=1 -c \
        "SELECT count(*) + CASE WHEN to_regclass('public.subscriptions') IS NULL THEN 0 ELSE 1 END FROM pg_roles WHERE rolname IN ('commitcap_owner', 'commitcap_writer');")"

    if [[ "$existing_objects" != "0" ]]; then
        fail "experiment state already exists; run: docker compose down -v --remove-orphans"
    fi

    admin_psql -v ON_ERROR_STOP=1 < sql/001_phase0_experiment.sql >/dev/null
}

reset_fixture() {
    admin_psql -v ON_ERROR_STOP=1 -c \
        "TRUNCATE TABLE commitcap.update_budget_state, public.subscriptions; INSERT INTO public.subscriptions (id, status) SELECT id, 'baseline' FROM generate_series(1, 10) AS ids(id);" \
        >/dev/null
}

assert_state() {
    local expected="$1"
    local actual
    actual="$(admin_psql -At -v ON_ERROR_STOP=1 -c \
        "SELECT count(*) FILTER (WHERE status = 'baseline') || ':' || count(*) FILTER (WHERE status = '$expected') FROM public.subscriptions;")"
    [[ "$actual" == "5:5" ]] || fail "CC-001 durable state was $actual, expected 5:5"
}

assert_baseline() {
    local actual
    actual="$(admin_psql -At -v ON_ERROR_STOP=1 -c \
        "SELECT count(*) FILTER (WHERE status = 'baseline') || ':' || count(*) FILTER (WHERE status <> 'baseline') FROM public.subscriptions;")"
    [[ "$actual" == "10:0" ]] || fail "denied transaction left durable state $actual, expected 10:0"
}

assert_privilege_envelope() {
    assert_scalar "writer elevated role attributes" \
        "SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls FROM pg_roles WHERE rolname = 'commitcap_writer';" \
        "f"
    assert_scalar "writer membership in trusted owner" \
        "SELECT pg_has_role('commitcap_writer', 'commitcap_owner', 'MEMBER');" \
        "f"

    assert_scalar "writer CONNECT privilege" \
        "SELECT has_database_privilege('commitcap_writer', 'commitcap', 'CONNECT');" \
        "t"
    assert_scalar "writer TEMPORARY privilege" \
        "SELECT has_database_privilege('commitcap_writer', 'commitcap', 'TEMPORARY');" \
        "f"
    assert_scalar "writer public schema USAGE" \
        "SELECT has_schema_privilege('commitcap_writer', 'public', 'USAGE');" \
        "t"
    assert_scalar "writer public schema CREATE" \
        "SELECT has_schema_privilege('commitcap_writer', 'public', 'CREATE');" \
        "f"
    assert_scalar "writer trusted schema USAGE" \
        "SELECT has_schema_privilege('commitcap_writer', 'commitcap', 'USAGE');" \
        "f"
    assert_scalar "writer trusted schema CREATE" \
        "SELECT has_schema_privilege('commitcap_writer', 'commitcap', 'CREATE');" \
        "f"

    assert_scalar "writer SELECT(id) privilege" \
        "SELECT has_column_privilege('commitcap_writer', 'public.subscriptions', 'id', 'SELECT');" \
        "t"
    assert_scalar "writer UPDATE(status) privilege" \
        "SELECT has_column_privilege('commitcap_writer', 'public.subscriptions', 'status', 'UPDATE');" \
        "t"
    assert_scalar "writer table-wide SELECT privilege" \
        "SELECT has_table_privilege('commitcap_writer', 'public.subscriptions', 'SELECT');" \
        "f"
    assert_scalar "writer table-wide UPDATE privilege" \
        "SELECT has_table_privilege('commitcap_writer', 'public.subscriptions', 'UPDATE');" \
        "f"
    assert_scalar "writer SELECT(status) privilege" \
        "SELECT has_column_privilege('commitcap_writer', 'public.subscriptions', 'status', 'SELECT');" \
        "f"
    assert_scalar "writer UPDATE(id) privilege" \
        "SELECT has_column_privilege('commitcap_writer', 'public.subscriptions', 'id', 'UPDATE');" \
        "f"
    assert_scalar "writer INSERT privilege" \
        "SELECT has_table_privilege('commitcap_writer', 'public.subscriptions', 'INSERT');" \
        "f"
    assert_scalar "writer DELETE privilege" \
        "SELECT has_table_privilege('commitcap_writer', 'public.subscriptions', 'DELETE');" \
        "f"
    assert_scalar "writer TRUNCATE privilege" \
        "SELECT has_table_privilege('commitcap_writer', 'public.subscriptions', 'TRUNCATE');" \
        "f"
    assert_scalar "writer TRIGGER privilege" \
        "SELECT has_table_privilege('commitcap_writer', 'public.subscriptions', 'TRIGGER');" \
        "f"

    assert_scalar "subscriptions owner" \
        "SELECT pg_get_userbyid(c.relowner) FROM pg_class AS c JOIN pg_namespace AS n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relname = 'subscriptions';" \
        "commitcap_owner"
    assert_scalar "accounting-state owner" \
        "SELECT pg_get_userbyid(c.relowner) FROM pg_class AS c JOIN pg_namespace AS n ON n.oid = c.relnamespace WHERE n.nspname = 'commitcap' AND c.relname = 'update_budget_state';" \
        "commitcap_owner"
    assert_scalar "trusted schema owner" \
        "SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname = 'commitcap';" \
        "commitcap_owner"
    assert_scalar "enforcement function owner" \
        "SELECT pg_get_userbyid(p.proowner) FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace WHERE n.nspname = 'commitcap' AND p.proname = 'enforce_update_budget';" \
        "commitcap_owner"

    assert_scalar "writer accounting-state access" \
        "SELECT has_any_column_privilege('commitcap_writer', 'commitcap.update_budget_state', 'SELECT') OR has_any_column_privilege('commitcap_writer', 'commitcap.update_budget_state', 'INSERT') OR has_any_column_privilege('commitcap_writer', 'commitcap.update_budget_state', 'UPDATE') OR has_table_privilege('commitcap_writer', 'commitcap.update_budget_state', 'DELETE') OR has_table_privilege('commitcap_writer', 'commitcap.update_budget_state', 'TRUNCATE');" \
        "f"
    assert_scalar "writer direct enforcement-function EXECUTE" \
        "SELECT has_function_privilege('commitcap_writer', 'commitcap.enforce_update_budget()', 'EXECUTE');" \
        "f"

    printf 'privilege-envelope checks: PASS\n'
}

initialize_experiment
printf 'PostgreSQL: %s\n' "$(admin_psql -At -v ON_ERROR_STOP=1 -c 'SHOW server_version;')"
assert_privilege_envelope
printf 'Roles: commitcap_admin (setup), commitcap_owner (NOLOGIN owner), commitcap_writer (non-superuser writer)\n'
printf 'Writer grants: CONNECT, public USAGE, SELECT(id), and UPDATE(status); no direct enforcement-function EXECUTE\n'

reset_fixture
writer_psql -v ON_ERROR_STOP=1 -c \
    "BEGIN; UPDATE public.subscriptions SET status = 'cc001' WHERE id BETWEEN 1 AND 5; COMMIT;" \
    >/dev/null
assert_state cc001
printf 'CC-001: PASS (5 updates committed and durable)\n'

reset_fixture
set +e
cc002_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = $$cc002$$ WHERE id BETWEEN 1 AND 6;
COMMIT;
SQL
)"
set -e
[[ "$cc002_output" == *"CommitCap mutation budget exceeded"* ]] || fail "CC-002 did not report the budget denial"
[[ "$cc002_output" == *"attempted 6"* ]] || fail "CC-002 denial did not identify the sixth event"
[[ "$cc002_output" == *"ROLLBACK"* ]] || fail "CC-002 did not show transaction rollback"
assert_baseline
printf 'CC-002: PASS (6th row-update event denied; no protected mutation durable)\n'

reset_fixture
set +e
cc003_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status = $$cc003$$ WHERE id = 1;
UPDATE public.subscriptions SET status = $$cc003$$ WHERE id = 2;
UPDATE public.subscriptions SET status = $$cc003$$ WHERE id = 3;
UPDATE public.subscriptions SET status = $$cc003$$ WHERE id = 4;
UPDATE public.subscriptions SET status = $$cc003$$ WHERE id = 5;
UPDATE public.subscriptions SET status = $$cc003$$ WHERE id = 6;
COMMIT;
SQL
)"
set -e
[[ "$cc003_output" == *"CommitCap mutation budget exceeded"* ]] || fail "CC-003 did not report the cumulative budget denial"
[[ "$cc003_output" == *"attempted 6"* ]] || fail "CC-003 denial did not identify the sixth event"
cc003_success_count="$(printf '%s\n' "$cc003_output" | grep -c '^UPDATE 1$' || true)"
[[ "$cc003_success_count" == "5" ]] || fail "CC-003 completed $cc003_success_count statements before denial, expected 5"
[[ "$cc003_output" == *"ROLLBACK"* ]] || fail "CC-003 did not show transaction rollback"
assert_baseline
printf 'CC-003: PASS (6 statements shared one budget; no protected mutation durable)\n'

printf 'All requested experiments passed. Savepoint and exception-handler behavior was not tested.\n'
