#!/usr/bin/env bash
# #97 deterministic active-identity scan for maintained root/native/demo/SQL
# tooling. The pre-rebrand product identifier must not appear in active code.
# Historical evidence directories and the WordPress plugin tree are out of
# scope here: evidence is intentionally preserved, and the plugin tree has its
# own staged audit plus explicit pre-release cleanup and negative-test literals.
set -euo pipefail

REPO_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
old_brand='commit''cap'
fail() { printf 'ACTIVE IDENTITY SCAN FAIL: %s\n' "$1" >&2; exit 1; }

found=0
while IFS= read -r file; do
    case "$file" in
        */evidence/*) continue ;;
    esac
    if grep -n -- "$old_brand" "$file" >/dev/null 2>&1; then
        printf 'pre-rebrand identifier in active tooling: %s\n' "$file" >&2
        found=1
    fi
done < <(
    find "$REPO_DIR/demo" "$REPO_DIR/experiments" "$REPO_DIR/sql" "$REPO_DIR/tests" -type f \
        \( -name '*.sh' -o -name '*.sql' -o -name '*.c' -o -name '*.control' \
           -o -name 'Makefile' -o -name 'Dockerfile' -o -name '*.yml' -o -name '*.yaml' \) -print
    printf '%s\n' "$REPO_DIR/writeleash"
)

[ "$found" = 0 ] || fail 'pre-rebrand identifiers remain in active core tooling'
printf 'Active WriteLeash identity scan: PASS (root CLI, demo, SQL, native tooling)\n'
