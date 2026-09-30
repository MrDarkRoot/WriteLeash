#!/usr/bin/env bash
# Isolated disposable PG16.4 security fixture for the generic UPDATE product.
set -euo pipefail
EXPERIMENT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE=(docker compose -f "$EXPERIMENT_DIR/docker-compose.yml")
PIN='postgres@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c'
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-writeleash_product_47}"
started=false
cleanup() {
    local status=$?
    trap - EXIT
    if [[ "$started" == true ]]; then
        "${COMPOSE[@]}" down -v --remove-orphans >/dev/null || status=1
    fi
    printf 'Product #47 exit status: %s\n' "$status"
    exit "$status"
}
command -v docker >/dev/null || { printf 'Docker required\n' >&2; exit 1; }
docker compose version >/dev/null
docker info >/dev/null
[[ -z "$("${COMPOSE[@]}" ps -a -q)" ]] || { printf 'Compose project already in use: %s\n' "$COMPOSE_PROJECT_NAME" >&2; exit 1; }
docker pull "$PIN" >/dev/null
docker tag "$PIN" postgres:16.4-alpine
[[ "$(docker image inspect "$PIN" --format '{{.Id}}')" == "$(docker image inspect postgres:16.4-alpine --format '{{.Id}}')" ]] || { printf 'PostgreSQL image digest mismatch\n' >&2; exit 1; }
trap cleanup EXIT
started=true
"${COMPOSE[@]}" up -d --build --wait >/dev/null
"${COMPOSE[@]}" exec -T -e PGPASSWORD=writeleash_native_admin_experiment_only postgres \
    psql -X -h 127.0.0.1 -U writeleash_native_admin -d writeleash_native -v ON_ERROR_STOP=1 \
    < "$EXPERIMENT_DIR/setup.sql" >/dev/null
source "$EXPERIMENT_DIR/product_update_cases.sh"
