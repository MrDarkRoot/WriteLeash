#!/usr/bin/env bash
set -euo pipefail

project="$1"
containers="$(docker ps -a -q --filter "label=com.docker.compose.project=$project")"
networks="$(docker network ls -q --filter "label=com.docker.compose.project=$project")"
volumes="$(docker volume ls -q --filter "label=com.docker.compose.project=$project")"
if [[ -n "$containers" || -n "$networks" || -n "$volumes" ]]; then
  printf 'Compose cleanup %s: FAIL (containers=%s networks=%s volumes=%s)\n' "$project" "$containers" "$networks" "$volumes" >&2
  exit 1
fi
printf 'Compose cleanup %s: PASS (no containers, networks or volumes)\n' "$project"
