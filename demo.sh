#!/usr/bin/env bash
#
# One-command entry point for the current transaction-local research demo.
# This is a thin alias: all behavior, pinning and assertions live in
# demo/phase0/run.sh, which is also the path exercised by CI.
set -euo pipefail

ROOT="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

exec "$ROOT/demo/phase0/run.sh" "$@"
