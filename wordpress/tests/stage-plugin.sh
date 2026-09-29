#!/bin/sh
# Test-only installation shape driven by the #63 distribution allowlist.
# Source stays at /opt/commitcap-for-wordpress; the manifest is /opt/release/... .
set -eu
site=$1
source=/opt/commitcap-for-wordpress
manifest=/opt/release/commitcap-distribution-files.txt
destination="$site/wp-content/plugins/commitcap"
test -f "$manifest"
test ! -e "$destination"

entries=$(grep -v '^#' "$manifest" | grep -v '^[[:space:]]*$')
test -n "$entries"
if printf '%s\n' $entries | grep -q ' '; then
  echo 'Distribution manifest paths must not contain spaces' >&2
  exit 1
fi

mkdir -p "$destination"
for entry in $entries; do
  case "$entry" in
    /*|*..*) echo "Unsafe distribution manifest entry: $entry" >&2; exit 1 ;;
  esac
  if [ ! -f "$source/$entry" ]; then
    echo "Distribution manifest entry missing from source: $entry" >&2
    exit 1
  fi
  mkdir -p "$destination/$(dirname "$entry")"
  cp "$source/$entry" "$destination/$entry"
done

staged=$(cd "$destination" && find . -type f | sed 's|^\./||' | LC_ALL=C sort)
expected=$(printf '%s\n' $entries | LC_ALL=C sort)
if [ "$staged" != "$expected" ]; then
  echo 'Staged plugin files do not match the distribution allowlist' >&2
  printf 'staged:\n%s\nexpected:\n%s\n' "$staged" "$expected" >&2
  exit 1
fi

test -f "$destination/commitcap.php"
test ! -e "$destination/commitcap-for-wordpress.php"
test ! -e "$destination/README.md"
test ! -e "$destination/LICENSE-AUDIT.md"
test ! -e "$destination/RELEASE-MATRIX.md"
test ! -e "$destination/THREAT-MODEL.md"
