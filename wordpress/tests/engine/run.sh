#!/usr/bin/env bash
set -euo pipefail
project=commitcap_wp_engine54
compose_file="$(dirname "$0")/docker-compose.yml"
compose=(docker compose -p "$project" -f "$compose_file")
cleanup() {
  local status=$?
  trap - EXIT
  if ! "${compose[@]}" down -v --remove-orphans; then
    printf 'Compose teardown failed for %s\n' "$project" >&2
    if (( status == 0 )); then status=1; fi
  fi
  if ! bash "$(dirname "$0")/../assert-compose-clean.sh" "$project"; then
    if (( status == 0 )); then status=1; fi
  fi
  exit "$status"
}
trap cleanup EXIT
"${compose[@]}" up -d --wait mysql mariadb
"${compose[@]}" run --build --rm tester
