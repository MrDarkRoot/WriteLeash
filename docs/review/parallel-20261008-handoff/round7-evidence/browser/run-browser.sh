#!/bin/bash
set -euo pipefail
export PATH=/tmp/wlruntime/bin:$PATH
export LD_LIBRARY_PATH=/tmp/wlruntime/lib
export WP_CLI_ALLOW_ROOT=1
export WL167_SITE=/tmp/wlruntime/sites/round7-browser
export WL167_BASE_URL=http://127.0.0.1:8189
export WL167_PLAYWRIGHT_MODULE=/home/vanta/Projects/WriteLeash/wordpress/tests/admin/browser-tools/node_modules/playwright
export NODE_PATH=/home/vanta/Projects/WriteLeash/wordpress/tests/admin/browser-tools/node_modules
export WL170_OLDER_ZIP=/tmp/wlruntime/opt/woo-zips/woocommerce.11.0.1.zip
out=/home/vanta/Projects/WriteLeash/docs/review/parallel-20261008-handoff/round7-evidence/browser
for engine in chromium firefox; do
 export WL170_BROWSER="$engine" WL170_FIXTURE="$out/fixture-$engine.json" WL167_EVIDENCE="$out/170-$engine"
 WL170_MODE=seed wp --path="$WL167_SITE" eval-file /tmp/writeleash-round7-browser-rebuild/wordpress/tests/admin/regression-browser-fixture.php > "$out/seed-$engine.log" 2>&1
 start=$(date -u +%FT%TZ)
 set +e
 node /tmp/writeleash-round7-browser-rebuild/wordpress/tests/admin/regression-browser.cjs > "$out/170-$engine.log" 2>&1
 status=$?
 set -e
 end=$(date -u +%FT%TZ)
 python3 -c 'import json,sys;from pathlib import Path;Path(sys.argv[1]).write_text(json.dumps({"engine":sys.argv[2],"exit_status":int(sys.argv[3]),"start":sys.argv[4],"completion":sys.argv[5],"command":"node /tmp/writeleash-round7-browser-rebuild/wordpress/tests/admin/regression-browser.cjs","source_sha":"ad6377eaf6b68548221d3e4fae0b522b0e19f7d3","source_tree":"9278c28cdb961c0fc404202ec1ea551882b4620a","site":"/tmp/wlruntime/sites/round7-browser","database":"round7_browser","cache":"default"},indent=2))' "$out/170-$engine.receipt.json" "$engine" "$status" "$start" "$end"
 tail -3 "$out/170-$engine.log"
 [ "$status" -eq 0 ] || exit "$status"
done
