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

project="commitcap_wordpress_foundation_$db"
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
