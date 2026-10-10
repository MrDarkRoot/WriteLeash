#!/usr/bin/env bash
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
ls "$here" >/dev/null
node "$here/progress-client.cjs"
php "$here/progress-unit.php"
php "$here/recovery-unit.php"
php "$here/i18n-catalog.php"
php "$here/i18n-unit.php"
php "$here/i18n-plan-unit.php"
php "$here/preview-csv-unit.php"
mkdir -p "$here/cache"
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
fetch "$here/cache/redirection.5.5.2.zip" 2c562a256797828ec3a0ddfd19425f0c1bc126554bb4baaa95434b35f361a648
fetch "$here/cache/woocommerce.11.0.1.zip" da189b6616c610d15a2106f93151dab81b78f83e075bcefce221ac0d00b4fa21
compose=(docker compose -p writeleash_woo111 -f "$here/docker-compose.yml")
cleanup() {
  local status=$?
  trap - EXIT
  if ! "${compose[@]}" down -v --remove-orphans; then status=1; fi
  if ! bash "$here/../assert-compose-clean.sh" writeleash_woo111; then status=1; fi
  exit "$status"
}
trap cleanup EXIT
"${compose[@]}" up -d --wait mysql mariadb redis
redis_version=$("${compose[@]}" exec -T redis redis-cli --raw INFO server | sed -n 's/^redis_version://p' | tr -d '\r')
[[ "$redis_version" = 7.4.2 ]]
echo "#111 pinned Redis server: $redis_version"
"${compose[@]}" build tester
bash "$here/selection-browser-run.sh"
"${compose[@]}" run --rm tester
