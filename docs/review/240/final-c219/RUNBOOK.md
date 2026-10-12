# Reproduction notes

All build inputs are immutable commit `c219bc857d1f7d0a205caed3b22236fb5683c6bb`; builder worktrees A/B were clean and detached. Commands used:

```sh
python3 -B wordpress/release/build-wordpress-org-v02.py --source /tmp/wl240-c219-a --sha c219bc857d1f7d0a205caed3b22236fb5683c6bb --version 0.2.0 --output /tmp/wl240-final-v020-c219-a
python3 -B wordpress/release/build-wordpress-org-v02.py --source /tmp/wl240-c219-b --sha c219bc857d1f7d0a205caed3b22236fb5683c6bb --version 0.2.0 --output /tmp/wl240-final-v020-c219-b
cmp -s /tmp/wl240-final-v020-c219-a/writeleash-0.2.0.zip /tmp/wl240-final-v020-c219-b/writeleash-0.2.0.zip
sha256sum /tmp/wl240-final-v020-c219-a/writeleash-0.2.0.zip /tmp/wl240-final-v020-c219-b/writeleash-0.2.0.zip
PYTHONDONTWRITEBYTECODE=1 bash .github/ci/pr-fast.sh
```

The second build’s complete stdout/stderr is in `distribution/build-b-console.txt`; both builder `evidence.json` receipts include the full source/runtime/asset inventories and audit outcomes. The 10 corruption/artifact tests and 5 B1/B2/B3 controls are separate in `builder/`. Source/catalog consistency was checked in an additional clean worktree with `WP_CLI_PHAR=/home/vanta/.local/bin/wp bash wordpress/release/make-pot.sh`; the generated POT matched the packaged file byte-for-byte.

The artifact was installed with normal WP-CLI `Plugin_Upgrader` from the local ZIP after WooCommerce 11.1.2 was active, and installed hashes were compared against all 46 ZIP members. Test commands on that byte-identical install included:

```sh
wp --path=<disposable-site> eval-file wordpress/tests/admin/integration.php
wp --path=<disposable-site> eval-file wordpress/tests/durable/integration.php
wp --path=<disposable-site> eval-file wordpress/tests/admin/recovery-integration.php
wp --path=<disposable-site> eval-file wordpress/tests/admin/presets-repeat-integration.php
wp --path=<disposable-site> eval-file wordpress/tests/admin/presets-concurrency.php
node wordpress/tests/admin/preset-labels-browser.cjs
node wordpress/tests/admin/presets-browser.cjs
node wordpress/tests/admin/regression-browser.cjs
```

The existing fixture files, browser tools, and local ZIP path used at execution are in the acceptance environment records. Product/variation edits in browser scenarios went through ordinary WooCommerce APIs/Admin; the scripts read plans/prices/journals for assertions and do not create approvals or edit plan contents to fabricate outcomes.

The official scanner was installed separately from the official 2.1.0 ZIP (SHA-256 `6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4`) into a disposable store. Both scanner commands and unmodified raw output are retained. The packaged readme was submitted to the official [WordPress.org Readme Validator](https://wordpress.org/plugins/developers/readme-validator/) and its echoed bytes were compared with the ZIP readme.

Local SVN rehearsal used an isolated `file://` repository at `/tmp/wl240-c219-local-svn`; it did not contact the WordPress.org SVN server for writes. Its expected MIME-property-only working-copy diff and all per-file hashes are in `distribution/local-svn-rehearsal.json`.
