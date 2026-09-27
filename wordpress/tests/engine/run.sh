#!/usr/bin/env bash
set -euo pipefail
compose=(docker compose -p commitcap_wp_engine54 -f "$(dirname "$0")/docker-compose.yml")
cleanup() { "${compose[@]}" down -v --remove-orphans; }
trap cleanup EXIT
"${compose[@]}" up -d --wait mysql mariadb
"${compose[@]}" run --build --rm tester
