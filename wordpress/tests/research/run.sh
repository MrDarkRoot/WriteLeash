#!/usr/bin/env bash
# Gate #85 reproducible plugin research harness.
# Downloads pinned wordpress.org plugin ZIPs (sha256 verified), boots the pinned
# MySQL 8.0.44 fixture and runs the real installed-plugin operations with the
# MySQL general log enabled. Transcripts are printed to stdout for CI capture.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
cache="$here/.cache"
mkdir -p "$cache"

fetch() {
  local file="$1" sha="$2"
  local zip="$cache/$file.zip"
  if [ ! -f "$zip" ] || [ "$(sha256sum "$zip" | cut -d' ' -f1)" != "$sha" ]; then
    curl -fsSL -o "$zip.tmp" "https://downloads.wordpress.org/plugin/$file.zip"
    mv "$zip.tmp" "$zip"
  fi
  echo "$sha  $zip" | sha256sum -c - >/dev/null
}

# Exact versions used by CANDIDATES.md. Checksums pin the downloaded artifact.
fetch redirection.5.5.2   "$(cat "$here/checksums.txt" 2>/dev/null | awk '$2=="redirection.5.5.2"{print $1}')"
fetch fluentform.5.2.9    "$(cat "$here/checksums.txt" 2>/dev/null | awk '$2=="fluentform.5.2.9"{print $1}')"
fetch relevanssi.4.22.1   "$(cat "$here/checksums.txt" 2>/dev/null | awk '$2=="relevanssi.4.22.1"{print $1}')"

project=writeleash_wp_research
compose_file="$here/docker-compose.yml"
compose=(docker compose -p "$project" -f "$compose_file")
cleanup() {
  local status=$?
  trap - EXIT
  if ! "${compose[@]}" down -v --remove-orphans; then
    printf 'Compose teardown failed for %s\n' "$project" >&2
    if (( status == 0 )); then status=1; fi
  fi
  exit "$status"
}
trap cleanup EXIT
"${compose[@]}" up -d --wait mysql
"${compose[@]}" run --no-deps --build --rm tester
