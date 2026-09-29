#!/usr/bin/env bash
set -euo pipefail

case "${1:-}" in
  mysql|mariadb) db="$1" ;;
  *) printf 'Usage: bash wordpress/tests/run.sh {mysql|mariadb}\n' >&2; exit 2 ;;
esac
if (( $# != 1 )); then
  printf 'Expected exactly one database selector\n' >&2
  exit 2
fi

here="$(cd "$(dirname "$0")" && pwd)"
file="redirection.5.5.2"
sha="$(awk -v f="$file" '$2 == f { print $1 }' "$here/research/checksums.txt")"
if [ -z "$sha" ]; then
  printf 'No pinned checksum for %s\n' "$file" >&2
  exit 1
fi
mkdir -p "$here/research/.cache"
zip="$here/research/.cache/$file.zip"
if [ ! -f "$zip" ] || [ "$(sha256sum "$zip" | cut -d' ' -f1)" != "$sha" ]; then
  curl -fsSL -o "$zip.tmp" "https://downloads.wordpress.org/plugin/$file.zip"
  mv "$zip.tmp" "$zip"
fi
echo "$sha  $zip" | sha256sum -c - >/dev/null

project="writeleash_wordpress_foundation_$db"
compose_file="$(dirname "$0")/docker-compose.yml"
compose=(docker compose -p "$project" -f "$compose_file")
cleanup() {
  local status=$?
  trap - EXIT
  if ! "${compose[@]}" down -v --remove-orphans; then
    printf 'Compose teardown failed for %s\n' "$project" >&2
    if (( status == 0 )); then status=1; fi
  fi
  if ! bash "$(dirname "$0")/assert-compose-clean.sh" "$project"; then
    if (( status == 0 )); then status=1; fi
  fi
  exit "$status"
}
trap cleanup EXIT
"${compose[@]}" up -d --wait "$db"
"${compose[@]}" run --no-deps --build --rm -e CC_DB_FAMILY="$db" tester
