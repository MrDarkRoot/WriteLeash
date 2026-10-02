#!/usr/bin/env bash
# #87 reproducible adapter suite. Verifies the pinned Redirection 5.5.2 ZIP
# (sha256) and runs the real-Plugin adapter on both pinned database engines.
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

file="woocommerce.9.9.7"
sha="96facedd12e32b6e0b120dcaa4a62b9c6b1ae12159d32510ea416c8e9b44f4dd"
zip="$cache/$file.zip"
if [ ! -f "$zip" ] || [ "$(sha256sum "$zip" | cut -d' ' -f1)" != "$sha" ]; then
  curl -fsSL -o "$zip.tmp" "https://downloads.wordpress.org/plugin/$file.zip"
  mv "$zip.tmp" "$zip"
fi
echo "$sha  $zip" | sha256sum -c - >/dev/null

project=writeleash_wp_redirection_adapter
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
"${compose[@]}" run --build --rm -e CC62_PLUGIN_CHECK="${CC62_PLUGIN_CHECK:-0}" tester
