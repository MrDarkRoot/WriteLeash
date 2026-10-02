#!/usr/bin/env bash
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
ls "$here" >/dev/null
mkdir -p "$here/cache"
: "${WL112_RESULTS:?absolute evidence directory required}"
: "${WL112_SHA:?exact source SHA required}"
: "${WL112_CORE_IMAGE:?pinned core image required}"
: "${WL112_CLI_IMAGE:?pinned PHP image required}"
ls "$WL112_RESULTS" >/dev/null
# Disposable tester runs as the upstream CLI image's non-root www-data user.
chmod 777 "$WL112_RESULTS"
fetch() {
  local name="$1" sha="$2" file="$here/cache/$1"
  if [[ ! -f "$file" ]] || [[ "$(sha256sum "$file" | cut -d' ' -f1)" != "$sha" ]]; then
    curl -fL --retry 3 "https://downloads.wordpress.org/plugin/$name" -o "$file.tmp"
    mv "$file.tmp" "$file"
  fi
  printf '%s  %s\n' "$sha" "$file" | sha256sum -c -
}
fetch woocommerce.11.1.2.zip 9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e
fetch woocommerce.11.0.1.zip da189b6616c610d15a2106f93151dab81b78f83e075bcefce221ac0d00b4fa21
fetch redis-cache.2.7.0.zip 0cbc41dea351688693b8e7db1165b5d92b6c8ef7231ac0ff5abec7b83d62c635
compose=(docker compose -p writeleash_woo112 -f "$here/docker-compose.yml")
cleanup() {
  local status=$?
  trap - EXIT
  if ! "${compose[@]}" down -v --remove-orphans; then status=1; fi
  if ! bash "$here/../assert-compose-clean.sh" writeleash_woo112; then status=1; fi
  exit "$status"
}
trap cleanup EXIT
"${compose[@]}" up -d --wait mysql mariadb redis
# Operator-only fixture construction. The plugin never receives this identity;
# its normal wp-config user lacks TRIGGER/ROUTINE/GRANT/CREATE USER privileges.
"${compose[@]}" exec -T mysql mysql -uroot -pdisposable_root_password -e "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'wp_test'@'%'; GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,DROP,INDEX ON wp_test.* TO 'wp_test'@'%';"
"${compose[@]}" exec -T mariadb mariadb -uroot -pdisposable_root_password -e "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'wp_test'@'%'; GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,DROP,INDEX ON wp_test.* TO 'wp_test'@'%';"
"${compose[@]}" exec -T redis redis-cli INFO server
"${compose[@]}" run --build --rm tester
