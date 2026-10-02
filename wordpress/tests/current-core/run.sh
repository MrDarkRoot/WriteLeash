#!/usr/bin/env bash
# #63 current-stable WordPress compatibility gate. Verifies the pinned
# Redirection 5.5.2 ZIP (sha256) and runs the focused product compatibility
# matrix on both pinned database engines against current WordPress stable.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
research="$here/../research"
cache="$research/.cache"
mkdir -p "$cache"

file="redirection.5.5.2"
sha="$(awk -v f="$file" '$2 == f { print $1 }' "$research/checksums.txt")"
if [ -z "$sha" ]; then
  printf 'No pinned checksum for %s\n' "$file" >&2
  exit 1
fi
zip="$cache/$file.zip"
if [ ! -f "$zip" ] || [ "$(sha256sum "$zip" | cut -d' ' -f1)" != "$sha" ]; then
  curl -fsSL -o "$zip.tmp" "https://downloads.wordpress.org/plugin/$file.zip"
  mv "$zip.tmp" "$zip"
fi
echo "$sha  $zip" | sha256sum -c - >/dev/null

file="woocommerce.11.1.2"
sha="9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e"
zip="$cache/$file.zip"
if [ ! -f "$zip" ] || [ "$(sha256sum "$zip" | cut -d' ' -f1)" != "$sha" ]; then
  curl -fsSL -o "$zip.tmp" "https://downloads.wordpress.org/plugin/$file.zip"
  mv "$zip.tmp" "$zip"
fi
echo "$sha  $zip" | sha256sum -c - >/dev/null

project=writeleash_wp_current_core
compose_file="$here/docker-compose.yml"
compose=(docker compose -p "$project" -f "$compose_file")
cleanup() {
  local status=$?
  trap - EXIT
  if ! "${compose[@]}" down -v --remove-orphans; then
    printf 'Compose teardown failed for %s\n' "$project" >&2
    if (( status == 0 )); then status=1; fi
  fi
  if ! bash "$here/../assert-compose-clean.sh" "$project"; then
    if (( status == 0 )); then status=1; fi
  fi
  exit "$status"
}
trap cleanup EXIT
"${compose[@]}" up -d --wait mysql mariadb
"${compose[@]}" run --build --rm tester
