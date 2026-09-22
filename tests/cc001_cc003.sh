#!/usr/bin/env bash
set -euo pipefail

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

assert_writer_privileges() {
    local actual
    actual="$(admin_psql -At -v ON_ERROR_STOP=1 -c \
        "SELECT rolsuper || ':' || rolcreatedb || ':' || rolcreaterole || ':' || rolbypassrls FROM pg_roles WHERE rolname = 'commitcap_writer';")"
    [[ "$actual" == "false:false:false:false" ]] || fail "writer role is not minimally privileged: $actual"
}

printf 'PostgreSQL: %s\n' "$(admin_psql -At -v ON_ERROR_STOP=1 -c 'SHOW server_version;')"
assert_writer_privileges
printf 'Roles: commitcap_admin (setup), commitcap_owner (NOLOGIN owner), commitcap_writer (non-superuser writer)\n'
printf 'Writer grants: SELECT(id) and UPDATE(status) on public.subscriptions; EXECUTE on the enforcement trigger function\n'

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
[[ "$cc003_output" == *"ROLLBACK"* ]] || fail "CC-003 did not show transaction rollback"
assert_baseline
printf 'CC-003: PASS (6 statements shared one budget; no protected mutation durable)\n'

printf 'All requested experiments passed. Savepoint and exception-handler behavior was not tested.\n'
