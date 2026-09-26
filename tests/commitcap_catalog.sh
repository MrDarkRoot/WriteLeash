#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
EXPERIMENT_DIR="$REPO_DIR/experiments/native_tx_state"
COMPOSE=(docker compose -f "$EXPERIMENT_DIR/docker-compose.yml")
export COMPOSE_PROJECT_NAME=commitcap_catalog_27
ADMIN_PASSWORD='commitcap_native_admin_experiment_only'
started=false

fail() {
    printf 'CATALOG TEST FAIL: %s\n' "$1" >&2
    exit 1
}

cleanup() {
    local status=$?
    trap - EXIT
    if [[ "$started" == true ]]; then
        "${COMPOSE[@]}" down -v --remove-orphans >/dev/null || status=1
    fi
    printf 'Catalog preflight test exit status: %s\n' "$status"
    exit "$status"
}

psql_admin() {
    "${COMPOSE[@]}" exec -T -e "PGPASSWORD=$ADMIN_PASSWORD" postgres \
        psql -X -h 127.0.0.1 -U commitcap_native_admin -d commitcap_native -v ON_ERROR_STOP=1 "$@"
}

psql_writer() {
    "${COMPOSE[@]}" exec -T -e PGPASSWORD=commitcap_writer_experiment_only postgres \
        psql -X -h 127.0.0.1 -U commitcap_writer -d commitcap_native -v ON_ERROR_STOP=0 "$@"
}

extract_verification_script() {
    local output="$1"
    local line
    local in_script=false

    while IFS= read -r line; do
        if [[ "$line" == '-- BEGIN TRUSTED-ADMIN VERIFICATION SCRIPT' ]]; then
            in_script=true
            continue
        fi
        if [[ "$line" == '-- END TRUSTED-ADMIN VERIFICATION SCRIPT' ]]; then
            return 0
        fi
        if [[ "$in_script" == true ]]; then
            printf '%s\n' "$line"
        fi
    done <<< "$output"
    fail 'generated verification script markers were missing'
}

extract_install_sql() {
    local line
    local in_sql=false
    local complete=false

    while IFS= read -r line; do
        if [[ "$line" == 'CREATE TRIGGER "commitcap_rows_updated"' ]]; then
            in_sql=true
        fi
        if [[ "$in_sql" == true ]]; then
            printf '%s\n' "$line"
        fi
        if [[ "$in_sql" == true && "$line" == "EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');" ]]; then
            complete=true
            break
        fi
    done <<< "$1"
    [[ "$complete" == true ]] || fail 'generated trusted-admin CREATE TRIGGER was missing'
}

check_catalog() {
    local label="$1"
    local table="$2"
    local role="$3"
    local expected="$4"
    local expected_check="$5"
    local budget="${6:-5}"
    local plan
    local verification_script
    local result

    plan="$("$REPO_DIR/commitcap" protect-update --table "public.$table" --budget "$budget" --writer-role "$role")"
    verification_script="$(extract_verification_script "$plan")"
    result="$(psql_admin -A -t -F '|' <<< "$verification_script")" || fail "$label: catalog query did not execute: $result"
    if [[ "$expected" == 'PASS' ]]; then
        [[ "$result" == *'OVERALL|PASS|'* ]] || fail "$label expected OVERALL PASS, got: $result"
    else
        [[ "$result" == *'OVERALL|FAIL|'* ]] || fail "$label expected OVERALL FAIL, got: $result"
        [[ "$result" == *"$expected_check|FAIL|"* ]] || fail "$label did not fail [$expected_check]: $result"
    fi
    printf '  %-37s %s\n' "$label" "$expected"
}

check_budget_argument() {
    local label="$1"
    local argument="$2"
    local expected="$3"
    local expected_check="$4"

    psql_admin -c 'DROP TRIGGER commitcap_rows_updated ON public.cc_dx_bad_budget;' >/dev/null
    psql_admin -c "CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_bad_budget FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('$argument');" >/dev/null
    check_catalog "$label" cc_dx_bad_budget commitcap_writer "$expected" "$expected_check"
}

check_set_role_denied() {
    local label="$1"
    local role="$2"
    local table="${3:-cc_dx_valid}"
    local switched

    psql_admin -c "GRANT $role TO commitcap_writer;" >/dev/null
    [[ "$(psql_admin -At -c "SELECT pg_has_role('commitcap_writer', '$role', 'SET');")" == t ]] || fail "$label: role is not reachable through SET ROLE"
    switched="$(psql_writer -A -t -v ON_ERROR_STOP=1 <<SQL
BEGIN;
SET ROLE $role;
SELECT current_role;
ROLLBACK;
SQL
)" || fail "$label: writer could not SET ROLE in rollback-only transaction: $switched"
    [[ "$switched" == *"$role"* ]] || fail "$label: did not observe SET ROLE to $role: $switched"
    check_catalog "$label" "$table" commitcap_writer FAIL 'protected writer has no SET-able role memberships'
    psql_admin -c "REVOKE $role FROM commitcap_writer;" >/dev/null
}

trap cleanup EXIT
[[ -z "$("${COMPOSE[@]}" ps -a -q)" ]] || fail "Compose project already in use: $COMPOSE_PROJECT_NAME"
"${COMPOSE[@]}" up -d --build --wait >/dev/null
started=true
psql_admin < "$EXPERIMENT_DIR/setup.sql" >/dev/null
[[ "$(psql_admin -At -c 'SHOW server_version;')" == 16.4* ]] || fail 'catalog fixture is not PostgreSQL 16.4'

psql_admin >/dev/null <<'SQL'
CREATE TABLE public.cc_dx_valid (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_generated (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_replication (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_disabled (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_when (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_update_of (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_duplicate (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_wrong_timing (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_wrong_level (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_wrong_operation (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_wrong_function (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_bad_budget (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_bad_arg_count (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_mixed (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_extra_trigger (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_inherit_parent (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_inherit_child () INHERITS (public.cc_dx_inherit_parent);
CREATE TABLE public.cc_dx_partitioned (id integer, status text NOT NULL DEFAULT 'baseline') PARTITION BY RANGE (id);
CREATE TABLE public.cc_dx_partition PARTITION OF public.cc_dx_partitioned FOR VALUES FROM (0) TO (10);
CREATE TABLE public.cc_dx_writer_owner (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_writer_trigger (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE TABLE public.cc_dx_settableowner (id integer PRIMARY KEY, status text NOT NULL DEFAULT 'baseline');
CREATE ROLE cc_dx_owner_role NOLOGIN;
CREATE ROLE cc_dx_trigger_role NOLOGIN;
CREATE ROLE cc_dx_param_role NOLOGIN;
CREATE ROLE cc_dx_system_role NOLOGIN;
CREATE ROLE cc_dx_schema_role NOLOGIN;
CREATE ROLE cc_dx_harmless NOLOGIN;
CREATE ROLE cc_dx_chain NOLOGIN;

ALTER TABLE public.cc_dx_valid OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_generated OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_replication OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_disabled OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_when OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_update_of OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_duplicate OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_wrong_timing OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_wrong_level OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_wrong_operation OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_wrong_function OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_bad_budget OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_bad_arg_count OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_mixed OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_extra_trigger OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_inherit_parent OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_inherit_child OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_partitioned OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_partition OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_writer_trigger OWNER TO commitcap_owner;
ALTER TABLE public.cc_dx_settableowner OWNER TO cc_dx_owner_role;

CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_valid
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_replication
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('0');
INSERT INTO public.cc_dx_replication VALUES (1, 'baseline');
GRANT SELECT(id), UPDATE(status) ON public.cc_dx_replication TO commitcap_writer;
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_disabled
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
ALTER TABLE public.cc_dx_disabled DISABLE TRIGGER commitcap_rows_updated;
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_when
FOR EACH ROW WHEN (NEW.status <> 'baseline') EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE OF status ON public.cc_dx_update_of
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_duplicate
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
CREATE TRIGGER cc_dx_duplicate_product BEFORE UPDATE ON public.cc_dx_duplicate
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('4');
CREATE TRIGGER commitcap_rows_updated AFTER UPDATE ON public.cc_dx_wrong_timing
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_wrong_level
FOR EACH STATEMENT EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
CREATE TRIGGER commitcap_rows_updated BEFORE INSERT ON public.cc_dx_wrong_operation
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_wrong_function
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_update_budget();
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_bad_budget
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('01');
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_bad_arg_count
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5', 'extra');
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_mixed
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
CREATE TRIGGER cc_dx_mixed_legacy BEFORE UPDATE ON public.cc_dx_mixed
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_update_budget();
CREATE FUNCTION public.cc_dx_noop_trigger() RETURNS trigger
LANGUAGE plpgsql AS $body$ BEGIN RETURN NEW; END $body$;
CREATE TRIGGER cc_dx_extra BEFORE UPDATE ON public.cc_dx_extra_trigger
FOR EACH ROW EXECUTE FUNCTION public.cc_dx_noop_trigger();
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_extra_trigger
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_inherit_parent
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_partitioned
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_writer_owner
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_writer_trigger
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_settableowner
FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');

ALTER TABLE public.cc_dx_writer_owner OWNER TO commitcap_writer;
GRANT TRIGGER ON public.cc_dx_writer_trigger TO commitcap_writer;
CREATE ROLE cc_dx_superuser NOLOGIN SUPERUSER;
CREATE ROLE cc_dx_elevated NOLOGIN CREATEDB CREATEROLE REPLICATION BYPASSRLS;
GRANT TRIGGER ON public.cc_dx_valid TO cc_dx_trigger_role;
GRANT SET ON PARAMETER session_replication_role TO cc_dx_param_role;
GRANT ALTER SYSTEM ON PARAMETER session_replication_role TO cc_dx_system_role;
GRANT CREATE ON SCHEMA commitcap_native TO cc_dx_schema_role;
SQL

printf 'Catalog preflight PostgreSQL 16.4 checks:\n'
generated_plan="$("$REPO_DIR/commitcap" protect-update --table public.cc_dx_generated --budget 5 --writer-role commitcap_writer)"
generated_install="$(extract_install_sql "$generated_plan")"
psql_admin -c "$generated_install" >/dev/null
check_catalog 'generated trigger applied as admin' cc_dx_generated commitcap_writer PASS ''
if psql_admin -c "$generated_install" >/dev/null 2>&1; then
    fail 'repeating generated CREATE TRIGGER silently replaced existing protection'
fi
printf '  %-37s FAIL (explicit name conflict)\n' 'duplicate CREATE TRIGGER'
check_catalog 'valid #47 trigger' cc_dx_valid commitcap_writer PASS ''
check_catalog 'zero budget origin trigger' cc_dx_replication commitcap_writer PASS '' 0
# Demonstrate the failure mode without committing a bypassed mutation: origin
# trigger denies the row, but a writer granted SET can skip it under replica.
origin_result="$(psql_writer -A -t 2>&1 <<'SQL'
BEGIN;
UPDATE public.cc_dx_replication SET status='would_be_denied' WHERE id=1;
ROLLBACK;
SQL
)"
[[ "$origin_result" == *'CommitCap mutation budget exceeded (limit 0, attempted 1)'* ]] || fail "origin UPDATE did not deny the first row event: $origin_result"
psql_admin -c 'GRANT SET ON PARAMETER session_replication_role TO commitcap_writer;' >/dev/null
[[ "$(psql_admin -At -c "SELECT has_parameter_privilege('commitcap_writer', 'session_replication_role', 'SET');")" == t ]] || fail 'direct parameter SET grant was not effective'
replica_result="$(psql_writer -A -t -v ON_ERROR_STOP=1 <<'SQL'
BEGIN;
SET session_replication_role = replica;
UPDATE public.cc_dx_replication SET status='rolled_back_bypass' WHERE id=1;
ROLLBACK;
SQL
)" || fail "writer could not exercise the replica-mode bypass in rollback-only transaction: $replica_result"
[[ "$replica_result" == *'UPDATE 1'* && "$replica_result" != *'CommitCap mutation budget exceeded'* ]] || fail "replica-mode UPDATE did not skip the origin trigger: $replica_result"
[[ "$(psql_admin -At -c 'SELECT status FROM public.cc_dx_replication WHERE id=1;')" == baseline ]] || fail 'replica-mode reproduction changed durable state'
printf '  replica-mode bypass reproduction: origin DENIED, replica UPDATE 1 rolled back; durable baseline PASS\n'
check_catalog 'direct session_replication_role SET' cc_dx_replication commitcap_writer FAIL 'protected writer cannot change session_replication_role' 0
psql_admin -c 'REVOKE SET ON PARAMETER session_replication_role FROM commitcap_writer;' >/dev/null
check_catalog 'parameter grant removed' cc_dx_replication commitcap_writer PASS '' 0
psql_admin -c 'GRANT ALTER SYSTEM ON PARAMETER session_replication_role TO commitcap_writer;' >/dev/null
[[ "$(psql_admin -At -c "SELECT has_parameter_privilege('commitcap_writer', 'session_replication_role', 'ALTER SYSTEM');")" == t ]] || fail 'ALTER SYSTEM parameter grant was not effective'
check_catalog 'direct session_replication_role ALTER SYSTEM' cc_dx_valid commitcap_writer FAIL 'protected writer cannot change session_replication_role'
psql_admin -c 'REVOKE ALTER SYSTEM ON PARAMETER session_replication_role FROM commitcap_writer;' >/dev/null
check_catalog 'ALTER SYSTEM grant removed' cc_dx_valid commitcap_writer PASS ''
check_catalog 'wrong installed budget' cc_dx_valid commitcap_writer FAIL 'installed budget equals reviewed plan' 50
check_catalog 'missing relation' cc_dx_missing commitcap_writer FAIL 'relation exists'
check_catalog 'disabled trigger' cc_dx_disabled commitcap_writer FAIL 'enabled for ordinary writes'
check_catalog 'conditional WHEN trigger' cc_dx_when commitcap_writer FAIL 'unconditional; no WHEN clause'
check_catalog 'UPDATE OF trigger' cc_dx_update_of commitcap_writer FAIL 'all UPDATE columns; not UPDATE OF'
check_catalog 'duplicate product trigger' cc_dx_duplicate commitcap_writer FAIL 'exactly one CommitCap UPDATE trigger'
check_catalog 'wrong timing' cc_dx_wrong_timing commitcap_writer FAIL 'BEFORE UPDATE FOR EACH ROW only'
check_catalog 'wrong level' cc_dx_wrong_level commitcap_writer FAIL 'BEFORE UPDATE FOR EACH ROW only'
check_catalog 'wrong operation' cc_dx_wrong_operation commitcap_writer FAIL 'BEFORE UPDATE FOR EACH ROW only'
check_catalog 'wrong function' cc_dx_wrong_function commitcap_writer FAIL 'exactly one CommitCap UPDATE trigger'
check_catalog 'noncanonical budget argument' cc_dx_bad_budget commitcap_writer FAIL 'canonical budget in 0..2147483647'
check_catalog 'wrong argument count' cc_dx_bad_arg_count commitcap_writer FAIL 'exactly one budget argument'
check_catalog 'mixed product and legacy trigger' cc_dx_mixed commitcap_writer FAIL 'exactly one CommitCap UPDATE trigger'
check_catalog 'additional direct trigger' cc_dx_extra_trigger commitcap_writer FAIL 'no other direct user-defined triggers'
check_catalog 'inheritance parent unsupported' cc_dx_inherit_parent commitcap_writer FAIL 'ordinary table; no partition or inheritance routing'
check_catalog 'inheritance child unsupported' cc_dx_inherit_child commitcap_writer FAIL 'ordinary table; no partition or inheritance routing'
check_catalog 'partitioned parent unsupported' cc_dx_partitioned commitcap_writer FAIL 'ordinary table; no partition or inheritance routing'
check_catalog 'partition child unsupported' cc_dx_partition commitcap_writer FAIL 'ordinary table; no partition or inheritance routing'
check_catalog 'writer owns table' cc_dx_writer_owner commitcap_writer FAIL 'protected writer is not table owner'
check_catalog 'writer has TRIGGER privilege' cc_dx_writer_trigger commitcap_writer FAIL 'protected writer lacks TRIGGER privilege'
check_catalog 'writer is superuser' cc_dx_valid cc_dx_superuser FAIL 'protected writer is not superuser'
check_catalog 'writer has dangerous attributes' cc_dx_valid cc_dx_elevated FAIL 'protected writer has no dangerous role attributes'
psql_admin -c 'GRANT CREATE ON SCHEMA commitcap_native TO commitcap_writer;' >/dev/null
check_catalog 'writer can CREATE trusted schema' cc_dx_valid commitcap_writer FAIL 'protected writer cannot CREATE in CommitCap schema'

psql_admin -c 'REVOKE CREATE ON SCHEMA commitcap_native FROM commitcap_writer;' >/dev/null
psql_admin -c "DROP TRIGGER commitcap_rows_updated ON public.cc_dx_bad_budget; CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_bad_budget FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('0');" >/dev/null
check_catalog 'zero budget argument' cc_dx_bad_budget commitcap_writer PASS '' 0
psql_admin -c "DROP TRIGGER commitcap_rows_updated ON public.cc_dx_bad_budget; CREATE TRIGGER commitcap_rows_updated BEFORE UPDATE ON public.cc_dx_bad_budget FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('2147483647');" >/dev/null
check_catalog 'maximum budget argument' cc_dx_bad_budget commitcap_writer PASS '' 2147483647
check_budget_argument 'negative budget argument' '-1' FAIL 'canonical budget in 0..2147483647'
check_budget_argument 'overflow budget argument' '2147483648' FAIL 'canonical budget in 0..2147483647'
check_budget_argument 'huge budget argument' '999999999999999999999999999999999999' FAIL 'canonical budget in 0..2147483647'
check_budget_argument 'non-numeric budget argument' 'garbage' FAIL 'canonical budget in 0..2147483647'
check_set_role_denied 'SET role with parameter SET' cc_dx_param_role
check_set_role_denied 'SET role with parameter ALTER SYSTEM' cc_dx_system_role
check_set_role_denied 'SET role with table TRIGGER' cc_dx_trigger_role
check_set_role_denied 'SET role with table ownership' cc_dx_owner_role cc_dx_settableowner
check_set_role_denied 'SET role with admin attributes' cc_dx_elevated
check_set_role_denied 'SET role with trusted schema CREATE' cc_dx_schema_role
check_set_role_denied 'SET trusted CommitCap owner role' commitcap_owner
check_set_role_denied 'SET harmless role (conservative V0)' cc_dx_harmless
psql_admin -c 'GRANT cc_dx_param_role TO cc_dx_chain; GRANT cc_dx_chain TO commitcap_writer;' >/dev/null
[[ "$(psql_admin -At -c "SELECT pg_has_role('commitcap_writer', 'cc_dx_param_role', 'SET');")" == t ]] || fail 'multi-hop SET ROLE path not reachable'
chain_result="$(psql_writer -A -t -v ON_ERROR_STOP=1 <<'SQL'
BEGIN;
SET ROLE cc_dx_param_role;
SELECT current_role, has_parameter_privilege(current_user, 'session_replication_role', 'SET');
ROLLBACK;
SQL
)" || fail "writer could not traverse multi-hop SET ROLE chain: $chain_result"
[[ "$chain_result" == *'cc_dx_param_role|t'* ]] || fail "multi-hop SET ROLE did not reach the privileged role: $chain_result"
check_catalog 'transitive SET ROLE chain' cc_dx_valid commitcap_writer FAIL 'protected writer has no SET-able role memberships'
psql_admin -c 'REVOKE cc_dx_chain FROM commitcap_writer;' >/dev/null
psql_admin -c 'GRANT cc_dx_harmless TO commitcap_writer WITH SET FALSE;' >/dev/null
[[ "$(psql_admin -At -c "SELECT pg_has_role('commitcap_writer', 'cc_dx_harmless', 'SET');")" == f ]] || fail 'SET FALSE membership was unexpectedly SET-able'
check_catalog 'non-SET-able harmless membership' cc_dx_valid commitcap_writer PASS ''
psql_admin -c 'REVOKE cc_dx_harmless FROM commitcap_writer;' >/dev/null
check_catalog 'normal writer after role cases' cc_dx_valid commitcap_writer PASS ''
missing_writer_plan="$("$REPO_DIR/commitcap" protect-update --table public.cc_dx_valid --budget 5)"
missing_writer_script="$(extract_verification_script "$missing_writer_plan")"
missing_writer_result="$(psql_admin -A -t -F '|' <<< "$missing_writer_script")"
[[ "$missing_writer_result" == *'protected writer role supplied and exists|FAIL|'* && "$missing_writer_result" == *'OVERALL|FAIL|'* ]] || fail "missing writer role did not fail preflight: $missing_writer_result"
printf '  %-37s FAIL (writer role omitted)\n' 'missing writer role'

printf 'Catalog preflight tests: PASS\n'
