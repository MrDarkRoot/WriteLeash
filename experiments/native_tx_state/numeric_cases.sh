# Sourced by run.sh; canonical CC-030..033 (not legacy row-budget CC-030..033).
reset_refunds() {
    admin_psql -v ON_ERROR_STOP=1 -c \
        "TRUNCATE TABLE public.refunds; INSERT INTO public.refunds (id, customer_id, amount) SELECT id, 10, 0.00 FROM generate_series(1, 8) AS ids(id);" >/dev/null
}

assert_refunds() {
    local label="$1" expected="$2"
    assert_scalar "$label fresh-admin refunds" \
        "SELECT string_agg(id || '=' || amount, ',' ORDER BY id) FROM public.refunds;" "$expected"
}

refunds_baseline='1=0.00,2=0.00,3=0.00,4=0.00,5=0.00,6=0.00,7=0.00,8=0.00'
refunds_90='1=30.00,2=20.00,3=40.00,4=0.00,5=0.00,6=0.00,7=0.00,8=0.00'
refunds_100='1=100.00,2=0.00,3=0.00,4=0.00,5=0.00,6=0.00,7=0.00,8=0.00'

numeric_state() {
    # For use in writer SQL: subscriptions|users|refunds positive|denied.
    printf "SELECT 'NUMERIC_STATE:' || subscriptions_consumed || '|' || users_consumed || '|' || refunds_positive_delta || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_policy_probe();"
}

assert_numeric_denial() {
    local label="$1" output="$2" message="$3"
    [[ "$output" == *"$message"* ]] || fail "$label missing immediate denial: $output"
    [[ "$output" == *"ROLLBACK"* || "$output" == *"CommitCap top-level transaction denied after mutation authority violation"* ]] || \
        fail "$label did not abort at COMMIT: $output"
    assert_refunds "$label" "$refunds_baseline"
    printf '%s: PASS (denied; fresh admin observed all eight baseline amounts)\n' "$label"
}

printf '\n--- independent transaction-local policy counters ---\n'
assert_scalar 'numeric writer privilege envelope' \
    "SELECT has_column_privilege('commitcap_writer','public.refunds','id','SELECT') AND has_column_privilege('commitcap_writer','public.refunds','amount','UPDATE') AND NOT has_table_privilege('commitcap_writer','public.refunds','INSERT') AND NOT has_table_privilege('commitcap_writer','public.refunds','DELETE') AND NOT has_table_privilege('commitcap_writer','public.refunds','TRUNCATE') AND NOT has_table_privilege('commitcap_writer','public.refunds','TRIGGER');" 't'
assert_scalar 'numeric trusted owner' \
    "SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid='public.refunds'::regclass;" 'commitcap_owner'
assert_scalar 'numeric trigger installed' \
    "SELECT tgenabled FROM pg_trigger WHERE tgname='refunds_positive_delta';" 'O'
assert_scalar 'numeric enforcement function not callable by writer' \
    "SELECT has_function_privilege('commitcap_writer','commitcap_native.enforce_refund_delta()','EXECUTE');" 'f'
assert_scalar 'numeric probe SECURITY INVOKER' \
    "SELECT prosecdef FROM pg_proc WHERE oid='commitcap_probe.cc_native_policy_probe()'::regprocedure;" 'f'

reset_fixture; reset_users_fixture; reset_refunds
independent_forward="$(writer_psql -v ON_ERROR_STOP=1 <<SQL
BEGIN;
UPDATE public.subscriptions SET status='independent' WHERE id BETWEEN 1 AND 5;
UPDATE public.users SET role='moderator' WHERE id=1;
UPDATE public.refunds SET amount=20.00 WHERE id=1;
$(numeric_state)
COMMIT;
SQL
)"
[[ "$independent_forward" == *'NUMERIC_STATE:5|1|20.00|f'* && "$independent_forward" == *'COMMIT'* ]] || fail "independence forward: $independent_forward"
assert_five_updated 'independent forward' independent
assert_scalar 'independent forward users' "SELECT role FROM public.users WHERE id=1;" 'moderator'
assert_scalar 'independent forward refund' "SELECT amount FROM public.refunds WHERE id=1;" '20.00'
printf 'independence subscriptions→users→refunds: PASS (5|1|20.00, three fresh-admin durable oracles)\n'

reset_fixture; reset_users_fixture; reset_refunds
independent_reverse="$(writer_psql -v ON_ERROR_STOP=1 <<SQL
BEGIN;
UPDATE public.refunds SET amount=20.00 WHERE id=1;
UPDATE public.users SET role='moderator' WHERE id=1;
UPDATE public.subscriptions SET status='independent' WHERE id BETWEEN 1 AND 5;
$(numeric_state)
COMMIT;
SQL
)"
[[ "$independent_reverse" == *'NUMERIC_STATE:5|1|20.00|f'* && "$independent_reverse" == *'COMMIT'* ]] || fail "independence reverse: $independent_reverse"
assert_five_updated 'independent reverse' independent
assert_scalar 'independent reverse users' "SELECT role FROM public.users WHERE id=1;" 'moderator'
assert_scalar 'independent reverse refund' "SELECT amount FROM public.refunds WHERE id=1;" '20.00'
printf 'independence refunds→users→subscriptions: PASS (5|1|20.00, three fresh-admin durable oracles)\n'

reset_fixture; reset_users_fixture; reset_refunds
set +e
isolation_deny="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<SQL
BEGIN;
UPDATE public.users SET role='moderator' WHERE id=1;
UPDATE public.refunds SET amount=20.00 WHERE id=1;
UPDATE public.subscriptions SET status='denied' WHERE id BETWEEN 1 AND 5;
SAVEPOINT excess;
UPDATE public.subscriptions SET status='denied' WHERE id=6;
ROLLBACK TO SAVEPOINT excess;
$(numeric_state)
COMMIT;
SQL
)"
set -e
[[ "$isolation_deny" == *'CommitCap mutation budget exceeded (limit 5, attempted 6)'* && "$isolation_deny" == *'NUMERIC_STATE:5|1|20.00|t'* && "$isolation_deny" == *'CommitCap top-level transaction denied after mutation authority violation'* ]] || fail "row policy denial not sticky/isolated: $isolation_deny"
assert_baseline; assert_users_baseline; assert_refunds 'row policy poisoned COMMIT' "$refunds_baseline"
printf 'subscription policy over budget: PASS (sticky denial; fresh admin all three baselines)\n'

reset_fixture; reset_users_fixture; reset_refunds
set +e
users_deny="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status='denied' WHERE id=1;
UPDATE public.users SET role='moderator' WHERE id BETWEEN 1 AND 6;
COMMIT;
SQL
)"
set -e
[[ "$users_deny" == *'CommitCap mutation budget exceeded (limit 5, attempted 6)'* ]] || fail "users policy sixth event not denied: $users_deny"
assert_baseline; assert_users_baseline; assert_refunds 'users policy denial' "$refunds_baseline"
printf 'users policy over budget: PASS (subscription event cannot consume users budget; fresh baselines)\n'

printf '\n--- canonical numeric-delta CC-030/031/032/033 ---\n'
reset_refunds
cc_numeric030="$(writer_psql -v ON_ERROR_STOP=1 <<SQL
BEGIN;
UPDATE public.refunds SET amount=30.00 WHERE id=1;
UPDATE public.refunds SET amount=20.00 WHERE id=2;
UPDATE public.refunds SET amount=40.00 WHERE id=3;
$(numeric_state)
COMMIT;
SQL
)"
[[ "$cc_numeric030" == *'NUMERIC_STATE:0|0|90.00|f'* && "$cc_numeric030" == *'COMMIT'* ]] || fail "canonical CC-030: $cc_numeric030"
assert_refunds 'canonical CC-030' "$refunds_90"
printf 'canonical CC-030: PASS (90.00 COMMIT, exact fresh-admin rows)\n'

reset_refunds
cc_numeric031="$(writer_psql -v ON_ERROR_STOP=1 <<SQL
BEGIN;
UPDATE public.refunds SET amount=100.00 WHERE id=1;
$(numeric_state)
COMMIT;
SQL
)"
[[ "$cc_numeric031" == *'NUMERIC_STATE:0|0|100.00|f'* && "$cc_numeric031" == *'COMMIT'* ]] || fail "canonical CC-031: $cc_numeric031"
assert_refunds 'canonical CC-031' "$refunds_100"
printf 'canonical CC-031: PASS (100.00 COMMIT, exact fresh-admin rows)\n'

reset_refunds
set +e
cc_numeric032="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.refunds SET amount=101.00 WHERE id=1;
COMMIT;
SQL
)"
set -e
assert_numeric_denial 'canonical CC-032 single' "$cc_numeric032" 'CommitCap numeric delta budget exceeded'
reset_refunds
set +e
cc_numeric032="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.refunds SET amount=30.00 WHERE id=1;
UPDATE public.refunds SET amount=20.00 WHERE id=2;
UPDATE public.refunds SET amount=40.00 WHERE id=3;
UPDATE public.refunds SET amount=11.00 WHERE id=4;
COMMIT;
SQL
)"
set -e
assert_numeric_denial 'canonical CC-032 decomposed' "$cc_numeric032" 'CommitCap numeric delta budget exceeded'

reset_refunds
cc_numeric033="$(writer_psql -v ON_ERROR_STOP=1 <<SQL
BEGIN;
UPDATE public.refunds SET amount=80.00 WHERE id=1;
UPDATE public.refunds SET amount=0.00 WHERE id=1;
$(numeric_state)
COMMIT;
SQL
)"
[[ "$cc_numeric033" == *'NUMERIC_STATE:0|0|80.00|f'* && "$cc_numeric033" == *'COMMIT'* ]] || fail "canonical CC-033 gross positive: $cc_numeric033"
assert_refunds 'canonical CC-033' "$refunds_baseline"
printf 'canonical CC-033: PASS (+80/-80 consumed 80.00 in committed transaction; fresh admin amount 0.00)\n'
reset_refunds
set +e
oscillation="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.refunds SET amount=80.00 WHERE id=1;
UPDATE public.refunds SET amount=0.00 WHERE id=1;
UPDATE public.refunds SET amount=21.00 WHERE id=1;
COMMIT;
SQL
)"
set -e
assert_numeric_denial 'CC-033 same-transaction oscillation +21' "$oscillation" 'CommitCap numeric delta budget exceeded'

printf '\n--- numeric boundaries, recovery, alternate SQL paths ---\n'
reset_refunds
no_effect="$(writer_psql -v ON_ERROR_STOP=1 <<SQL
BEGIN;
UPDATE public.refunds SET amount=50.00 WHERE id=1;
UPDATE public.refunds SET amount=10.00 WHERE id=1;
UPDATE public.refunds SET amount=10.00 WHERE id=1;
UPDATE public.refunds SET amount=10.01 WHERE id=1;
UPDATE public.refunds SET amount=10.01 WHERE id=9999;
$(numeric_state)
COMMIT;
SQL
)"
[[ "$no_effect" == *'NUMERIC_STATE:0|0|50.01|f'* && "$no_effect" == *'UPDATE 0'* ]] || fail "negative/no-op/zero-row: $no_effect"
assert_scalar 'numeric negative/no-op fresh amount' 'SELECT amount FROM public.refunds WHERE id=1;' '10.01'
printf 'numeric repeated/negative/no-op/zero-row: PASS (positive total 50.01, fresh admin amount 10.01)\n'

reset_refunds
allowed_rollback="$(writer_psql -v ON_ERROR_STOP=1 <<SQL
BEGIN;
SAVEPOINT outer_allowed;
UPDATE public.refunds SET amount=30.00 WHERE id=1;
SAVEPOINT inner_allowed;
UPDATE public.refunds SET amount=80.00 WHERE id=1;
RELEASE SAVEPOINT inner_allowed;
ROLLBACK TO SAVEPOINT outer_allowed;
UPDATE public.refunds SET amount=100.00 WHERE id=1;
$(numeric_state)
COMMIT;
SQL
)"
[[ "$allowed_rollback" == *'NUMERIC_STATE:0|0|100.00|f'* && "$allowed_rollback" == *'COMMIT'* ]] || fail "nested allowed numeric rollback: $allowed_rollback"
assert_refunds 'nested allowed numeric rollback' "$refunds_100"
printf 'numeric nested allowed rollback: PASS (30+50 undone by outer rollback; 100.00 committed)\n'

reset_fixture; reset_users_fixture; reset_refunds
set +e
denial_recovered="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<SQL
BEGIN;
UPDATE public.subscriptions SET status='poisoned' WHERE id=1;
UPDATE public.users SET role='moderator' WHERE id=1;
INSERT INTO public.unprotected_audit (message) VALUES ('numeric_poisoned');
UPDATE public.refunds SET amount=80.00 WHERE id=1;
SAVEPOINT outer_deny;
SAVEPOINT inner_deny;
UPDATE public.refunds SET amount=101.00 WHERE id=1;
ROLLBACK TO SAVEPOINT inner_deny;
ROLLBACK TO SAVEPOINT outer_deny;
$(numeric_state)
COMMIT;
SQL
)"
set -e
[[ "$denial_recovered" == *'CommitCap numeric delta budget exceeded'* && "$denial_recovered" == *'NUMERIC_STATE:1|1|80.00|t'* && "$denial_recovered" == *'CommitCap top-level transaction denied after mutation authority violation'* ]] || fail "nested numeric denial recovery: $denial_recovered"
assert_baseline; assert_users_baseline; assert_refunds 'nested numeric denial' "$refunds_baseline"
assert_scalar 'numeric poisoned sibling audit' "SELECT count(*) FROM public.unprotected_audit WHERE message='numeric_poisoned';" '0'
printf 'numeric nested denial: PASS (precommit ABORT, fresh admin three protected baselines + sibling audit=0)\n'

reset_refunds
set +e
caught_numeric="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.refunds SET amount=40.00 WHERE id=1;
DO $caught$ BEGIN BEGIN
  UPDATE public.refunds SET amount=101.00 WHERE id=2;
  RAISE EXCEPTION 'expected numeric denial';
EXCEPTION WHEN OTHERS THEN
  IF SQLERRM NOT LIKE 'CommitCap numeric delta budget exceeded%' THEN RAISE; END IF;
  RAISE NOTICE 'CC_NUMERIC_CAUGHT: %', SQLERRM;
END; END $caught$;
COMMIT;
SQL
)"
set -e
[[ "$caught_numeric" == *'CC_NUMERIC_CAUGHT: CommitCap numeric delta budget exceeded'* && "$caught_numeric" == *'CommitCap top-level transaction denied after mutation authority violation'* ]] || fail "caught numeric denial: $caught_numeric"
assert_refunds 'caught numeric denial' "$refunds_baseline"
printf 'numeric caught exception: PASS (sticky precommit rejection; fresh admin baseline)\n'

reset_refunds
alt_sql="$(writer_psql -v ON_ERROR_STOP=1 <<SQL
BEGIN;
WITH changed AS (UPDATE public.refunds SET amount=30.00 WHERE id=1 RETURNING id) SELECT count(*) FROM changed;
PREPARE numeric_allowed(numeric,bigint) AS UPDATE public.refunds SET amount=\$1 WHERE id=\$2;
EXECUTE numeric_allowed(30.00,2);
EXPLAIN (ANALYZE,COSTS OFF) UPDATE public.refunds SET amount=40.00 WHERE id=3;
$(numeric_state)
COMMIT;
SQL
)"
[[ "$alt_sql" == *'NUMERIC_STATE:0|0|100.00|f'* && "$alt_sql" == *'refunds_positive_delta'*'calls=1'* && "$alt_sql" == *'COMMIT'* ]] || fail "CTE/prepared/EXPLAIN numeric accounting: $alt_sql"
assert_scalar 'alternate numeric fresh sum' 'SELECT sum(amount) FROM public.refunds;' '100.00'
printf 'numeric CTE/prepared/EXPLAIN ANALYZE: PASS (one 100.00 budget, fresh-admin sum=100.00)\n'

reset_refunds
bulk_numeric="$(writer_psql -v ON_ERROR_STOP=1 <<SQL
BEGIN;
UPDATE public.refunds SET amount=CASE id WHEN 1 THEN 30.00 WHEN 2 THEN 20.00 ELSE 40.00 END WHERE id BETWEEN 1 AND 3;
$(numeric_state)
COMMIT;
SQL
)"
[[ "$bulk_numeric" == *'NUMERIC_STATE:0|0|90.00|f'* && "$bulk_numeric" == *'UPDATE 3'* && "$bulk_numeric" == *'COMMIT'* ]] || fail "bulk numeric statement: $bulk_numeric"
assert_refunds 'single statement multiple refund rows' "$refunds_90"
printf 'numeric broad single-statement: PASS (90.00 across three rows, fresh admin exact rows)\n'

reset_fixture; reset_users_fixture; reset_refunds
isolated_savepoint="$(writer_psql -v ON_ERROR_STOP=1 <<SQL
BEGIN;
UPDATE public.subscriptions SET status='isolated_savepoint' WHERE id BETWEEN 1 AND 5;
SAVEPOINT rollback_users_and_refunds;
UPDATE public.users SET role='moderator' WHERE id BETWEEN 1 AND 3;
UPDATE public.refunds SET amount=80.00 WHERE id=1;
ROLLBACK TO SAVEPOINT rollback_users_and_refunds;
UPDATE public.users SET role='moderator' WHERE id=1;
UPDATE public.refunds SET amount=100.00 WHERE id=1;
$(numeric_state)
COMMIT;
SQL
)"
[[ "$isolated_savepoint" == *'NUMERIC_STATE:5|1|100.00|f'* && "$isolated_savepoint" == *'COMMIT'* ]] || fail "isolated rollback per policy: $isolated_savepoint"
assert_five_updated 'isolated rollback subscriptions' isolated_savepoint
assert_scalar 'isolated rollback users' "SELECT count(*) FILTER (WHERE role='moderator') || ':' || count(*) FILTER (WHERE role='member') FROM public.users;" '1:5'
assert_refunds 'isolated rollback refunds' "$refunds_100"
printf 'independent subtransaction rollback: PASS (5|1|100.00, fresh admin three durable oracles)\n'

# Every invalid assignment must poison the top-level transaction even when
# the immediate error is recovered through a SAVEPOINT.
for invalid_case in \
    'NULL' "'NaN'::numeric" "'Infinity'::numeric" "'-Infinity'::numeric" \
    '-0.01' '0.001' '10000000000000000.00'
do
    reset_refunds
    set +e
    invalid_output="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<SQL
BEGIN;
UPDATE public.refunds SET amount=10.00 WHERE id=1;
SAVEPOINT invalid_value;
UPDATE public.refunds SET amount=$invalid_case WHERE id=2;
ROLLBACK TO SAVEPOINT invalid_value;
$(numeric_state)
COMMIT;
SQL
)"
    set -e
    [[ "$invalid_output" == *'CommitCap invalid refund amount'* && "$invalid_output" == *'NUMERIC_STATE:0|0|10.00|t'* && "$invalid_output" == *'CommitCap top-level transaction denied after mutation authority violation'* ]] || fail "invalid $invalid_case was not sticky: $invalid_output"
    assert_refunds "invalid $invalid_case" "$refunds_baseline"
    printf 'invalid refund value %s: PASS (sticky deny; fresh baseline)\n' "$invalid_case"
done

# Test-only superuser-only budget configuration: strict exact decimal grammar
# with two fractional places and a 16-digit integer maximum.
for bad_budget in "'-0.01'" "'NaN'" "'Infinity'" "'-Infinity'" "'100.001'" \
    "'10000000000000000.00'" "'1e2'" "'garbage'" "'100'"
do
    cc_config_attempt "numeric_${bad_budget//[^A-Za-z0-9]/_}" \
        "ALTER ROLE commitcap_writer SET commitcap_native.test_numeric_budget = $bad_budget" 22023
done
cc_denied_attempt numeric_budget_writer_set 42501 "SET commitcap_native.test_numeric_budget = '9999999999999999.99'"
cc_denied_attempt numeric_budget_writer_reset 42501 'RESET commitcap_native.test_numeric_budget'
cc_denied_attempt numeric_disable_trigger 42501 'ALTER TABLE public.refunds DISABLE TRIGGER refunds_positive_delta'
cc_denied_attempt numeric_drop_trigger 42501 'DROP TRIGGER refunds_positive_delta ON public.refunds'
cc_denied_attempt numeric_replace_function 42501 "CREATE OR REPLACE FUNCTION commitcap_native.enforce_refund_delta() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RETURN NEW; END'"
cc_denied_attempt numeric_insert 42501 'INSERT INTO public.refunds(id,customer_id,amount) VALUES (99,10,1.00)'
cc_denied_attempt numeric_upsert 42501 "INSERT INTO public.refunds(id,customer_id,amount) VALUES (1,10,101.00) ON CONFLICT (id) DO UPDATE SET amount=EXCLUDED.amount"
cc_denied_attempt numeric_delete 42501 'DELETE FROM public.refunds WHERE id=1'
cc_denied_attempt numeric_truncate 42501 'TRUNCATE public.refunds'

cc_config_set commitcap_native.test_numeric_budget "'9999999999999999.99'"
reset_refunds
max_numeric="$(writer_psql -v ON_ERROR_STOP=1 <<SQL
BEGIN;
UPDATE public.refunds SET amount=9999999999999999.99 WHERE id=1;
$(numeric_state)
COMMIT;
SQL
)"
[[ "$max_numeric" == *'NUMERIC_STATE:0|0|9999999999999999.99|f'* && "$max_numeric" == *'COMMIT'* ]] || fail "maximum numeric boundary: $max_numeric"
assert_scalar 'max numeric fresh amount' 'SELECT amount FROM public.refunds WHERE id=1;' '9999999999999999.99'
printf 'max numeric boundary: PASS (exact 16+2, fresh admin verified)\n'

reset_refunds
set +e
max_over="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.refunds SET amount=9999999999999999.99 WHERE id=1;
UPDATE public.refunds SET amount=0.01 WHERE id=2;
COMMIT;
SQL
)"
set -e
assert_numeric_denial 'max numeric over-budget' "$max_over" 'CommitCap numeric delta budget exceeded'
cc_config_reset commitcap_native.test_numeric_budget
assert_value 'restored numeric test budget' "$(writer_psql -At -v ON_ERROR_STOP=1 -c 'SHOW commitcap_native.test_numeric_budget;')" '100.00'

cc_config_set commitcap_native.test_numeric_budget "'0.00'"
reset_refunds
zero_numeric="$(writer_psql -v ON_ERROR_STOP=1 <<SQL
BEGIN;
UPDATE public.refunds SET amount=0.00 WHERE id=1;
$(numeric_state)
COMMIT;
SQL
)"
[[ "$zero_numeric" == *'NUMERIC_STATE:0|0|0.00|f'* && "$zero_numeric" == *'COMMIT'* ]] || fail "zero numeric budget no-op: $zero_numeric"
assert_refunds 'zero budget no-op' "$refunds_baseline"
reset_refunds
set +e
zero_denial="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.refunds SET amount=0.01 WHERE id=1;
COMMIT;
SQL
)"
set -e
assert_numeric_denial 'zero budget first positive event' "$zero_denial" 'CommitCap numeric delta budget exceeded'
cc_config_reset commitcap_native.test_numeric_budget
printf 'zero numeric budget: PASS (no-op COMMIT, +0.01 denied, fresh admin baselines)\n'

# A denied transaction may be followed by a clean independent one in a reused
# backend. Existing legacy CC-021 covers the persistent-backend property for
# row/transition policies; this checks the numeric counter's own cleanup.
reset_refunds
numeric_reuse="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<SQL
BEGIN;
UPDATE public.refunds SET amount=101.00 WHERE id=1;
COMMIT;
BEGIN;
UPDATE public.refunds SET amount=100.00 WHERE id=1;
$(numeric_state)
COMMIT;
SQL
)"
[[ "$numeric_reuse" == *'CommitCap numeric delta budget exceeded'* && "$numeric_reuse" == *'NUMERIC_STATE:0|0|100.00|f'* && "$numeric_reuse" == *'COMMIT'* ]] || fail "numeric next-transaction cleanup: $numeric_reuse"
assert_refunds 'numeric next transaction' "$refunds_100"
printf 'numeric next-transaction cleanup: PASS (fresh admin amount=100.00)\n'
printf 'canonical numeric CC-030/031/032/033 and approved boundary probes: PASS IN PG16.4 RESEARCH FIXTURE\n'

printf '\n--- numeric-delta concurrent row-lock contention ---\n'
# Two-session regression required by the PR #12 numeric-delta review: READ
# COMMITTED writers contending for one protected refunds row, using the same
# named-pipe session harness as the CC-019/CC-020 row experiments. Lock waits
# are asserted from a separate admin connection through pg_stat_activity and
# pg_blocking_pids(); no asserted state depends on sleeps. Allowed numeric
# events produce no server-log event lines, so exactly-once post-lock trigger
# evaluation is proven by the exact resumed refunds_positive_delta, the single
# UPDATE command tag, unchanged pg_stat_database.deadlocks, and fresh-admin
# durable rows instead of the log event counts used for row events.
assert_value 'numeric contention default numeric budget' \
    "$(writer_psql -At -v ON_ERROR_STOP=1 -c 'SHOW commitcap_native.test_numeric_budget;')" '100.00'

nc_state_assert() {
    local name="$1" marker="$2" expected="$3"
    local line value

    cc_send "$name" "SELECT '$marker:' || subscriptions_consumed || '|' || users_consumed || '|' || refunds_positive_delta || '|' || CASE WHEN denied THEN 't' ELSE 'f' END FROM commitcap_probe.cc_native_policy_probe();"
    cc_wait_output "$name" "$marker:" "numeric policy state $marker"
    line="$(grep -F "$marker:" "$CC_SESSION_DIR/$name.out" | tail -n 1)"
    value="${line##*"$marker:"}"
    [[ "$value" == "$expected" ]] || \
        fail "$marker was $value, expected $expected"
    printf '  %s: subscriptions|users|refunds_positive_delta|denied = %s\n' "$marker" "$value"
}

nc_deadlocks() {
    admin_psql -At -v ON_ERROR_STOP=1 -c \
        "SELECT deadlocks FROM pg_stat_database WHERE datname = current_database();"
}

nc_update1_delta() {
    local name="$1" before="$2"
    local after

    after="$(grep -cFx 'UPDATE 1' "$CC_SESSION_DIR/$name.out" || true)"
    echo $((after - before))
}

rm -rf "$CC_SESSION_DIR"
CC_SESSION_DIR="$(mktemp -d "${TMPDIR:-/tmp}/commitcap_native_numeric_sessions.XXXXXX")"
cc_session_start a
cc_session_start b
cc_session_attach a
cc_session_attach b
cc_probe_assert a NC_START_A f 0 f
cc_probe_assert b NC_START_B f 0 f
cc_a_pid="$(cc_probe_field a NC_PID_A pid)"
cc_b_pid="$(cc_probe_field b NC_PID_B pid)"
[[ "$cc_a_pid" != "$cc_b_pid" ]] || fail "numeric contention sessions shared backend pid $cc_a_pid"
printf 'numeric contention backend A pid: %s\n' "$cc_a_pid"
printf 'numeric contention backend B pid: %s\n' "$cc_b_pid"

for nc_session in a b; do
    cc_send "$nc_session" "SELECT 'NC_ISO_$nc_session:' || current_setting('transaction_isolation');"
    cc_wait_output "$nc_session" "NC_ISO_$nc_session:" "isolation level for numeric session $nc_session"
done
nc_a_iso="$(grep -F 'NC_ISO_a:' "$CC_SESSION_DIR/a.out" | tail -n 1 | cut -d: -f2)"
nc_b_iso="$(grep -F 'NC_ISO_b:' "$CC_SESSION_DIR/b.out" | tail -n 1 | cut -d: -f2)"
[[ "$nc_a_iso" == "read committed" && "$nc_b_iso" == "read committed" ]] || \
    fail "numeric contention isolation was A=$nc_a_iso B=$nc_b_iso, expected read committed for both"
printf 'numeric contention isolation: A=%s B=%s\n' "$nc_a_iso" "$nc_b_iso"

printf '\nnumeric contention scenario A: both sessions update the same refunds row; B waits on the row lock\n'
reset_refunds
nc_deadlocks_before="$(nc_deadlocks)"

cc_send a "BEGIN;"
cc_send a "UPDATE public.refunds SET amount=70.00 WHERE id=1;"
nc_state_assert a NC_A_A1 '0|0|70.00|f'

nc_b_update1_before="$(grep -cFx 'UPDATE 1' "$CC_SESSION_DIR/b.out" || true)"
cc_send b "BEGIN;"
cc_send b "UPDATE public.refunds SET amount=120.00 WHERE id=1;"
cc_wait_blocked "$cc_b_pid" "$cc_a_pid" "numeric contention scenario A"
nc_state_assert a NC_A_A2 '0|0|70.00|f'

nc_a_commits_before="$(cc_count_tag a COMMIT)"
cc_send a "COMMIT;"
cc_sync a NC_A_A_COMMIT
[[ "$(( $(cc_count_tag a COMMIT) - nc_a_commits_before ))" == "1" ]] || \
    fail "numeric contention scenario A: session A did not commit"

cc_sync b NC_A_B_RESUMED
[[ "$(nc_update1_delta b "$nc_b_update1_before")" == "1" ]] || \
    fail "numeric contention scenario A: B contended update did not report exactly one UPDATE tag"
nc_state_assert b NC_A_B1 '0|0|50.00|f'
nc_b_commits_before="$(cc_count_tag b COMMIT)"
cc_send b "COMMIT;"
cc_sync b NC_A_B_COMMIT
[[ "$(( $(cc_count_tag b COMMIT) - nc_b_commits_before ))" == "1" ]] || \
    fail "numeric contention scenario A: session B did not commit"

assert_refunds 'numeric contention scenario A durable' \
    '1=120.00,2=0.00,3=0.00,4=0.00,5=0.00,6=0.00,7=0.00,8=0.00'
cc_probe_assert a NC_A_A_CLEAN f 0 f
cc_probe_assert b NC_A_B_CLEAN f 0 f
nc_deadlocks_after="$(nc_deadlocks)"
printf 'numeric contention scenario A: deadlock count %s -> %s\n' "$nc_deadlocks_before" "$nc_deadlocks_after"
[[ "$nc_deadlocks_after" == "$nc_deadlocks_before" ]] || \
    fail "numeric contention scenario A: deadlock count changed"
printf 'numeric contention scenario A: PASS (verified lock wait; B resumed on committed 70.00 and consumed 50.00; stale OLD 0.00 would have consumed 120.00 and falsely denied; independent 70.00/50.00 accounting; fresh-admin 120.00)\n'

printf '\nnumeric contention scenario B: resumed update exceeds the waiting session numeric budget\n'
reset_refunds
nc_deadlocks_before="$(nc_deadlocks)"

cc_send b "BEGIN;"
cc_send b "UPDATE public.refunds SET amount=50.00 WHERE id=2;"
nc_state_assert b NC_B_B1 '0|0|50.00|f'
cc_send b "SAVEPOINT nc_b_sp;"

cc_send a "BEGIN;"
cc_send a "UPDATE public.refunds SET amount=90.00 WHERE id=1;"
cc_send a "UPDATE public.refunds SET amount=30.00 WHERE id=1;"
nc_state_assert a NC_B_A1 '0|0|90.00|f'

nc_b_update1_before="$(grep -cFx 'UPDATE 1' "$CC_SESSION_DIR/b.out" || true)"
cc_send b "UPDATE public.refunds SET amount=100.00 WHERE id=1;"
cc_wait_blocked "$cc_b_pid" "$cc_a_pid" "numeric contention scenario B"
nc_state_assert a NC_B_A2 '0|0|90.00|f'

nc_a_commits_before="$(cc_count_tag a COMMIT)"
cc_send a "COMMIT;"
cc_sync a NC_B_A_COMMIT
[[ "$(( $(cc_count_tag a COMMIT) - nc_a_commits_before ))" == "1" ]] || \
    fail "numeric contention scenario B: session A did not commit"

cc_wait_output b 'CommitCap numeric delta budget exceeded' 'numeric contention scenario B over-budget denial'
[[ "$(nc_update1_delta b "$nc_b_update1_before")" == "0" ]] || \
    fail "numeric contention scenario B: denied contended update reported a successful UPDATE tag"

cc_send b "ROLLBACK TO SAVEPOINT nc_b_sp;"
nc_state_assert b NC_B_B2 '0|0|50.00|t'

cc_send b "UPDATE public.subscriptions SET status='nc_b_sibling' WHERE id=1;"
cc_wait_output b 'CommitCap top-level transaction already denied' 'numeric contention scenario B sibling rejection'

cc_send b "ROLLBACK TO SAVEPOINT nc_b_sp;"
# The recovered transaction is back in progress, so the sticky denial rejects
# COMMIT at XACT_EVENT_PRE_COMMIT (no COMMIT tag is emitted; this matches the
# CC-024/CC-032 convention) rather than converting to a top-level ROLLBACK.
nc_b_commits_before="$(cc_count_tag b COMMIT)"
cc_send b "COMMIT;"
cc_wait_output b 'CommitCap top-level transaction denied after mutation authority violation' 'numeric contention scenario B COMMIT rejection'
[[ "$(( $(cc_count_tag b COMMIT) - nc_b_commits_before ))" == "0" ]] || \
    fail "numeric contention scenario B: denied transaction emitted a COMMIT tag"

assert_refunds 'numeric contention scenario B durable' \
    '1=30.00,2=0.00,3=0.00,4=0.00,5=0.00,6=0.00,7=0.00,8=0.00'
assert_scalar 'numeric contention scenario B sibling durable' \
    "SELECT count(*) FROM public.subscriptions WHERE status = 'nc_b_sibling';" \
    "0"
cc_probe_assert a NC_B_A_CLEAN f 0 f
cc_probe_assert b NC_B_B_CLEAN f 0 f
nc_deadlocks_after="$(nc_deadlocks)"
printf 'numeric contention scenario B: deadlock count %s -> %s\n' "$nc_deadlocks_before" "$nc_deadlocks_after"
[[ "$nc_deadlocks_after" == "$nc_deadlocks_before" ]] || \
    fail "numeric contention scenario B: deadlock count changed"
printf 'numeric contention scenario B: PASS (post-lock delta 70.00 exceeded remaining 50.00 and was denied; stale OLD 90.00 would have computed 10.00 and falsely allowed; denial sticky through savepoint recovery, sibling rejection and COMMIT rejection; A durable 30.00)\n'

printf '\nnumeric contention scenario C: lock holder rolls back while B waits\n'
reset_refunds
nc_deadlocks_before="$(nc_deadlocks)"

cc_send a "BEGIN;"
cc_send a "UPDATE public.refunds SET amount=80.00 WHERE id=1;"
nc_state_assert a NC_C_A1 '0|0|80.00|f'

nc_b_update1_before="$(grep -cFx 'UPDATE 1' "$CC_SESSION_DIR/b.out" || true)"
cc_send b "BEGIN;"
cc_send b "UPDATE public.refunds SET amount=40.00 WHERE id=1;"
cc_wait_blocked "$cc_b_pid" "$cc_a_pid" "numeric contention scenario C"

nc_a_rollbacks_before="$(cc_count_tag a ROLLBACK)"
nc_a_commits_before="$(cc_count_tag a COMMIT)"
cc_send a "ROLLBACK;"
cc_sync a NC_C_A_ROLLBACK
[[ "$(( $(cc_count_tag a ROLLBACK) - nc_a_rollbacks_before ))" == "1" ]] || \
    fail "numeric contention scenario C: session A did not roll back"
[[ "$(( $(cc_count_tag a COMMIT) - nc_a_commits_before ))" == "0" ]] || \
    fail "numeric contention scenario C: session A unexpectedly emitted a COMMIT tag"

cc_sync b NC_C_B_RESUMED
[[ "$(nc_update1_delta b "$nc_b_update1_before")" == "1" ]] || \
    fail "numeric contention scenario C: B contended update did not report exactly one UPDATE tag"
nc_state_assert b NC_C_B1 '0|0|40.00|f'
nc_b_commits_before="$(cc_count_tag b COMMIT)"
cc_send b "COMMIT;"
cc_sync b NC_C_B_COMMIT
[[ "$(( $(cc_count_tag b COMMIT) - nc_b_commits_before ))" == "1" ]] || \
    fail "numeric contention scenario C: session B did not commit"

assert_refunds 'numeric contention scenario C durable' \
    '1=40.00,2=0.00,3=0.00,4=0.00,5=0.00,6=0.00,7=0.00,8=0.00'
cc_probe_assert a NC_C_A_CLEAN f 0 f
cc_probe_assert b NC_C_B_CLEAN f 0 f
nc_deadlocks_after="$(nc_deadlocks)"
printf 'numeric contention scenario C: deadlock count %s -> %s\n' "$nc_deadlocks_before" "$nc_deadlocks_after"
[[ "$nc_deadlocks_after" == "$nc_deadlocks_before" ]] || \
    fail "numeric contention scenario C: deadlock count changed"
printf 'numeric contention scenario C: PASS (B resumed against committed 0.00 baseline and consumed 40.00; the aborted 80.00 version would have produced a -40.00 delta and zero consumption)\n'

cc_session_stop a
cc_session_stop b
printf 'numeric-delta concurrent row-lock contention: scenarios A, B and C PASS\n'
