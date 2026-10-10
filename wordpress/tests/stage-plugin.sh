#!/bin/sh
# PUBLIC plugin staging only: validated explicit distribution allowlist.
# Source stays at /opt/writeleash; the manifest is /opt/release/... .
set -eu
site=$1
source=${WRITELEASH_STAGE_SOURCE:-/opt/writeleash}
manifest=${WRITELEASH_STAGE_MANIFEST:-/opt/release/writeleash-distribution-files.txt}
tests=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
destination="$site/wp-content/plugins/writeleash"
test -f "$manifest"
test ! -e "$destination"
# #211: the staged live tree declares the proposed candidate version. The
# explicit pin must move together with the header, readme and changelog on a
# version-bump PR; the historical v0.1 builder keeps its own 0.1.0 default.
php "$tests/release/package-preflight.php" "$source" "$manifest" --version=0.2.0

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

test -f "$destination/writeleash.php"
# The old CommitCap package basename and the wrong directory-named main file
# must never be staged as the active plugin.
test ! -e "$destination/commitcap.php"
test ! -e "$destination/writeleash-for-wordpress.php"
test ! -e "$destination/README.md"
test ! -e "$destination/LICENSE-AUDIT.md"
test ! -e "$destination/RELEASE-MATRIX.md"
test ! -e "$destination/THREAT-MODEL.md"
test ! -e "$destination/operator-setup.txt"
php "$tests/release/package-preflight.php" "$destination" "$manifest" --version=0.2.0
for entry in $entries; do
  case "$entry" in *.php) php -l "$destination/$entry" ;; esac
done
