#!/usr/bin/env bash
# Disposable PG16.4 acceptance path for the #27/#47 generic UPDATE surface.
set -euo pipefail
export LC_ALL=C

ROOT="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
source "$ROOT/demo/product_plan.sh"
FIXTURE="$ROOT/experiments/native_tx_state"
PIN='postgres@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c'
PROJECT='commitcap_product_demo'
export COMPOSE_PROJECT_NAME="$PROJECT"
COMPOSE=(docker compose -f "$FIXTURE/docker-compose.yml")
WRITER='commitcap_demo_writer'
started=false

fail() { printf 'Product demo FAIL: %s\n' "$*" >&2; exit 1; }
line() {
    [[ $'\n'"$1"$'\n' == *$'\n'"$2"$'\n'* ]] || fail "missing exact line [$2] in: $1"
}
admin_psql() {
    "${COMPOSE[@]}" exec -T -e PGPASSWORD=commitcap_native_admin_experiment_only postgres \
        psql -X -A -t -h 127.0.0.1 -U commitcap_native_admin -d commitcap_native -v ON_ERROR_STOP=1 "$@"
}
writer_psql() {
    "${COMPOSE[@]}" exec -T -e PGPASSWORD=commitcap_demo_writer_experiment_only postgres \
        psql -X -A -t -h 127.0.0.1 -U "$WRITER" -d commitcap_native "$@"
}

project_resources() {
    local containers networks volumes
    containers="$("${COMPOSE[@]}" ps -a -q)" || return 1
    networks="$(docker network ls -q --filter "label=com.docker.compose.project=$PROJECT")" || return 1
    volumes="$(docker volume ls -q --filter "label=com.docker.compose.project=$PROJECT")" || return 1
    [[ -z "$containers" && -z "$networks" && -z "$volumes" ]]
}

cleanup() {
    local status=$? ids id mount mounts
    local -a owned_volumes=()
    trap - EXIT
    if [[ "$started" == true ]]; then
        if ids="$("${COMPOSE[@]}" ps -a -q)"; then
            for id in $ids; do
                if mounts="$(docker inspect --format '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}}{{"\n"}}{{end}}{{end}}' "$id")"; then
                    while IFS= read -r mount; do
                        [[ -z "$mount" ]] || owned_volumes+=("$mount")
                    done <<< "$mounts"
                else
                    printf 'Product demo cleanup: could not inspect owned container %s\n' "$id" >&2
                    status=1
                fi
            done
        else
            printf 'Product demo cleanup: could not enumerate project containers\n' >&2
            status=1
        fi
        if ! "${COMPOSE[@]}" down -v --remove-orphans >/dev/null; then
            printf 'Product demo cleanup: Compose teardown failed\n' >&2
            status=1
        fi
        if ! project_resources; then
            printf 'Product demo cleanup: project containers, volumes or networks remain\n' >&2
            status=1
        fi
        for mount in "${owned_volumes[@]}"; do
            if docker volume inspect "$mount" >/dev/null 2>&1; then
                printf 'Product demo cleanup: volume %s remains\n' "$mount" >&2
                status=1
            fi
        done
        if (( status == 0 )); then
            printf 'Product demo cleanup: PASS\n'
            printf '\nProduct Demo: PASS\n'
        fi
    fi
    printf 'Product Demo exit status: %s\n' "$status"
    exit "$status"
}

install_policy() {
    local table="$1" budget="$2" plan install_sql verify_sql result
    plan="$("$ROOT/commitcap" protect-update --table "public.$table" --budget "$budget" --writer-role "$WRITER")" || fail "could not generate #27 plan for $table"
    install_sql="$(extract_plan_section "$plan" install)" || fail "invalid #27 installation section for $table"
    verify_sql="$(extract_plan_section "$plan" verify)" || fail "invalid #27 verification section for $table"
    admin_psql -c "$install_sql" >/dev/null || fail "trusted-admin installation failed for $table"
    result="$(admin_psql -F '|' <<< "$verify_sql" 2>&1)" || fail "catalog preflight query failed for $table: $result"
    if [[ "$result" == *'|FAIL|'* ]] || ! [[ $'\n'"$result"$'\n' == *$'\nOVERALL|PASS|all catalog and writer checks passed\n'* ]]; then
        fail "catalog preflight did not PASS for $table: $result"
    fi
    printf '  public.%s: PASS (budget %s)\n' "$table" "$budget"
}

snapshot() {
    # A new trusted-admin connection for each oracle; no writer state is used.
    admin_psql -c "SELECT (SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.$TABLE_A) || '|' || (SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.$TABLE_B);"
}
assert_snapshot() {
    local label="$1" expected="$2" observed
    observed="$(snapshot)" || fail "$label: fresh trusted-admin connection failed"
    [[ "$observed" == "$expected" ]] || fail "$label: fresh trusted-admin state [$observed], expected [$expected]"
}
reset_baseline() {
    admin_psql >/dev/null <<SQL
BEGIN;
TRUNCATE public.$TABLE_A, public.$TABLE_B;
INSERT INTO public.$TABLE_A SELECT id, 'baseline' FROM generate_series(1, 8) AS rows(id);
INSERT INTO public.$TABLE_B SELECT id, 'baseline' FROM generate_series(1, 5) AS rows(id);
COMMIT;
SQL
    assert_snapshot 'baseline reset' "$BASELINE"
}

doctor_output="$("$ROOT/commitcap" doctor 2>&1)" || fail "local prerequisites unavailable; run ./commitcap doctor: $doctor_output"
trap cleanup EXIT
if ! project_resources; then
    fail "dedicated Compose project $PROJECT is already occupied or Docker could not be inspected; no resources removed"
fi
docker pull "$PIN" >/dev/null || fail 'could not pull pinned PostgreSQL 16.4 image'
docker tag "$PIN" postgres:16.4-alpine || fail 'could not tag pinned PostgreSQL image'
[[ "$(docker image inspect "$PIN" --format '{{.Id}}')" == "$(docker image inspect postgres:16.4-alpine --format '{{.Id}}')" ]] || fail 'pinned PostgreSQL image digest mismatch'

started=true  # also clean up if Compose only partially starts
"${COMPOSE[@]}" up -d --build --wait >/dev/null
admin_psql < "$ROOT/demo/product_update_setup.sql" >/dev/null
version="$(admin_psql -c 'SHOW server_version;')"
[[ "$version" == '16.4' ]] || fail "expected PostgreSQL 16.4, got [$version]"
legacy_count="$(admin_psql -c "SELECT count(*) FROM pg_class AS c JOIN pg_namespace AS n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname IN ('subscriptions','users','refunds');")"
[[ "$legacy_count" == 0 ]] || fail 'product fixture unexpectedly includes legacy subscriptions/users/refunds tables'

# Bash-generated fixed-shape identifiers; never evaluate user input as SQL.
printf -v suffix '%04x%04x' "$RANDOM" "$RANDOM"
TABLE_A="cc_demo_repair_$suffix"
TABLE_B="cc_demo_flags_$suffix"
[[ "$TABLE_A" =~ ^cc_demo_repair_[0-9a-f]{8}$ && "$TABLE_B" =~ ^cc_demo_flags_[0-9a-f]{8}$ &&
   ${#TABLE_A} -le 63 && ${#TABLE_B} -le 63 ]] || fail 'generated relation name is invalid'
for table in "$TABLE_A" "$TABLE_B"; do
    if grep -Fq -- "$table" "$FIXTURE/commitcap_native_tx_state.c"; then
        fail "generated relation name is compiled into native C: $table"
    fi
done

printf 'CommitCap V0 Research Preview\n\nPostgreSQL:\n%s\n\nwriter:\n%s\n' "$version" "$WRITER"
printf '\nCreating arbitrary demo relations:\npublic.%s\npublic.%s\n' "$TABLE_A" "$TABLE_B"
printf '\nProtection:\nUPDATE row events; transaction-local authority\n'
printf 'public.%s budget: 5\npublic.%s budget: 3\n' "$TABLE_A" "$TABLE_B"

admin_psql >/dev/null <<SQL
CREATE TABLE public.$TABLE_A (id integer PRIMARY KEY, status text NOT NULL);
CREATE TABLE public.$TABLE_B (id integer PRIMARY KEY, status text NOT NULL);
ALTER TABLE public.$TABLE_A OWNER TO commitcap_owner;
ALTER TABLE public.$TABLE_B OWNER TO commitcap_owner;
REVOKE ALL ON public.$TABLE_A, public.$TABLE_B FROM PUBLIC;
GRANT SELECT(id), UPDATE(status) ON public.$TABLE_A, public.$TABLE_B TO $WRITER;
SQL

# In addition to #27 catalog checks, verify LOGIN and the narrow DML grants.
writer_boundary="$(admin_psql <<SQL
SELECT w.rolcanlogin
    AND NOT (w.rolsuper OR w.rolcreatedb OR w.rolcreaterole OR w.rolreplication OR w.rolbypassrls)
    AND has_column_privilege(w.oid, 'public.$TABLE_A', 'id', 'SELECT')
    AND has_column_privilege(w.oid, 'public.$TABLE_B', 'id', 'SELECT')
    AND has_column_privilege(w.oid, 'public.$TABLE_A', 'status', 'UPDATE')
    AND has_column_privilege(w.oid, 'public.$TABLE_B', 'status', 'UPDATE')
    AND NOT has_any_column_privilege(w.oid, 'public.$TABLE_A', 'INSERT')
    AND NOT has_any_column_privilege(w.oid, 'public.$TABLE_B', 'INSERT')
    AND NOT has_table_privilege(w.oid, 'public.$TABLE_A', 'INSERT, DELETE, TRUNCATE')
    AND NOT has_table_privilege(w.oid, 'public.$TABLE_B', 'INSERT, DELETE, TRUNCATE')
    AND NOT has_function_privilege(w.oid, 'commitcap_native.enforce_rows_updated()', 'EXECUTE')
FROM pg_roles AS w WHERE w.rolname='$WRITER';
SQL
)" || fail 'writer trust query failed'
[[ "$writer_boundary" == t ]] || fail "restricted writer LOGIN/privilege boundary invalid [$writer_boundary]"

printf '\nCatalog preflight:\n'
install_policy "$TABLE_A" 5
install_policy "$TABLE_B" 3
printf 'Product demo catalog preflight: PASS\n'

BASELINE='1=baseline,2=baseline,3=baseline,4=baseline,5=baseline,6=baseline,7=baseline,8=baseline|1=baseline,2=baseline,3=baseline,4=baseline,5=baseline'
SAFE_STATE='1=safe,2=safe,3=safe,4=safe,5=safe,6=baseline,7=baseline,8=baseline|1=safe,2=safe,3=safe,4=baseline,5=baseline'
reset_baseline

printf '\nSAFE TRANSACTION\n'
safe="$(writer_psql -v ON_ERROR_STOP=1 2>&1 <<SQL
BEGIN;
UPDATE public.$TABLE_A SET status='safe' WHERE id BETWEEN 1 AND 5;
UPDATE public.$TABLE_B SET status='safe' WHERE id BETWEEN 1 AND 3;
COMMIT;
\echo safe_SQLSTATE :SQLSTATE
SQL
)" || fail "safe writer SQL failed: $safe"
line "$safe" 'UPDATE 5'
line "$safe" 'UPDATE 3'
line "$safe" 'COMMIT'
line "$safe" 'safe_SQLSTATE 00000'
assert_snapshot 'safe COMMIT' "$SAFE_STATE"
printf 'public.%s: 5 / 5 UPDATE row events\n' "$TABLE_A"
printf 'public.%s: 3 / 3 UPDATE row events\n' "$TABLE_B"
printf 'COMMIT: SUCCESS\nFresh trusted-admin durable verification: PASS\n'
printf 'Product demo safe transaction: PASS\n'

reset_baseline
printf '\nDENIED TRANSACTION\n'
denied="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<SQL
BEGIN;
UPDATE public.$TABLE_A SET status='denied_safe' WHERE id BETWEEN 1 AND 5;
UPDATE public.$TABLE_B SET status='rolled_back_sibling' WHERE id=1;
SAVEPOINT after_safe;
UPDATE public.$TABLE_A SET status='denied_excess' WHERE id=6;
\echo sixth_SQLSTATE :SQLSTATE
ROLLBACK TO SAVEPOINT after_safe;
\echo recovery_SQLSTATE :SQLSTATE
SELECT 'RECOVERED_SQL_ALLOWED';
COMMIT;
\echo commit_SQLSTATE :SQLSTATE
SQL
)" || fail "denied writer session failed unexpectedly: $denied"
line "$denied" 'UPDATE 5'
line "$denied" 'sixth_SQLSTATE 54000'
line "$denied" 'recovery_SQLSTATE 00000'
line "$denied" 'RECOVERED_SQL_ALLOWED'
line "$denied" 'commit_SQLSTATE 54000'
line "$denied" "policy / metric: public.$TABLE_A.rows_updated"
line "$denied" 'granted: 5'
line "$denied" 'consumed before attempt: 5'
line "$denied" 'attempted effect: 6 row-update events'
line "$denied" 'result: ABORTED'
[[ "$denied" == *'CommitCap mutation budget exceeded (limit 5, attempted 6)'* &&
   "$denied" == *'CommitCap top-level transaction denied after mutation authority violation'* &&
   "$denied" != *$'\nCOMMIT\n'* ]] || fail "sticky A denial did not reject COMMIT: $denied"
assert_snapshot 'A denial' "$BASELINE"
printf 'policy:\npublic.%s.rows_updated\ngranted:\n5\nconsumed before attempt:\n5\nattempted:\n6\n' "$TABLE_A"
printf 'SQLSTATE: 54000\nimmediate result: DENIED\nattempted savepoint recovery: DENIAL REMAINS STICKY\ntop-level COMMIT: REJECTED\n'
printf 'Fresh trusted-admin durable verification: PASS\nOver-budget protected mutation durable: NO\n'
printf 'Product demo denied transaction: PASS\nProduct demo sticky COMMIT rejection: PASS\n'

# Separate fresh baseline and policy B overrun; A stays within its own budget.
reset_baseline
b_denied="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<SQL
BEGIN;
UPDATE public.$TABLE_A SET status='within_a' WHERE id BETWEEN 1 AND 2;
UPDATE public.$TABLE_B SET status='three_b' WHERE id BETWEEN 1 AND 3;
SAVEPOINT after_three;
UPDATE public.$TABLE_B SET status='four_b' WHERE id=4;
\echo b_fourth_SQLSTATE :SQLSTATE
ROLLBACK TO SAVEPOINT after_three;
\echo b_recovery_SQLSTATE :SQLSTATE
SELECT 'B_RECOVERED_SQL_ALLOWED';
COMMIT;
\echo b_commit_SQLSTATE :SQLSTATE
SQL
)" || fail "second-policy writer session failed unexpectedly: $b_denied"
line "$b_denied" 'UPDATE 2'
line "$b_denied" 'UPDATE 3'
line "$b_denied" 'b_fourth_SQLSTATE 54000'
line "$b_denied" 'b_recovery_SQLSTATE 00000'
line "$b_denied" 'B_RECOVERED_SQL_ALLOWED'
line "$b_denied" 'b_commit_SQLSTATE 54000'
line "$b_denied" "policy / metric: public.$TABLE_B.rows_updated"
line "$b_denied" 'granted: 3'
line "$b_denied" 'consumed before attempt: 3'
line "$b_denied" 'attempted effect: 4 row-update events'
line "$b_denied" 'result: ABORTED'
[[ "$b_denied" == *'CommitCap mutation budget exceeded (limit 3, attempted 4)'* &&
   "$b_denied" == *'CommitCap top-level transaction denied after mutation authority violation'* &&
   "$b_denied" != *$'\nCOMMIT\n'* ]] || fail "policy B denial did not reject COMMIT: $b_denied"
assert_snapshot 'B denial' "$BASELINE"
printf '\nSECOND POLICY\npublic.%s budget: 3; event 4 DENIED; COMMIT REJECTED\n' "$TABLE_B"
printf 'Fresh trusted-admin durable verification: PASS\nProduct demo independent second policy: PASS\n'
printf 'Product demo fresh-admin durability: PASS\n'

printf '\nScope of this V0 Research Preview\n\nProtected:\n'
printf '%s\n' '- UPDATE row events' '- per top-level transaction' '- supported local PostgreSQL 16.4 fixture'
printf '\nNot protected:\n'
printf '%s\n' '- INSERT' '- DELETE' '- transaction splitting' \
    '- retries across committed transactions' '- task-wide / cross-transaction authority' \
    '- superuser or table owner' '- partitions / inheritance' '- arbitrary trigger graphs' \
    '- unsupported PostgreSQL versions' '- managed PostgreSQL deployments'
printf '\nSupported release:\nNO\n\nProduction-ready security control:\nNO\n'
