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
fetch "$here/cache/redis-cache.2.7.0.zip" 0cbc41dea351688693b8e7db1165b5d92b6c8ef7231ac0ff5abec7b83d62c635
redirection_sha="$(awk '$2 == "redirection.5.5.2" {print $1}' "$here/../research/checksums.txt")"
fetch "$here/../research/.cache/redirection.5.5.2.zip" "$redirection_sha"
compose=(docker compose -p writeleash_woo108 -f "$here/docker-compose.yml")
cleanup() {
  local status=$?
  trap - EXIT
  if ! "${compose[@]}" down -v --remove-orphans; then status=1; fi
  if ! bash "$here/../assert-compose-clean.sh" writeleash_woo108; then status=1; fi
  exit "$status"
}
trap cleanup EXIT
"${compose[@]}" up -d --wait mysql mariadb redis
redis_version=$("${compose[@]}" exec -T redis redis-cli --raw INFO server | sed -n 's/^redis_version://p' | tr -d '\r')
[[ "$redis_version" = 7.4.2 ]]
echo "#108 pinned Redis server: $redis_version"
"${compose[@]}" run --build --rm tester
