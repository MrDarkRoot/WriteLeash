#!/usr/bin/env bash
set -euo pipefail
compose=(docker compose -p commitcap_wordpress_foundation -f "$(dirname "$0")/docker-compose.yml")
cleanup() { "${compose[@]}" down -v --remove-orphans; }
trap cleanup EXIT
"${compose[@]}" up -d --wait db
"${compose[@]}" run --build --rm tester
