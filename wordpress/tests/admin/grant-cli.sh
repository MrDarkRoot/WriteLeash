#!/usr/bin/env bash
# #309 WP-CLI surface gate (container). Proves the runtime-registered
# `wp writeleash grant` group fails closed without an authenticated operator
# and behaves over the real repository with one:
#   bash /opt/tests/admin/grant-cli.sh "$site"
set -euo pipefail
site=$1
wp() { command wp --path="$site" "$@"; }
# busybox date (Alpine tester image) rejects GNU relative dates; PHP is the
# portable UTC clock here.
future() { php -r 'echo gmdate("Y-m-d H:i:s", time() + 86400);'; }
expect_fail() {
  local label=$1
  shift
  if wp "$@" >/tmp/wl309-cli.out 2>&1; then
    echo "#309 CLI expected refusal but succeeded: $label" >&2
    cat /tmp/wl309-cli.out >&2
    exit 1
  fi
  echo "#309 CLI refused: $label"
}

# Fixture identities: one product-manager operator, one agent, one
# capability-less user, one site administrator for the #308 emergency
# controls, and one leashed shop manager. Idempotent across site profiles.
operator_id=$(wp user get wl309cli-operator --field=ID 2>/dev/null || wp user create wl309cli-operator wl309cli-operator@example.test --role=shop_manager --porcelain)
agent_id=$(wp user get wl309cli-agent --field=ID 2>/dev/null || wp user create wl309cli-agent wl309cli-agent@example.test --role=subscriber --porcelain)
spectator_id=$(wp user get wl309cli-spectator --field=ID 2>/dev/null || wp user create wl309cli-spectator wl309cli-spectator@example.test --role=subscriber --porcelain)
admin_id=$(wp user get wl309cli-admin --field=ID 2>/dev/null || wp user create wl309cli-admin wl309cli-admin@example.test --role=administrator --porcelain)
manager_id=$(wp user get wl309cli-manager --field=ID 2>/dev/null || wp user create wl309cli-manager wl309cli-manager@example.test --role=shop_manager --porcelain)

# Cookie-less / anonymous CLI is refused; a guessed or unknown --user id is
# refused; the capability-less operator is refused by the repository contract.
expect_fail 'list without --user' writeleash grant list
expect_fail 'create without --user' writeleash grant create "--actor=$agent_id" --budget=1 "--expires=$(future)"
expect_fail 'list with unknown --user' writeleash grant list --user=99999999
expect_fail 'list with capability-less --user' writeleash grant list "--user=$spectator_id"
expect_fail 'self-issued create' writeleash grant create "--user=$operator_id" "--actor=$operator_id" --budget=1 "--expires=$(future)"
expect_fail 'nonexistent actor create' writeleash grant create "--user=$operator_id" --actor=99999999 --budget=1 "--expires=$(future)"
expect_fail 'zero budget create' writeleash grant create "--user=$operator_id" "--actor=$agent_id" --budget=0 "--expires=$(future)"
expect_fail 'past expiry create' writeleash grant create "--user=$operator_id" "--actor=$agent_id" --budget=1 '--expires=2000-01-01 00:00:00'
expect_fail 'revoke without --user' writeleash grant revoke 00000000-0000-0000-0000-000000000000
expect_fail 'disable without --user' writeleash grant disable
expect_fail 'enable without --user' writeleash grant enable
expect_fail 'revoke --all without --user' writeleash grant revoke --all
expect_fail 'disable with unknown --user' writeleash grant disable --user=99999999
expect_fail 'enable with capability-less --user' writeleash grant enable "--user=$spectator_id"
expect_fail 'revoke --all with capability-less --user' writeleash grant revoke --all "--user=$spectator_id"

# #308 emergency controls (disable, enable, revoke --all) require manage_options
# on top of the product-management pair and refuse a restricted operator. A
# shop manager holds exactly that pair (it is the #307 restricted profile), so
# it must fail closed and never move the kill-switch or the grant set.
kill_before=$(wp eval 'echo WriteLeash\Grant_Repository::is_disabled() ? "1" : "0";')
expect_fail 'disable as shop manager' writeleash grant disable "--user=$operator_id"
expect_fail 'enable as shop manager' writeleash grant enable "--user=$operator_id"
expect_fail 'revoke --all as shop manager' writeleash grant revoke --all "--user=$operator_id"
test "$(wp eval 'echo WriteLeash\Grant_Repository::is_disabled() ? "1" : "0";')" = "$kill_before"
leash_out=$(wp writeleash grant create "--user=$admin_id" "--actor=$manager_id" --budget=1 "--expires=$(future)")
leash_id=$(printf '%s' "$leash_out" | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
test -n "$leash_id"
test "$(wp eval "echo WriteLeash\\Grant_Repository::is_restricted_actor($manager_id) ? '1' : '0';")" = "1"
expect_fail 'disable as leashed shop manager' writeleash grant disable "--user=$manager_id"
expect_fail 'enable as leashed shop manager' writeleash grant enable "--user=$manager_id"
expect_fail 'revoke --all as leashed shop manager' writeleash grant revoke --all "--user=$manager_id"
test "$(wp eval 'echo WriteLeash\Grant_Repository::is_disabled() ? "1" : "0";')" = "$kill_before"
test "$(wp eval "echo WriteLeash\\Grant_Repository::is_restricted_actor($manager_id) ? '1' : '0';")" = "1"
wp writeleash grant list "--user=$admin_id" --format=csv | grep -q "^$leash_id,$manager_id,$admin_id,active,"

# Happy path: create, list, revoke — with the authenticated operator.
created=$(wp writeleash grant create "--user=$operator_id" "--actor=$agent_id" --budget=3 "--expires=$(future)")
echo "#309 CLI create: $created"
public_id=$(printf '%s' "$created" | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
test -n "$public_id"
# CSV row shape: public_id,actor_id,issuer_id,status,budget_consumed,budget_reserved,budget_max,expires_at,version
wp writeleash grant list "--user=$operator_id" --format=csv | grep -q "^$public_id,$agent_id,$operator_id,active,"
wp writeleash grant revoke "$public_id" "--user=$operator_id" >/tmp/wl309-cli-revoke.out
grep -q 'revoked' /tmp/wl309-cli-revoke.out
wp writeleash grant list "--user=$operator_id" --format=csv | grep -q "^$public_id,$agent_id,$operator_id,revoked,"

# #308 emergency controls through a non-restricted administrator.
wp writeleash grant disable "--user=$admin_id" >/tmp/wl308-cli-disable.out
grep -q 'paused' /tmp/wl308-cli-disable.out
test "$(wp option get writeleash_grants_disabled)" = "1"
test "$(wp eval 'echo WriteLeash\Grant_Repository::is_disabled() ? "1" : "0";')" = "1"
wp writeleash grant enable "--user=$admin_id" >/tmp/wl308-cli-enable.out
grep -q 'resumed' /tmp/wl308-cli-enable.out
test "$(wp option get writeleash_grants_disabled)" = "0"
test "$(wp eval 'echo WriteLeash\Grant_Repository::is_disabled() ? "1" : "0";')" = "0"

bulk_a=$(wp writeleash grant create "--user=$operator_id" "--actor=$agent_id" --budget=1 "--expires=$(future)")
bulk_b=$(wp writeleash grant create "--user=$operator_id" "--actor=$spectator_id" --budget=1 "--expires=$(future)")
bulk_a_id=$(printf '%s' "$bulk_a" | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
bulk_b_id=$(printf '%s' "$bulk_b" | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
test -n "$bulk_a_id"
test -n "$bulk_b_id"
revoke_all_out=$(wp writeleash grant revoke --all "--user=$admin_id")
printf '%s' "$revoke_all_out" | grep -qE '[0-9]+ active grant\(s\) revoked'
wp writeleash grant list "--user=$operator_id" --format=csv | grep -q "^$bulk_a_id,$agent_id,$operator_id,revoked,"
wp writeleash grant list "--user=$operator_id" --format=csv | grep -q "^$bulk_b_id,$spectator_id,$operator_id,revoked,"
test "$(wp eval "echo WriteLeash\\Grant_Repository::is_restricted_actor($agent_id) ? '1' : '0';")" = "1"
test "$(wp eval "echo WriteLeash\\Grant_Repository::is_restricted_actor($spectator_id) ? '1' : '0';")" = "1"
wp writeleash grant revoke --all "--user=$admin_id" >/tmp/wl308-cli-revoke-all-repeat.out
grep -q 'nothing revoked' /tmp/wl308-cli-revoke-all-repeat.out
echo '#309/#308 CLI gate: list/create/revoke authenticated on the product-management pair, pause/resume/revoke-all administrator-only, every refusal fail-closed: PASS'
