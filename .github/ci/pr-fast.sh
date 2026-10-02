#!/usr/bin/env bash
# Cheap, network-free public source/package gate. No release ZIP is produced.
set -euo pipefail
repo="$(git rev-parse --show-toplevel)"
cd "$repo"
python3 .github/ci/ownership.py --audit
python3 .github/ci/dependency-audit.py
git ls-files -z 'wordpress/assets/*' 'wordpress/icon/*' | php .github/ci/asset-audit.php
source=wordpress/writeleash
manifest=wordpress/release/writeleash-distribution-files.txt
tests=wordpress/tests/release
php "$tests/package-preflight.php" "$source" "$manifest"
php "$tests/inventory-audit.php" "$source" wordpress/release/PUBLIC-PAYLOAD.md "$manifest"
mkdir -p /tmp/opencode
php "$tests/public-audit-cases.php" "$source" "$manifest"
php "$tests/readme-validate.php" "$source"
php "$tests/historical-shim-cases.php" "$source"
if [[ -f "$tests/claim-matrix-audit.php" ]]; then
  php "$tests/claim-matrix-audit.php" "$repo"
fi
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
while IFS= read -r entry; do
  [[ -z "$entry" || "$entry" == \#* ]] && continue
  mkdir -p "$stage/$(dirname "$entry")"
  cp "$source/$entry" "$stage/$entry"
done < "$manifest"
php "$tests/source-audit.php" "$stage"
count=0
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
  count=$((count + 1))
done < <(git ls-files -z 'wordpress/*.php' 'wordpress/**/*.php')
printf '#133 PHP lint: %s source/fixture files PASS\n' "$count"
printf '#133 PR_FAST PASS\n'
