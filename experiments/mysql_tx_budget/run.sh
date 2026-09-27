#!/usr/bin/env bash
set -euo pipefail
compose=(docker compose -p commitcap_mysql_budget -f "$(dirname "$0")/docker-compose.yml")
cleanup() { "${compose[@]}" down -v --remove-orphans; }
trap cleanup EXIT
"${compose[@]}" up --build -d --wait mysql mariadb
"${compose[@]}" run --build --rm tester
