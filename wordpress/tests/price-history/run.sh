#!/usr/bin/env bash
set -euo pipefail
repo="$(cd "$(dirname "$0")/../../.." && pwd)"
python3 "$repo/wordpress/tests/portfolio/test-isolation.py" --target price-history
bash "$repo/wordpress/tests/portfolio/run.sh" price-history
