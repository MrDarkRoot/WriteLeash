#!/usr/bin/env bash
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
case "${1:-}" in price-history|price-campaigns) export ISOLATION_TARGET="$1" ;; *) echo 'Explicit satellite target required' >&2; exit 1 ;; esac
command -v docker >/dev/null || { echo '#144 real WP/Woo fixture NOT RUN: Docker executable unavailable' >&2; exit 1; }
docker info >/dev/null
export ISOLATION_SOURCE_SHA="$(git -C "$here" rev-parse HEAD)"
[[ "$ISOLATION_SOURCE_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ -z "$(git -C "$here" status --porcelain --untracked-files=all)" ]] || { echo 'Dirty exact-SHA fixture source' >&2; exit 1; }
mkdir -p "$here/cache"
file="$here/cache/woocommerce.11.1.2.zip"
sha=9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e
if [[ ! -f "$file" ]] || [[ "$(sha256sum "$file" | cut -d' ' -f1)" != "$sha" ]]; then
  curl -fL --retry 3 https://downloads.wordpress.org/plugin/woocommerce.11.1.2.zip -o "$file.tmp"
  mv "$file.tmp" "$file"
fi
printf '%s  %s\n' "$sha" "$file" | sha256sum -c -
project="wl144_${ISOLATION_TARGET//-/_}"
compose=(docker compose -p "$project" -f "$here/docker-compose.yml")
cleanup() {
  local status=$?
  trap - EXIT
  if ! "${compose[@]}" down -v --remove-orphans; then status=1; fi
  if ! bash "$here/../assert-compose-clean.sh" "$project"; then status=1; fi
  exit "$status"
}
trap cleanup EXIT
"${compose[@]}" up -d --wait mysql mariadb
"${compose[@]}" run --build --rm tester
