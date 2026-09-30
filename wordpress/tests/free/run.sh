#!/usr/bin/env bash
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
ls "$here" "$here/../research" >/dev/null
mkdir -p "$here/cache" "$here/../research/.cache"
fetch() {
  local file="$1" sha="$2"
  if [[ ! -f "$file" ]] || [[ "$(sha256sum "$file" | cut -d' ' -f1)" != "$sha" ]]; then
    curl -fL --retry 3 "https://downloads.wordpress.org/plugin/$(basename "$file")" -o "$file.tmp"
    mv "$file.tmp" "$file"
  fi
  printf '%s  %s\n' "$sha" "$file" | sha256sum -c -
}
fetch "$here/cache/woocommerce.11.1.2.zip" 9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e
redirection_sha="$(awk '$2 == "redirection.5.5.2" {print $1}' "$here/../research/checksums.txt")"
fetch "$here/../research/.cache/redirection.5.5.2.zip" "$redirection_sha"
compose=(docker compose -p writeleash_woo107 -f "$here/docker-compose.yml")
cleanup() {
  local status=$?
  trap - EXIT
  if ! "${compose[@]}" down -v --remove-orphans; then status=1; fi
  if ! bash "$here/../assert-compose-clean.sh" writeleash_woo107; then status=1; fi
  exit "$status"
}
trap cleanup EXIT
"${compose[@]}" up -d --wait mysql mariadb
"${compose[@]}" run --build --rm tester
