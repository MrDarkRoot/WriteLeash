# Issue #211 implementation — Accurate Woo metadata & exact review package

## Candidate identity

| Field | Value |
|---|---|
| Branch | `work/211-woo-exact-review-package` |
| Base (fetched `main`) | `f02db253cfb08dc236f19ad4a62c567afa7be1b0` (PR #225 merge) |
| Candidate source SHA | `5be1ba3bcea3efb9546c3c6dd3987f5be46e4285` |
| Proposed candidate version | `0.2.0` — **awaiting founder authorization; not a frozen RC** |
| ZIP basename | `writeleash-0.2.0.zip` |
| ZIP SHA-256 | `08f2128e2f8df2869e4810b9e8df5762ac845d088c9776fa2f4aa5ed40afad79` |
| ZIP size | 2029422 bytes, 45 files |
| Manifest SHA-256 | `f83c628b732ba9688a5fa73063d94cf6689c78864a5e9` (`wordpress/release/writeleash-woo-distribution-files.txt`) |
| Runtime tree hash | `0e6e07fd9d72ea24aaaba2830caca7b8eb1668e9a215eadbb6b40089dc8b4668` |
| Builder | `python3 wordpress/release/build-woo-candidate.py --source source --sha <SHA> --output candidate` |
| Tools | Python 3.12.3, stdlib zipfile ZIP_STORED, git 2.43.0, PHP 8.2.32 |

Reproducibility: three independent clean-worktree builds (two at `052e7b1`,
one at `5be1ba3`) produce byte-identical ZIPs (`cmp` clean, same SHA-256).
`5be1ba3` adds only `candidate-install-verify.php` (test tooling, outside the
allowlist); the identical ZIP across both SHAs proves payload stability.
`PYTHONDONTWRITEBYTECODE=1` is required: the builder imports the historical
module, and an emitted `__pycache__` would trip the clean-tree gate by design.

## Compatibility claims (declared vs tested)

| Platform | Minimum supported | Highest actually tested | Evidence |
|---|---|---|---|
| WordPress | 7.0 | 7.1.2 | Acceptance rows 7.0.1 + 7.1.2 PASS (`tests/acceptance/EVIDENCE.md`); current-core gate pins 7.1.2 image digest; Woo 11.1.2 itself requires WP 7.0 (verified from pinned ZIP below) |
| WooCommerce | 10.0.0 | 11.1.2 | Full PASS rows only on pinned 11.1.2 (`9de9350a…8e9e`, re-downloaded and hash-verified 2026-10-09); #209 source-verified identical public factory API on 9.9.7/11.0.1/11.1.2; `Free_Support_Contract` 10.0.0–12.0.0 with fail-closed gate |
| PHP | 7.4 | 8.2.34 (7.4.33, 8.0.30, 8.1.34 exercised) | Acceptance matrix rows; activation refuses < 7.4 |
| MySQL / MariaDB | 8.0.44 / 10.11.15 | same | Every harness asserts the exact server version |

Headers declare `WC requires at least: 10.0`, `WC tested up to: 11.1`,
`Requires at least: 7.0`, `Tested up to: 7.1`, `Requires PHP: 7.4`.
Gaps (not implied as coverage): no end-to-end PASS row on Woo 10.0.x–11.0.x,
no WP 7.0.x beyond 7.0.1, no PHP 8.3+, no real-host pilot, multisite
unsupported. Minimums rest on the shipped version gate plus stable-API
analysis, not on a per-version matrix — stated here, not hidden.

## Metadata decisions

- `Plugin URI: https://github.com/MrDarkRoot/WriteLeash` (existing real repo).
- `Developer`/`Developer URI` mirror the real Author identity; no invented data.
- No `Woo:` line: the identifier is `productId:productSlug`, assigned
  automatically on vendor submission. The example-header doc permits optional
  pre-upload inclusion, but current QIT validation auto-adds it and a
  pre-submission candidate has no product ID to supply — supplying one would
  be fabrication. The builder and `woo-candidate.php` both fail on its presence.
- No Marketplace product URL anywhere; the test rejects `woocommerce.com`
  product paths in the header.
- `Requires Plugins: woocommerce`, HPOS `custom_order_tables`, text domain,
  GPL attribution and slug identity preserved. No Cart/Checkout Blocks claims.

## Package contents

- Included: 45-file Free runtime closure (37 PHP incl. #204 progress client,
  #205/#206/#209 Admin-selector/cache paths), `free-selection.js/css`,
  `free-progress.js`, `admin-logo.png`, `languages/writeleash.pot` (#210),
  `readme.txt`, `LICENSE`, `uninstall.php`, new `changelog.txt`.
- Newly allowed: only `changelog.txt` (45 vs live 44 vs historical 42; the two
  live deltas are the accepted #204 JS and #210 POT, already audited).
- Excluded: research docs, contracts, CI, tests, fixtures, screenshots,
  credentials, git metadata, temp ZIPs, dev dependencies — enforced by
  `scan()` + `public-runtime-audit` + `--exact` closure check.
- Historical `build-wordpress-org.py`, v0.1 manifest and #194 receipts
  byte-untouched (`git status` clean on those paths; default-mode preflight
  still pins 0.1.0).

## Reproduction

```sh
git fetch origin && git checkout work/211-woo-exact-review-package
SHA=$(git rev-parse HEAD)
git worktree add /tmp/wl-a $SHA && git worktree add /tmp/wl-b $SHA
export PYTHONDONTWRITEBYTECODE=1
python3 wordpress/release/build-woo-candidate.py --source /tmp/wl-a --sha $SHA --output /tmp/cand-a
python3 wordpress/release/build-woo-candidate.py --source /tmp/wl-b --sha $SHA --output /tmp/cand-b
sha256sum /tmp/cand-a/writeleash-0.2.0.zip /tmp/cand-b/writeleash-0.2.0.zip  # must match
php wordpress/tests/release/candidate-install-verify.php /tmp/cand-a/writeleash-0.2.0.zip /tmp/cand-a/evidence.json /tmp/site-a
bash .github/ci/pr-fast.sh
```

Install semantics: fresh `wp-content/plugins/`, unzip preserving archive
paths (WordPress `unzip_file` equivalent), 45 files byte-equal to evidence
hashes, core `get_file_data` algorithm parses v0.2.0, `php -l` clean, HPOS
and uninstall guards intact, Woo-absent path returns
`woocommerce_unavailable` without fatal (installed-tree class smoke PASS).
Full DB-backed activation (present/active/missing Woo, Admin entry,
representative workflow) runs in hosted CI on this PR; no local MySQL/Docker
exists here, so local activation is PARTIAL by environment, not by code.

## Acceptance accounting (original #211 criteria)

1. Headers/readme/changelog accurate, gaps recorded — **PASS**.
2. No manual `Woo`, no fake product URL, deps/HPOS/license intact — **PASS**.
3. Two clean builds byte-identical; payload excludes non-public material — **PASS**.
4. Upload-semantics install + content match succeed; DB-backed activation via hosted CI — **PARTIAL** (local half PASS, CI pending).
5. v0.1 artifacts unchanged; distinct candidate checksum handed off — **PASS**.
6. No business-approval claim, no submission — **PASS**.

## Explicit exclusions

- Historical v0.1 receipts unchanged. #207/#208 not included (test rejects
  their keywords in the changelog). Official QIT belongs to #212; local PASS
  is not certification. No merge, release, submission, vendor application or
  issue closure. Version `0.2.0` is a documented proposal, not a frozen RC.
- Pricing authority, selection semantics, recovery and journal storage
  untouched; `detect_changes --scope all`: complete, low risk, test-only
  symbols. No migrations introduced.

## #212 handoff

Exact artifact: `writeleash-0.2.0.zip`, SHA-256
`08f2128e2f8df2869e4810b9e8df5762ac845d088c9776fa2f4aa5ed40afad79`,
from source `5be1ba3bcea3efb9546c3c6dd3987f5be46e4285`, 45 files, tree hash
`0e6e07fd…`. QIT prerequisites (vendor access/credentials) remain downstream.
