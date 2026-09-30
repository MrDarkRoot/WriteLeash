#!/usr/bin/env bash
# One technical gate, composing the existing independent fresh-fixture suites.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
tests="$here/.."
repo="$here/../../.."
cache="$tests/research/.cache"
ls "$tests/research" >/dev/null
mkdir -p "$cache"
check_zip="$cache/plugin-check.2.1.0.zip"
cleanup() {
  status=$?
  trap - EXIT
  rm -f "$check_zip" "$check_zip.tmp"
  exit "$status"
}
trap cleanup EXIT

curl -fsSL -o "$check_zip.tmp" https://downloads.wordpress.org/plugin/plugin-check.2.1.0.zip
printf '%s  %s\n' '6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4' "$check_zip.tmp" | sha256sum -c -
mv "$check_zip.tmp" "$check_zip"
php "$here/package-preflight.php" "$repo/wordpress/writeleash" "$repo/wordpress/release/writeleash-distribution-files.txt"
php "$here/readme-validate.php" "$repo/wordpress/writeleash"
php "$here/source-audit.php" "$repo/wordpress/writeleash"
linted=0
for file in "$tests"/*.php "$tests"/adapter/*.php "$tests"/current-core/*.php "$tests"/engine/*.php "$tests"/guard/*.php "$tests"/doctor/*.php "$tests"/jobs/*.php "$tests"/undo/*.php "$here"/*.php; do
  php -l "$file" >/dev/null
  linted=$((linted + 1))
done
echo "#62 PHP test fixture lint: $linted files PASS"
echo '#62 fixture: WordPress 6.8.3; PHP 8.2; MySQL 8.0.44; MariaDB 10.11.15; Redirection 5.5.2; P=2000'
for host in mysql mariadb; do
  echo "#62 $host foundation: fresh activation, lint, direct-access, exact uninstall"
  bash "$tests/run.sh" "$host"
done
echo '#62 both engines: cooperative engine/Guard/Doctor/provisioning research'
bash "$tests/engine/run.sh"
echo '#62 both engines: real authenticated Redirection REST, Admin/CLI, lifecycle, #61 overlap, Plugin Check 2.1.0'
CC62_PLUGIN_CHECK=1 bash "$tests/adapter/run.sh"
echo '#63 both engines: current-stable WordPress 7.1.2 compatibility gate'
bash "$tests/current-core/run.sh"
echo '#63 technical release matrix PASS (source-tree preflight only; see RELEASE-MATRIX.md for UNKNOWN/DEFERRED)'
