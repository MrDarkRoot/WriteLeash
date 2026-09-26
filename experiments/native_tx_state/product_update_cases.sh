#!/usr/bin/env bash
# Sourced by the integrated suite and the isolated #47 runner. All assertions
# use a new trusted-admin connection; no writer snapshot is a durability oracle.
product_admin() {
    "${COMPOSE[@]}" exec -T -e PGPASSWORD=commitcap_native_admin_experiment_only postgres \
        psql -X -A -t -h 127.0.0.1 -U commitcap_native_admin -d commitcap_native -v ON_ERROR_STOP=1 "$@"
}
product_writer() {
    "${COMPOSE[@]}" exec -T -e PGPASSWORD=commitcap_writer_experiment_only postgres \
        psql -X -A -t -h 127.0.0.1 -U commitcap_writer -d commitcap_native "$@"
}
product_fail() { printf 'PRODUCT #47 FAIL: %s\n' "$*" >&2; exit 1; }
product_equal() {
    local actual
    actual="$(product_admin -c "$2")"
    [[ "$actual" == "$3" ]] || product_fail "$1: got [$actual], expected [$3]"
}
product_reset() {
    product_admin >/dev/null <<'SQL'
TRUNCATE public.cc_product_alpha, public.cc_product_beta, public.cc_product_zero, cc_product_other.cc_product_alpha, public.unprotected_audit RESTART IDENTITY;
INSERT INTO public.cc_product_alpha SELECT id, 'baseline' FROM generate_series(1,8) AS id;
INSERT INTO public.cc_product_beta SELECT id, 'baseline' FROM generate_series(1,6) AS id;
INSERT INTO public.cc_product_zero VALUES (1, 'baseline');
INSERT INTO cc_product_other.cc_product_alpha SELECT id, 'baseline' FROM generate_series(1,2) AS id;
SQL
}
product_snapshot() {
    product_admin -c "SELECT (SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.cc_product_alpha) || '|' || (SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.cc_product_beta) || '|' || (SELECT status FROM public.cc_product_zero WHERE id=1) || '|' || (SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM cc_product_other.cc_product_alpha) || '|' || (SELECT count(*) FROM public.unprotected_audit);"
}
product_baseline='1=baseline,2=baseline,3=baseline,4=baseline,5=baseline,6=baseline,7=baseline,8=baseline|1=baseline,2=baseline,3=baseline,4=baseline,5=baseline,6=baseline|baseline|1=baseline,2=baseline|0'
product_assert_baseline() {
    local observed
    observed="$(product_snapshot)"
    [[ "$observed" == "$product_baseline" ]] || product_fail "$1 fresh-admin durable state: [$observed]"
}
product_safe() {
    local name="$1" sql="$2" expected="$3" output
    product_reset
    output="$(product_writer -v ON_ERROR_STOP=1 2>&1 <<< "$sql")" || product_fail "$name writer failed: $output"
    [[ "$output" == *'COMMIT'* ]] || product_fail "$name did not commit: $output"
    product_equal "$name fresh admin" "SELECT $expected;" 't'
}
product_denied() {
    local name="$1" sql="$2" metric="$3" budget="$4" consumed="$5" attempted="$6" output
    product_reset
    output="$(product_writer -v ON_ERROR_STOP=0 2>&1 <<SQL
BEGIN;
INSERT INTO public.unprotected_audit(message) VALUES ('rolled_back_sibling');
SAVEPOINT recover;
$sql
\\echo PRODUCT_DENIAL_SQLSTATE :SQLSTATE
ROLLBACK TO SAVEPOINT recover;
SELECT 1;
COMMIT;
\\echo PRODUCT_COMMIT_SQLSTATE :SQLSTATE
SQL
)" || product_fail "$name psql exited unexpectedly: $output"
    [[ "$output" == *"CommitCap mutation budget exceeded (limit $budget, attempted $attempted)"* &&
       "$output" == *"policy / metric: $metric"* &&
       "$output" == *"granted: $budget"* &&
       "$output" == *"consumed before attempt: $consumed"* &&
       "$output" == *"attempted effect: $attempted row-update events"* &&
       "$output" == *'PRODUCT_DENIAL_SQLSTATE 54000'* &&
       "$output" == *'CommitCap top-level transaction denied after mutation authority violation'* &&
       "$output" == *'result: ABORTED'* &&
       "$output" == *'PRODUCT_COMMIT_SQLSTATE 54000'* ]] || product_fail "$name missing denial evidence: $output"
    product_assert_baseline "$name"
}

product_admin >/dev/null <<'SQL'
CREATE TABLE public.cc_product_alpha (id bigint PRIMARY KEY, status text NOT NULL);
CREATE TABLE public.cc_product_beta (id bigint PRIMARY KEY, status text NOT NULL);
CREATE TABLE public.cc_product_zero (id bigint PRIMARY KEY, status text NOT NULL);
CREATE TABLE public.cc_product_invalid (id bigint PRIMARY KEY, status text NOT NULL);
CREATE SCHEMA cc_product_other AUTHORIZATION commitcap_owner;
CREATE TABLE cc_product_other.cc_product_alpha (id bigint PRIMARY KEY, status text NOT NULL);
ALTER TABLE public.cc_product_alpha OWNER TO commitcap_owner;
ALTER TABLE public.cc_product_beta OWNER TO commitcap_owner;
ALTER TABLE public.cc_product_zero OWNER TO commitcap_owner;
ALTER TABLE public.cc_product_invalid OWNER TO commitcap_owner;
ALTER TABLE cc_product_other.cc_product_alpha OWNER TO commitcap_owner;
GRANT USAGE ON SCHEMA cc_product_other TO commitcap_writer;
GRANT SELECT(id), UPDATE(status) ON public.cc_product_beta, public.cc_product_zero, public.cc_product_invalid TO commitcap_writer;
GRANT SELECT(id,status), UPDATE(status) ON public.cc_product_alpha TO commitcap_writer;
GRANT SELECT(id), UPDATE(status) ON cc_product_other.cc_product_alpha TO commitcap_writer;
CREATE TRIGGER cc_alpha BEFORE UPDATE ON public.cc_product_alpha FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
CREATE TRIGGER cc_beta BEFORE UPDATE ON public.cc_product_beta FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('3');
CREATE TRIGGER cc_zero BEFORE UPDATE ON public.cc_product_zero FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('0');
CREATE TRIGGER cc_other BEFORE UPDATE ON cc_product_other.cc_product_alpha FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('1');
INSERT INTO public.cc_product_invalid VALUES (1, 'baseline');
SQL

product_equal 'generic trigger function private to writer' "SELECT has_function_privilege('commitcap_writer','commitcap_native.enforce_rows_updated()','EXECUTE');" 'f'
product_equal 'generic writer nonowner' "SELECT (SELECT relowner FROM pg_class WHERE oid='public.cc_product_alpha'::regclass) <> (SELECT oid FROM pg_roles WHERE rolname='commitcap_writer');" 't'
product_equal 'generic writer narrow grants' "SELECT has_column_privilege('commitcap_writer','public.cc_product_alpha','status','UPDATE') AND NOT has_table_privilege('commitcap_writer','public.cc_product_alpha','TRIGGER') AND NOT has_table_privilege('commitcap_writer','public.cc_product_alpha','INSERT') AND NOT has_table_privilege('commitcap_writer','public.cc_product_alpha','DELETE');" 't'

for product_iteration in $(seq 1 "${PRODUCT_REPETITIONS:-1}"); do
    product_safe 'zero events' 'BEGIN; UPDATE public.cc_product_alpha SET status=status WHERE id=999; COMMIT;' \
        "(SELECT count(*) FROM public.cc_product_alpha WHERE status='baseline')=8"
    product_safe 'one event' "BEGIN; UPDATE public.cc_product_alpha SET status='one' WHERE id=1; COMMIT;" \
        "(SELECT count(*) FROM public.cc_product_alpha WHERE status='one')=1"
    product_safe 'five broad events' "BEGIN; UPDATE public.cc_product_alpha SET status='five' WHERE id<=5; COMMIT;" \
        "(SELECT count(*) FROM public.cc_product_alpha WHERE status='five')=5"
    product_safe 'zero budget without a row event' "BEGIN; UPDATE public.cc_product_zero SET status='still_zero' WHERE id=999; COMMIT;" \
        "(SELECT status FROM public.cc_product_zero WHERE id=1)='baseline'"
    product_denied 'broad six' "UPDATE public.cc_product_alpha SET status='six' WHERE id<=6;" \
        'public.cc_product_alpha.rows_updated' 5 5 6
    product_denied 'decomposed six' "UPDATE public.cc_product_alpha SET status='one' WHERE id=1; UPDATE public.cc_product_alpha SET status='two' WHERE id=2; UPDATE public.cc_product_alpha SET status='three' WHERE id=3; UPDATE public.cc_product_alpha SET status='four' WHERE id=4; UPDATE public.cc_product_alpha SET status='five' WHERE id=5; UPDATE public.cc_product_alpha SET status='six' WHERE id=6;" \
        'public.cc_product_alpha.rows_updated' 5 5 6
    product_denied 'repeated same row' "UPDATE public.cc_product_alpha SET status='again' WHERE id=1; UPDATE public.cc_product_alpha SET status='again' WHERE id=1; UPDATE public.cc_product_alpha SET status='again' WHERE id=1; UPDATE public.cc_product_alpha SET status='again' WHERE id=1; UPDATE public.cc_product_alpha SET status='again' WHERE id=1; UPDATE public.cc_product_alpha SET status='again' WHERE id=1;" \
        'public.cc_product_alpha.rows_updated' 5 5 6
    product_denied 'no-op same row' "UPDATE public.cc_product_alpha SET status=status WHERE id=1; UPDATE public.cc_product_alpha SET status=status WHERE id=1; UPDATE public.cc_product_alpha SET status=status WHERE id=1; UPDATE public.cc_product_alpha SET status=status WHERE id=1; UPDATE public.cc_product_alpha SET status=status WHERE id=1; UPDATE public.cc_product_alpha SET status=status WHERE id=1;" \
        'public.cc_product_alpha.rows_updated' 5 5 6
    product_denied 'writable CTE' "WITH changed AS (UPDATE public.cc_product_alpha SET status='cte' WHERE id<=6 RETURNING id) SELECT count(*) FROM changed;" \
        'public.cc_product_alpha.rows_updated' 5 5 6
    product_denied 'EXPLAIN ANALYZE' "EXPLAIN (ANALYZE,COSTS OFF) UPDATE public.cc_product_alpha SET status='plan' WHERE id<=6;" \
        'public.cc_product_alpha.rows_updated' 5 5 6
    product_denied 'prepared' "PREPARE cc_p(bigint) AS UPDATE public.cc_product_alpha SET status='prepared' WHERE id=\$1; EXECUTE cc_p(1); EXECUTE cc_p(2); EXECUTE cc_p(3); EXECUTE cc_p(4); EXECUTE cc_p(5); EXECUTE cc_p(6);" \
        'public.cc_product_alpha.rows_updated' 5 5 6
    product_denied 'zero budget first event' "UPDATE public.cc_product_zero SET status='first' WHERE id=1;" \
        'public.cc_product_zero.rows_updated' 0 0 1
    product_safe 'two independent policies' "BEGIN; UPDATE public.cc_product_alpha SET status='five' WHERE id<=5; UPDATE public.cc_product_beta SET status='three' WHERE id<=3; COMMIT;" \
        "(SELECT count(*) FROM public.cc_product_alpha WHERE status='five')=5 AND (SELECT count(*) FROM public.cc_product_beta WHERE status='three')=3"
    product_denied 'B fourth after A fifth' "UPDATE public.cc_product_alpha SET status='five' WHERE id<=5; UPDATE public.cc_product_beta SET status='three' WHERE id<=3; UPDATE public.cc_product_beta SET status='four' WHERE id=4;" \
        'public.cc_product_beta.rows_updated' 3 3 4
    product_denied 'A sixth after B third' "UPDATE public.cc_product_beta SET status='three' WHERE id<=3; UPDATE public.cc_product_alpha SET status='five' WHERE id<=5; UPDATE public.cc_product_alpha SET status='six' WHERE id=6;" \
        'public.cc_product_alpha.rows_updated' 5 5 6
    product_safe 'same name other schema independent OID' "BEGIN; UPDATE public.cc_product_alpha SET status='five' WHERE id<=5; UPDATE cc_product_other.cc_product_alpha SET status='one' WHERE id=1; COMMIT;" \
        "(SELECT count(*) FROM public.cc_product_alpha WHERE status='five')=5 AND (SELECT count(*) FROM cc_product_other.cc_product_alpha WHERE status='one')=1"
    product_denied 'same name other schema second event' "UPDATE public.cc_product_alpha SET status='five' WHERE id<=5; UPDATE cc_product_other.cc_product_alpha SET status='two' WHERE id<=2;" \
        'cc_product_other.cc_product_alpha.rows_updated' 1 1 2
    product_safe 'nested rollback only A consumption' "BEGIN; SAVEPOINT outer_sp; UPDATE public.cc_product_alpha SET status='rolled' WHERE id<=3; SAVEPOINT inner_sp; UPDATE public.cc_product_beta SET status='rolled' WHERE id<=2; RELEASE SAVEPOINT inner_sp; ROLLBACK TO SAVEPOINT outer_sp; UPDATE public.cc_product_alpha SET status='final' WHERE id<=5; UPDATE public.cc_product_beta SET status='final' WHERE id<=3; COMMIT;" \
        "(SELECT count(*) FROM public.cc_product_alpha WHERE status='final')=5 AND (SELECT count(*) FROM public.cc_product_beta WHERE status='final')=3"
    product_reset
    product_first="$(product_writer -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.cc_product_alpha SET status='allowed' WHERE id=1;
SAVEPOINT first_deny;
UPDATE public.cc_product_beta SET status='bad' WHERE id<=4;
\echo PRODUCT_FIRST :SQLSTATE
ROLLBACK TO SAVEPOINT first_deny;
SAVEPOINT later;
UPDATE public.cc_product_alpha SET status='later' WHERE id<=6;
\echo PRODUCT_LATER :SQLSTATE
ROLLBACK TO SAVEPOINT later;
COMMIT;
\echo PRODUCT_FIRST_COMMIT :SQLSTATE
SQL
)"
    [[ "$product_first" == *'PRODUCT_FIRST 54000'* && "$product_first" == *'PRODUCT_LATER 54000'* &&
       "$product_first" == *'PRODUCT_FIRST_COMMIT 54000'* &&
       "$product_first" == *'policy / metric: public.cc_product_beta.rows_updated'* &&
       "$product_first" != *'policy / metric: public.cc_product_alpha.rows_updated'* ]] || product_fail "first cause overwritten: $product_first"
    product_assert_baseline 'first cause across policies'
    product_reset
    product_caught="$(product_writer -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.cc_product_beta SET status='before' WHERE id=1;
DO $block$ BEGIN BEGIN
 UPDATE public.cc_product_alpha SET status='bad' WHERE id<=6;
 EXCEPTION WHEN SQLSTATE '54000' THEN RAISE NOTICE 'PRODUCT_CAUGHT';
 END; END $block$;
COMMIT;
\echo PRODUCT_CAUGHT_COMMIT :SQLSTATE
SQL
)"
    [[ "$product_caught" == *'PRODUCT_CAUGHT'* && "$product_caught" == *'PRODUCT_CAUGHT_COMMIT 54000'* ]] || product_fail "caught exception: $product_caught"
    product_assert_baseline 'caught exception'
    product_reset
    product_reuse="$(product_writer -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN; SAVEPOINT s;
UPDATE public.cc_product_alpha SET status='bad' WHERE id<=6;
ROLLBACK TO SAVEPOINT s; COMMIT;
BEGIN;
UPDATE public.cc_product_alpha SET status='good' WHERE id<=5;
COMMIT;
SELECT 'PRODUCT_REUSE:' || count(*) FROM public.cc_product_alpha WHERE id<=5;
SQL
)"
    [[ "$product_reuse" == *'CommitCap mutation budget exceeded'* && "$product_reuse" == *'PRODUCT_REUSE:5'* && "$product_reuse" == *'COMMIT'* ]] || product_fail "same-backend denial reuse: $product_reuse"
    product_equal 'same-backend fresh-admin state' "SELECT count(*) FROM public.cc_product_alpha WHERE status='good';" '5'
    product_reset
    product_safe 'new backend after rollback' "BEGIN; UPDATE public.cc_product_alpha SET status='temp' WHERE id<=5; ROLLBACK; BEGIN; UPDATE public.cc_product_alpha SET status='new' WHERE id<=5; COMMIT;" \
        "(SELECT count(*) FROM public.cc_product_alpha WHERE status='new')=5"
    product_reset
    product_lifecycle="$(product_writer -v ON_ERROR_STOP=1 2>&1 <<'SQL'
SELECT 'PRODUCT_PID:' || pg_backend_pid();
BEGIN;
UPDATE public.cc_product_alpha SET status='first' WHERE id<=5;
COMMIT;
BEGIN;
UPDATE public.cc_product_alpha SET status='rolled' WHERE id<=5;
ROLLBACK;
BEGIN;
UPDATE public.cc_product_alpha SET status='last' WHERE id<=5;
COMMIT;
SELECT 'PRODUCT_PID:' || pg_backend_pid();
SQL
)" || product_fail "same-backend lifecycle: $product_lifecycle"
    product_pid_count="$(printf '%s\n' "$product_lifecycle" | grep -c '^PRODUCT_PID:' || true)"
    product_unique_pids="$(printf '%s\n' "$product_lifecycle" | grep '^PRODUCT_PID:' | sort -u | wc -l)"
    [[ "$product_pid_count" == 2 && "$product_unique_pids" == 1 && "$product_lifecycle" == *'COMMIT'* && "$product_lifecycle" == *'ROLLBACK'* ]] || product_fail "same-backend lifecycle tags: $product_lifecycle"
    product_equal 'same-backend commit/rollback fresh admin' "SELECT (SELECT count(*) FROM public.cc_product_alpha WHERE status='last')=5;" 't'
    printf 'product #47 isolated pass %s/%s (arbitrary tables, sticky COMMIT, fresh-admin oracles)\n' "$product_iteration" "${PRODUCT_REPETITIONS:-1}"
done

# Admin-controlled malformed trigger configurations must fail on invocation,
# including after SAVEPOINT recovery; invalid configuration is not a writer GUC.
for product_bad in 'NO_ARGUMENT' "''" "'-1'" "'01'" "'1.5'" "'garbage'" "'2147483648'" "'99999999999999999999999'" "'5','extra'"; do
    if [[ "$product_bad" == NO_ARGUMENT ]]; then product_bad=''; fi
    product_admin -c "DROP TRIGGER IF EXISTS cc_invalid ON public.cc_product_invalid; CREATE TRIGGER cc_invalid BEFORE UPDATE ON public.cc_product_invalid FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated($product_bad);" >/dev/null
    product_invalid="$(product_writer -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN; SAVEPOINT s;
UPDATE public.cc_product_invalid SET status='invalid' WHERE id=1;
\echo PRODUCT_INVALID :SQLSTATE
ROLLBACK TO SAVEPOINT s;
COMMIT;
\echo PRODUCT_INVALID_COMMIT :SQLSTATE
SQL
)"
    [[ "$product_invalid" == *'PRODUCT_INVALID 22023'* && "$product_invalid" == *'PRODUCT_INVALID_COMMIT 54000'* ]] || product_fail "bad trigger arguments $product_bad: $product_invalid"
    product_equal 'invalid policy fresh-admin baseline' "SELECT status FROM public.cc_product_invalid WHERE id=1;" 'baseline'
done
product_admin -c 'DROP TRIGGER cc_invalid ON public.cc_product_invalid;' >/dev/null
product_admin -c "CREATE TRIGGER cc_invalid BEFORE UPDATE ON public.cc_product_invalid FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('2147483647');" >/dev/null
product_max="$(product_writer -v ON_ERROR_STOP=1 2>&1 <<'SQL'
BEGIN; UPDATE public.cc_product_invalid SET status='max' WHERE id=1; COMMIT;
SQL
)" || product_fail "maximum canonical budget: $product_max"
[[ "$product_max" == *'COMMIT'* ]] || product_fail "maximum budget did not commit: $product_max"
product_equal 'maximum canonical budget fresh admin' "SELECT status FROM public.cc_product_invalid WHERE id=1;" 'max'
product_admin -c 'DROP TRIGGER cc_invalid ON public.cc_product_invalid;' >/dev/null
product_admin -c "UPDATE public.cc_product_invalid SET status='baseline' WHERE id=1;" >/dev/null

product_admin -c "CREATE TRIGGER cc_alpha_duplicate BEFORE UPDATE ON public.cc_product_alpha FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('2');" >/dev/null
product_reset
product_duplicate="$(product_writer -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN; SAVEPOINT s;
UPDATE public.cc_product_alpha SET status='bad' WHERE id=1;
\echo PRODUCT_DUPLICATE :SQLSTATE
ROLLBACK TO SAVEPOINT s; COMMIT;
\echo PRODUCT_DUPLICATE_COMMIT :SQLSTATE
SQL
)"
[[ "$product_duplicate" == *'PRODUCT_DUPLICATE 22023'* && "$product_duplicate" == *'PRODUCT_DUPLICATE_COMMIT 54000'* ]] || product_fail "duplicate policy was not rejected: $product_duplicate"
product_assert_baseline 'duplicate policy'
product_admin -c 'DROP TRIGGER cc_alpha_duplicate ON public.cc_product_alpha;' >/dev/null

# A trusted installer must not silently compose the legacy research row-budget
# trigger with the generic product policy on the same relation.
product_admin -c 'CREATE TRIGGER aa_legacy_budget BEFORE UPDATE ON public.cc_product_alpha FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_update_budget();' >/dev/null
product_reset
product_mixed="$(product_writer -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN; SAVEPOINT s; UPDATE public.cc_product_alpha SET status='bad' WHERE id=1;
\echo PRODUCT_MIXED :SQLSTATE
ROLLBACK TO SAVEPOINT s; COMMIT;
\echo PRODUCT_MIXED_COMMIT :SQLSTATE
SQL
)"
[[ "$product_mixed" == *'PRODUCT_MIXED 22023'* && "$product_mixed" == *'PRODUCT_MIXED_COMMIT 54000'* ]] || product_fail "legacy row-budget trigger composed with product policy: $product_mixed"
product_assert_baseline 'legacy/product conflict'
product_admin -c 'DROP TRIGGER aa_legacy_budget ON public.cc_product_alpha;' >/dev/null

for product_attack in \
    'DROP TRIGGER cc_alpha ON public.cc_product_alpha' \
    'ALTER TABLE public.cc_product_alpha DISABLE TRIGGER cc_alpha' \
    'ALTER TABLE public.cc_product_alpha DISABLE TRIGGER ALL' \
    'ALTER TRIGGER cc_alpha ON public.cc_product_alpha RENAME TO cc_writer_budget' \
    'ALTER TABLE public.cc_product_alpha OWNER TO commitcap_writer' \
    'ALTER FUNCTION commitcap_native.enforce_rows_updated() OWNER TO commitcap_writer' \
    "CREATE OR REPLACE FUNCTION commitcap_native.enforce_rows_updated() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RETURN NEW; END'" \
    'CREATE TABLE commitcap_native.writer_policy (id int)' \
    'ALTER TABLE public.cc_product_alpha ENABLE REPLICA TRIGGER cc_alpha' \
    'CREATE TRIGGER cc_writer_budget BEFORE UPDATE ON public.cc_product_alpha FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('\''999'\'')' \
    'ALTER TABLE public.cc_product_alpha RENAME TO writer_owned' \
    'SET session_replication_role = replica'; do
    product_tamper="$(product_writer -v ON_ERROR_STOP=0 2>&1 <<SQL
$product_attack;
\\echo PRODUCT_TAMPER :SQLSTATE
SQL
)"
    [[ "$product_tamper" == *'PRODUCT_TAMPER 42501'* ]] || product_fail "writer tamper [$product_attack]: $product_tamper"
done
product_direct="$(product_admin -v ON_ERROR_STOP=0 2>&1 <<'SQL'
SELECT commitcap_native.enforce_rows_updated();
\echo PRODUCT_DIRECT :SQLSTATE
SQL
)"
[[ "$product_direct" == *'PRODUCT_DIRECT 39P01'* ]] || product_fail "direct function call did not fail closed: $product_direct"

# A trusted admin can define an unsupported shape, but execution must error;
# a zero-row UPDATE does not fire a row trigger and cannot validate its shape.
product_admin -c "CREATE TRIGGER cc_wrong_time AFTER UPDATE ON public.cc_product_invalid FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('1');" >/dev/null
product_wrong="$(product_writer -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN; SAVEPOINT s; UPDATE public.cc_product_invalid SET status='wrong' WHERE id=1;
\echo PRODUCT_WRONG :SQLSTATE
ROLLBACK TO SAVEPOINT s; COMMIT;
\echo PRODUCT_WRONG_COMMIT :SQLSTATE
SQL
)"
[[ "$product_wrong" == *'PRODUCT_WRONG 39P01'* && "$product_wrong" == *'PRODUCT_WRONG_COMMIT 54000'* ]] || product_fail "wrong timing: $product_wrong"
product_admin -c 'DROP TRIGGER cc_wrong_time ON public.cc_product_invalid;' >/dev/null
product_admin -c "CREATE TRIGGER cc_wrong_level AFTER UPDATE ON public.cc_product_invalid FOR EACH STATEMENT EXECUTE FUNCTION commitcap_native.enforce_rows_updated('1');" >/dev/null
product_wrong="$(product_writer -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN; SAVEPOINT s; UPDATE public.cc_product_invalid SET status='wrong' WHERE id=1;
\echo PRODUCT_WRONG :SQLSTATE
ROLLBACK TO SAVEPOINT s; COMMIT;
\echo PRODUCT_WRONG_COMMIT :SQLSTATE
SQL
)"
[[ "$product_wrong" == *'PRODUCT_WRONG 39P01'* && "$product_wrong" == *'PRODUCT_WRONG_COMMIT 54000'* ]] || product_fail "wrong level: $product_wrong"
product_admin -c 'DROP TRIGGER cc_wrong_level ON public.cc_product_invalid;' >/dev/null
product_admin -c "CREATE TRIGGER cc_wrong_op BEFORE INSERT ON public.cc_product_invalid FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('1');" >/dev/null
product_wrong="$(product_admin -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN; SAVEPOINT s; INSERT INTO public.cc_product_invalid VALUES (2,'wrong');
\echo PRODUCT_WRONG :SQLSTATE
ROLLBACK TO SAVEPOINT s; COMMIT;
\echo PRODUCT_WRONG_COMMIT :SQLSTATE
SQL
)"
[[ "$product_wrong" == *'PRODUCT_WRONG 39P01'* && "$product_wrong" == *'PRODUCT_WRONG_COMMIT 54000'* ]] || product_fail "wrong operation: $product_wrong"
product_admin -c 'DROP TRIGGER cc_wrong_op ON public.cc_product_invalid;' >/dev/null
product_equal 'wrong shape fresh-admin baseline' "SELECT count(*) FROM public.cc_product_invalid WHERE status='baseline';" '1'
for product_conditional in \
    "CREATE TRIGGER cc_cond BEFORE UPDATE OF status ON public.cc_product_invalid FOR EACH ROW EXECUTE FUNCTION commitcap_native.enforce_rows_updated('1')" \
    "CREATE TRIGGER cc_cond BEFORE UPDATE ON public.cc_product_invalid FOR EACH ROW WHEN (NEW.status = 'conditional') EXECUTE FUNCTION commitcap_native.enforce_rows_updated('1')"; do
    product_admin -c "$product_conditional;" >/dev/null
    product_wrong="$(product_writer -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN; SAVEPOINT s; UPDATE public.cc_product_invalid SET status='conditional' WHERE id=1;
\echo PRODUCT_CONDITIONAL :SQLSTATE
ROLLBACK TO SAVEPOINT s; COMMIT;
\echo PRODUCT_CONDITIONAL_COMMIT :SQLSTATE
SQL
)"
    [[ "$product_wrong" == *'PRODUCT_CONDITIONAL 22023'* && "$product_wrong" == *'PRODUCT_CONDITIONAL_COMMIT 54000'* ]] || product_fail "conditional configuration: $product_wrong"
    product_equal 'conditional fresh-admin baseline' "SELECT status FROM public.cc_product_invalid WHERE id=1;" 'baseline'
    product_admin -c 'DROP TRIGGER cc_cond ON public.cc_product_invalid;' >/dev/null
done
printf 'product #47: ALL required generic UPDATE security tests PASS\n'
