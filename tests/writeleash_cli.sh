#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
CLI="$REPO_DIR/writeleash"
BASH_BIN="$(command -v bash)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf -- "$TMP_DIR"' EXIT

fail() {
    printf 'CLI TEST FAIL: %s\n' "$1" >&2
    exit 1
}

contains() {
    local text="$1"
    local expected="$2"
    [[ "$text" == *"$expected"* ]] || fail "missing [$expected] in output [$text]"
}

reject_command() {
    local label="$1"
    shift
    local output
    if output="$("$CLI" "$@" 2>&1)"; then
        fail "$label unexpectedly succeeded: $output"
    fi
    [[ "$output" != *'CREATE TRIGGER'* ]] || fail "$label emitted SQL before rejecting input"
}

[[ -x "$CLI" ]] || fail './writeleash is not executable'

help_output="$("$CLI" --help)"
contains "$help_output" './writeleash doctor'
contains "$help_output" 'protect-update'
contains "$help_output" './writeleash demo'
contains "$("$CLI" doctor --help)" 'Docker daemon reachability'
contains "$("$CLI" protect-update --help)" 'canonical decimal integer'
contains "$("$CLI" protect-update --help)" 'SET ROLE'

for valid_budget in 50 0 2147483647; do
    valid_output="$("$CLI" protect-update --table public.orders --budget "$valid_budget")"
    contains "$valid_output" 'CREATE TRIGGER "writeleash_rows_updated"'
    contains "$valid_output" 'BEFORE UPDATE ON "public"."orders"'
    contains "$valid_output" "EXECUTE FUNCTION writeleash_native.enforce_rows_updated('$valid_budget');"
    contains "$valid_output" "\\set cc_expected_budget $valid_budget"
    contains "$valid_output" 'V0 rejects SET or ALTER SYSTEM privilege on session_replication_role'
    contains "$valid_output" 'OVERALL'
    contains "$valid_output" 'transaction splitting'
done

dollar_identifier="$("$CLI" protect-update --table 'public.order$archive' --budget 1)"
contains "$dollar_identifier" 'BEFORE UPDATE ON "public"."order$archive"'

sentinel="$TMP_DIR/shell-was-executed"
substitution='public.$(touch __SENTINEL__)'
substitution="${substitution/__SENTINEL__/$sentinel}"
backtick='public.`touch __SENTINEL__`'
backtick="${backtick/__SENTINEL__/$sentinel}"

bad_tables=(
    'orders'
    'public.'
    '.public'
    'public.orders.extra'
    'public.orders;'
    'public.orders--comment'
    'public.orders DROP TABLE x'
    'public."orders"'
    'public.Orders'
    'Public.orders'
    "public.$(printf 'a%.0s' {1..64})"
    $'public.orders\nDROP TABLE x'
    "$substitution"
    "$backtick"
    'public.orders${IFS}'
    'public.orders&'
    'public.orders|'
    'public.orders>'
    'public.orders<'
)
for bad_table in "${bad_tables[@]}"; do
    reject_command "invalid table [$bad_table]" protect-update --table "$bad_table" --budget 5
done

budget_injection="\$(touch $sentinel)"
bad_budgets=(
    ''
    '-1'
    '+1'
    '01'
    '1.0'
    '1e3'
    ' 5'
    '5 '
    '2147483648'
    '999999999999999999999999999999999999999999999999'
    'garbage'
    ';'
    '&'
    '|'
    '>'
    '<'
    $'5\n6'
    "$budget_injection"
)
for bad_budget in "${bad_budgets[@]}"; do
    reject_command "invalid budget [$bad_budget]" protect-update --table public.orders --budget "$bad_budget"
done
[[ ! -e "$sentinel" ]] || fail 'shell metacharacter input executed a command'

reject_command 'missing table' protect-update --budget 5
reject_command 'unknown flag' protect-update --table public.orders --budget 5 --apply
reject_command 'help with unknown flag' protect-update --help --apply
reject_command 'unknown command' not-a-command
reject_command 'invalid writer role' protect-update --table public.orders --budget 5 --writer-role 'writer;DROP'
reject_command 'overlong writer role' protect-update --table public.orders --budget 5 --writer-role "$(printf 'a%.0s' {1..64})"

mkdir "$TMP_DIR/stub" "$TMP_DIR/empty-path"
cat > "$TMP_DIR/stub/docker" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
case "${DOCKER_STUB_MODE:-success}:$1:${2:-}" in
    success:compose:version|no-daemon:compose:version)
        printf 'Docker Compose version v2.27.0\n'
        ;;
    missing-compose:compose:version)
        exit 1
        ;;
    success:info:--format|missing-compose:info:--format)
        printf '27.0.0\n'
        ;;
    no-daemon:info:--format)
        exit 1
        ;;
    *)
        exit 1
        ;;
esac
STUB
chmod +x "$TMP_DIR/stub/docker"

doctor_ok="$(PATH="$TMP_DIR/stub:$PATH" DOCKER_STUB_MODE=success "$BASH_BIN" "$CLI" doctor)"
contains "$doctor_ok" '✓ Bash'
contains "$doctor_ok" '✓ Docker Compose v2 interface'
contains "$doctor_ok" '✓ Docker daemon'
contains "$doctor_ok" 'PostgreSQL 16.4 local Docker fixture'
contains "$doctor_ok" 'managed PostgreSQL'

if doctor_output="$(PATH="$TMP_DIR/empty-path" "$BASH_BIN" "$CLI" doctor 2>&1)"; then
    fail "doctor passed without Docker: $doctor_output"
fi
contains "$doctor_output" '✗ Docker CLI unavailable'
contains "$doctor_output" 'Install Docker Engine/Desktop'

if doctor_output="$(PATH="$TMP_DIR/stub:$PATH" DOCKER_STUB_MODE=missing-compose "$BASH_BIN" "$CLI" doctor 2>&1)"; then
    fail "doctor passed without Docker Compose: $doctor_output"
fi
contains "$doctor_output" '✗ Docker Compose v2 unavailable'
contains "$doctor_output" 'Install the Docker Compose plugin'

if doctor_output="$(PATH="$TMP_DIR/stub:$PATH" DOCKER_STUB_MODE=no-daemon "$BASH_BIN" "$CLI" doctor 2>&1)"; then
    fail "doctor passed without daemon: $doctor_output"
fi
contains "$doctor_output" '✗ Docker daemon unavailable'
contains "$doctor_output" 'Start Docker and retry: ./writeleash doctor'

reject_command 'demo rejects unknown flags' demo --apply

printf 'WriteLeash CLI/DX tests: PASS\n'
