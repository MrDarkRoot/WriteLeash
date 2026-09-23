#!/usr/bin/env bash
set -euo pipefail

ROOT="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
FIXTURE="$ROOT/experiments/native_tx_state"
PINNED_IMAGE='postgres@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c'
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-commitcap_demo}"
COMPOSE=(docker compose -f "$FIXTURE/docker-compose.yml")
started=false

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
admin_psql() {
    "${COMPOSE[@]}" exec -T -e PGPASSWORD=commitcap_native_admin_experiment_only postgres \
        psql -X -A -t -h 127.0.0.1 -U commitcap_native_admin -d commitcap_native -v ON_ERROR_STOP=1 "$@"
}
writer_psql() {
    "${COMPOSE[@]}" exec -T -e PGPASSWORD=commitcap_writer_experiment_only postgres \
        psql -X -A -t -h 127.0.0.1 -U commitcap_writer -d commitcap_native "$@"
}
cleanup() {
    local status=$?
    trap - EXIT
    if [[ "$started" == true ]]; then
        "${COMPOSE[@]}" down -v >/dev/null 2>&1 || printf 'WARN: compose teardown failed; run docker compose -f %s down -v\n' "$FIXTURE/docker-compose.yml" >&2
    fi
    printf 'Demo exit status: %s\n' "$status"
    exit "$status"
}
line() {
    [[ $'\n'"$1"$'\n' == *$'\n'"$2"$'\n'* ]] || fail "missing exact line [$2] in: $1"
}
reset() {
    # Trusted admin only; TRUNCATE resets fixture rows without firing UPDATE triggers.
    admin_psql >/dev/null <<'SQL'
BEGIN;
TRUNCATE public.unprotected_audit RESTART IDENTITY;
TRUNCATE public.subscriptions, public.users, public.refunds;
INSERT INTO public.subscriptions SELECT id, 'baseline' FROM generate_series(1,10) AS ids(id);
INSERT INTO public.users SELECT id, 10, 'member' FROM generate_series(1,6) AS ids(id);
INSERT INTO public.refunds SELECT id, 10, 0.00 FROM generate_series(1,8) AS ids(id);
COMMIT;
SQL
}
baseline_subs='1=baseline,2=baseline,3=baseline,4=baseline,5=baseline,6=baseline,7=baseline,8=baseline,9=baseline,10=baseline'
baseline_users='1=member,2=member,3=member,4=member,5=member,6=member'
baseline_refunds='1=0.00,2=0.00,3=0.00,4=0.00,5=0.00,6=0.00,7=0.00,8=0.00'
snapshot() {
    # Each call starts a NEW trusted-admin connection after the writer session exits.
    admin_psql -c "SELECT 'subscriptions=' || (SELECT string_agg(id || '=' || status, ',' ORDER BY id) FROM public.subscriptions) UNION ALL SELECT 'users=' || (SELECT string_agg(id || '=' || role, ',' ORDER BY id) FROM public.users) UNION ALL SELECT 'refunds=' || (SELECT string_agg(id || '=' || amount, ',' ORDER BY id) FROM public.refunds) UNION ALL SELECT 'audit=' || (SELECT count(*)::text FROM public.unprotected_audit);"
}
assert_durable() {
    local actual
    actual="$(snapshot)"
    line "$actual" "subscriptions=$1"
    line "$actual" "users=$2"
    line "$actual" "refunds=$3"
    line "$actual" "audit=$4"
    printf 'fresh trusted-admin durable state:\n%s\n' "$actual"
}
show_writer() { printf 'writer SQL result:\n%s\n' "$1"; }

command -v docker >/dev/null || fail 'Docker is required'
docker compose version >/dev/null || fail 'Docker Compose is required'
docker info >/dev/null || fail 'Docker daemon unavailable'
[[ -z "$("${COMPOSE[@]}" ps -a -q)" ]] || fail "Compose project $COMPOSE_PROJECT_NAME already has a container; choose an unused COMPOSE_PROJECT_NAME"
# Dockerfile uses the tag; bind that local tag to the exact pulled digest first.
docker pull "$PINNED_IMAGE" >/dev/null || fail "could not pull $PINNED_IMAGE"
docker tag "$PINNED_IMAGE" postgres:16.4-alpine || fail 'could not tag pinned PostgreSQL image'
pinned_id="$(docker image inspect "$PINNED_IMAGE" --format '{{.Id}}')" || fail 'pinned image missing after pull'
tagged_id="$(docker image inspect postgres:16.4-alpine --format '{{.Id}}')" || fail 'local PostgreSQL tag missing'
[[ -n "$pinned_id" && "$tagged_id" == "$pinned_id" ]] || fail "local postgres:16.4-alpine image ID $tagged_id differs from pinned image ID $pinned_id"
printf 'Pinned image preflight: %s; local tag image ID: %s\n' "$PINNED_IMAGE" "$tagged_id"
trap cleanup EXIT
started=true
"${COMPOSE[@]}" up -d --build --wait >/dev/null
admin_psql < "$FIXTURE/setup.sql" >/dev/null

printf 'Phase 0 local research demo; project=%s\n' "$COMPOSE_PROJECT_NAME"
printf 'Command: ./demo/phase0/run.sh\nImplementation commit: %s\n' "$(git -C "$ROOT" rev-parse HEAD)"
printf 'Base image: %s (verified local image ID: %s)\n' "$PINNED_IMAGE" "$tagged_id"
printf 'PostgreSQL: %s\n' "$(admin_psql -c 'SHOW server_version;')"
privileges="$(admin_psql -c "SELECT (NOT rolsuper AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolbypassrls AND NOT pg_has_role('commitcap_writer','commitcap_owner','MEMBER') AND has_column_privilege('commitcap_writer','public.subscriptions','status','UPDATE') AND has_column_privilege('commitcap_writer','public.users','role','UPDATE') AND has_column_privilege('commitcap_writer','public.refunds','amount','UPDATE') AND has_column_privilege('commitcap_writer','public.unprotected_audit','message','INSERT') AND NOT has_table_privilege('commitcap_writer','public.refunds','INSERT') AND NOT has_table_privilege('commitcap_writer','public.subscriptions','DELETE') AND NOT has_schema_privilege('commitcap_writer','commitcap_native','USAGE')) FROM pg_roles WHERE rolname='commitcap_writer';")"
[[ "$privileges" == t ]] || fail 'restricted writer privilege envelope differs from setup.sql'
settings="$(writer_psql -v ON_ERROR_STOP=1 -c "SELECT current_user || '|' || current_setting('commitcap_native.test_budget') || '|' || current_setting('commitcap_native.test_numeric_budget');")"
[[ "$settings" == 'commitcap_writer|5|100.00' ]] || fail "writer or budget settings differ: $settings"
printf 'Restricted writer: %s (fixture privilege check=t)\n' "$settings"

printf '\n1. Safe subscriptions + allowed role transition\n'
reset
out="$(writer_psql -v ON_ERROR_STOP=1 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status='repaired' WHERE id BETWEEN 1 AND 2;
UPDATE public.users SET role='moderator' WHERE id=1;
SELECT 'policy=' || subscriptions_consumed || '|' || users_consumed || '|' || refunds_positive_delta || '|' || denied FROM commitcap_probe.cc_native_policy_probe();
COMMIT;
SQL
)"
line "$out" 'UPDATE 2'; line "$out" 'policy=2|1|0.00|false'; line "$out" 'COMMIT'
show_writer "$out"
assert_durable '1=repaired,2=repaired,3=baseline,4=baseline,5=baseline,6=baseline,7=baseline,8=baseline,9=baseline,10=baseline' \
    '1=moderator,2=member,3=member,4=member,5=member,6=member' "$baseline_refunds" 0

printf '\n2. Six small subscriptions statements; savepoint cannot clear denial\n'
reset
out="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
INSERT INTO public.unprotected_audit(message) VALUES ('rolled_back_sibling');
UPDATE public.users SET role='moderator' WHERE id=1;
UPDATE public.refunds SET amount=20.00 WHERE id=1;
UPDATE public.subscriptions SET status='six' WHERE id=1;
UPDATE public.subscriptions SET status='six' WHERE id=2;
UPDATE public.subscriptions SET status='six' WHERE id=3;
UPDATE public.subscriptions SET status='six' WHERE id=4;
UPDATE public.subscriptions SET status='six' WHERE id=5;
SAVEPOINT before_sixth;
UPDATE public.subscriptions SET status='six' WHERE id=6;
\echo sixth_SQLSTATE :SQLSTATE
ROLLBACK TO SAVEPOINT before_sixth;
SELECT 'policy=' || subscriptions_consumed || '|' || users_consumed || '|' || refunds_positive_delta || '|' || denied FROM commitcap_probe.cc_native_policy_probe();
COMMIT;
\echo commit_SQLSTATE :SQLSTATE
SQL
)"
line "$out" 'sixth_SQLSTATE 54000'; line "$out" 'policy=5|1|20.00|true'
line "$out" 'commit_SQLSTATE 54000'
[[ "$out" == *'CommitCap mutation budget exceeded (limit 5, attempted 6)'* && "$out" == *'CommitCap top-level transaction denied after mutation authority violation'* ]] || fail 'row denial message differs'
show_writer "$out"
assert_durable "$baseline_subs" "$baseline_users" "$baseline_refunds" 0

printf '\n3. Forbidden member -> admin; recovered SQL still cannot COMMIT\n'
reset
out="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status='sibling' WHERE id=1;
INSERT INTO public.unprotected_audit(message) VALUES ('forbidden_sibling');
SAVEPOINT before_admin;
UPDATE public.users SET role='admin' WHERE id=1;
\echo transition_SQLSTATE :SQLSTATE
ROLLBACK TO SAVEPOINT before_admin;
SELECT 'policy=' || subscriptions_consumed || '|' || users_consumed || '|' || refunds_positive_delta || '|' || denied FROM commitcap_probe.cc_native_policy_probe();
COMMIT;
\echo commit_SQLSTATE :SQLSTATE
SQL
)"
line "$out" 'transition_SQLSTATE 54000'; line "$out" 'policy=1|0|0.00|true'
line "$out" 'commit_SQLSTATE 54000'
[[ "$out" == *'CommitCap forbidden state transition (* -> admin)'* && "$out" == *'CommitCap top-level transaction denied after mutation authority violation'* ]] || fail 'transition denial message differs'
show_writer "$out"
assert_durable "$baseline_subs" "$baseline_users" "$baseline_refunds" 0

printf '\n4. Exact positive refund delta 30.00 + 70.00 = 100.00\n'
reset
out="$(writer_psql -v ON_ERROR_STOP=1 2>&1 <<'SQL'
BEGIN;
UPDATE public.refunds SET amount=30.00 WHERE id=1;
UPDATE public.refunds SET amount=70.00 WHERE id=2;
SELECT 'policy=' || subscriptions_consumed || '|' || users_consumed || '|' || refunds_positive_delta || '|' || denied FROM commitcap_probe.cc_native_policy_probe();
COMMIT;
SQL
)"
line "$out" 'policy=0|0|100.00|false'; line "$out" 'COMMIT'
show_writer "$out"
assert_durable "$baseline_subs" "$baseline_users" \
    '1=30.00,2=70.00,3=0.00,4=0.00,5=0.00,6=0.00,7=0.00,8=0.00' 0

printf '\n5. Excess positive refund delta 80.00 + 21.00 = 101.00\n'
reset
out="$(writer_psql -v ON_ERROR_STOP=0 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status='sibling' WHERE id=1;
UPDATE public.users SET role='moderator' WHERE id=1;
INSERT INTO public.unprotected_audit(message) VALUES ('numeric_sibling');
UPDATE public.refunds SET amount=80.00 WHERE id=1;
SAVEPOINT before_excess;
UPDATE public.refunds SET amount=21.00 WHERE id=2;
\echo delta_SQLSTATE :SQLSTATE
ROLLBACK TO SAVEPOINT before_excess;
SELECT 'policy=' || subscriptions_consumed || '|' || users_consumed || '|' || refunds_positive_delta || '|' || denied FROM commitcap_probe.cc_native_policy_probe();
COMMIT;
\echo commit_SQLSTATE :SQLSTATE
SQL
)"
line "$out" 'delta_SQLSTATE 54000'; line "$out" 'policy=1|1|80.00|true'
line "$out" 'commit_SQLSTATE 54000'
[[ "$out" == *'CommitCap numeric delta budget exceeded'* && "$out" == *'CommitCap top-level transaction denied after mutation authority violation'* ]] || fail 'numeric denial message differs'
show_writer "$out"
assert_durable "$baseline_subs" "$baseline_users" "$baseline_refunds" 0

printf '\n6. Three independent policy limits in one top-level transaction\n'
reset
out="$(writer_psql -v ON_ERROR_STOP=1 2>&1 <<'SQL'
BEGIN;
UPDATE public.subscriptions SET status='independent' WHERE id BETWEEN 1 AND 5;
UPDATE public.users SET role='moderator' WHERE id BETWEEN 1 AND 5;
UPDATE public.refunds SET amount=100.00 WHERE id=1;
SELECT 'policy=' || subscriptions_consumed || '|' || users_consumed || '|' || refunds_positive_delta || '|' || denied FROM commitcap_probe.cc_native_policy_probe();
COMMIT;
SQL
)"
line "$out" 'policy=5|5|100.00|false'; line "$out" 'COMMIT'
show_writer "$out"
assert_durable '1=independent,2=independent,3=independent,4=independent,5=independent,6=baseline,7=baseline,8=baseline,9=baseline,10=baseline' \
    '1=moderator,2=moderator,3=moderator,4=moderator,5=moderator,6=member' \
    '1=100.00,2=0.00,3=0.00,4=0.00,5=0.00,6=0.00,7=0.00,8=0.00' 0
printf '\nDemo: PASS (six real writer transactions, fresh-admin exact durable oracles)\n'
