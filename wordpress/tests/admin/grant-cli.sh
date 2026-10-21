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
# capability-less user. Idempotent across repeated site profiles.
operator_id=$(wp user get wl309cli-operator --field=ID 2>/dev/null || wp user create wl309cli-operator wl309cli-operator@example.test --role=shop_manager --porcelain)
agent_id=$(wp user get wl309cli-agent --field=ID 2>/dev/null || wp user create wl309cli-agent wl309cli-agent@example.test --role=subscriber --porcelain)
spectator_id=$(wp user get wl309cli-spectator --field=ID 2>/dev/null || wp user create wl309cli-spectator wl309cli-spectator@example.test --role=subscriber --porcelain)

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
echo '#309 CLI gate: list/create/revoke authenticated, every refusal fail-closed: PASS'
