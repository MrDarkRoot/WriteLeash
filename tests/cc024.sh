#!/usr/bin/env bash
set -euo pipefail

# Requires a clean environment initialized by tests/cc001_cc003.sh.

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
    printf 'HARNESS ERROR: %s\n' "$1" >&2
    exit 2
}

admin_psql -v ON_ERROR_STOP=1 -c \
    "TRUNCATE TABLE commitcap.update_budget_state, public.subscriptions; INSERT INTO public.subscriptions (id, status) SELECT id, 'baseline' FROM generate_series(1, 10) AS ids(id);" \
    >/dev/null

set +e
output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
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
psql_status=$?
set -e

[[ "$output" == *"CC024_CAUGHT: CommitCap mutation budget exceeded"* ]] || \
    fail "the PL/pgSQL handler did not catch the CommitCap denial"
[[ "$output" == *"attempted 6"* ]] || \
    fail "the caught denial did not identify event six"

protected_state="$(admin_psql -At -v ON_ERROR_STOP=1 -c \
    "SELECT count(*) FILTER (WHERE status = 'baseline') || ':' || count(*) FILTER (WHERE status = 'cc024_allowed') || ':' || count(*) FILTER (WHERE status = 'cc024_excess') FROM public.subscriptions;")"
accounting_state="$(admin_psql -At -v ON_ERROR_STOP=1 -c \
    "SELECT count(*) || ':' || COALESCE(max(row_updates), 0) FROM commitcap.update_budget_state;")"
commit_count="$(printf '%s\n' "$output" | grep -c '^COMMIT$' || true)"

printf 'PostgreSQL: %s\n' "$(admin_psql -At -v ON_ERROR_STOP=1 -c 'SHOW server_version;')"
printf 'denial exception: observed and caught (event 6)\n'
printf 'PL/pgSQL exception recovery: %s\n' "$([[ "$output" == *"cc024_after_recovery"* ]] && printf allowed || printf denied)"
printf 'later SQL execution: %s\n' "$([[ "$output" == *"cc024_after_recovery"* ]] && printf allowed || printf denied)"
printf 'final COMMIT command tag count: %s\n' "$commit_count"
printf 'protected durable state (baseline:allowed:excess): %s\n' "$protected_state"
printf 'accounting durable state (rows:max_count): %s\n' "$accounting_state"
printf 'unprotected durable state: not tested\n'
printf 'psql exit status: %s\n' "$psql_status"

if [[ "$commit_count" == "1" && "$protected_state" == "5:5:0" && "$accounting_state" == "1:5" ]]; then
    printf 'CC-024: FAIL (PL/pgSQL exception recovery restored commit authority)\n'
    exit 1
fi

if [[ "$commit_count" == "0" && "$protected_state" == "10:0:0" ]]; then
    printf 'CC-024: PASS (top-level transaction remained denied)\n'
    exit 0
fi

fail "unexpected PL/pgSQL exception outcome"
