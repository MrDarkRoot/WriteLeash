# PR #222 round-3 verification — issue #210 i18n preparation

- Clone: `/tmp/writeleash-approved-20261008/agent-D`
- Branch: `work/210-i18n-preparation`
- Base: `c48e307efe7750d0fb36015c39d50a34675f9579` (`origin/main`, unchanged)
- Previous HEAD: `4c7f6b2fd919b0c2a84922f15dd1b9bfdaca26fd`
- New HEAD / pushed: `079f5dad148a8718586db05b4abc322047908710`
- Commit: `docs(i18n): record round-3 preparation verification (#210)` (docs only: `docs/i18n/PREPARATION.md`, `docs/review/210/IMPLEMENTATION.md`)
- Push: `git push origin work/210-i18n-preparation` → `4c7f6b2..079f5da` (fast-forward, no force)
- PR #222 stayed **Draft/OPEN**; description updated with the round-3 summary via REST (preserved round-2 body); head on GitHub = `079f5da`
- GitHub CI on `079f5da`: **PR_FAST pass (25s)**, **CI_COVERAGE pass (5s)**; other suites skipping as expected
- No maintainer comments, no third-party fixes; no tooling defect demonstrated, so tool/tests/manifest/summary were not changed

## 1. Regression checks

| Check | Result |
|---|---|
| `php wordpress/tests/release/i18n-inventory-cases.php` | **PASS** — `i18n literal inventory: PASS (manifest scoping, PHP comments, source lines, JS candidates, text/machine classification, summary counts, determinism, --all raw scan, missing root/manifest/entry, unsafe entry, empty manifest, unknown option)`, exit 0 |
| `php -l` on tool + tests | **PASS** — no syntax errors |
| Determinism: 2 runs per mode, `cmp` byte-identical | `--summary` `9cf090ecb01483cc1034854ce19d3dff45e30bab334b6229400d965a510256a5` (= committed `docs/i18n/inventory-summary.json`); full manifest-scoped `0e62bb58dc9512267809c486c30d4db6caf74ec5a914ae31a3e338b8fdb5a46c`; `--all` `06223803d739357149b876fc87f63ed44ade9908e70b4d38845d1b2dcf5168ed` |
| `--all` reproduces the pre-rework raw population | **PASS** — byte-identical (`cmp`) to round-2 evidence `inventory-source-tree-raw.json`; **9,436 candidates / 60 files** |
| Defaults to public distribution manifest | **PASS** — `source.mode=distribution-manifest`, `root=wordpress/writeleash`, `manifest=wordpress/release/writeleash-distribution-files.txt`, `manifest_entries=42`, `scanned_files=38`; cwd-independent (run from `/tmp` gives the same result) |
| Scans only allowlisted PHP/JS entries | **PASS** — full output files ⊆ 38 manifest `.php`/`.js` entries; zero non-allowlisted files; all 38 allowlisted files present |
| Committed summary matches regeneration | **PASS** — `cmp` identical (`9cf090ec…0256a5`, 6,362 candidates / 38 files; 2,479 `text` / 3,883 `machine`) |

Hash file: `regeneration-hashes.txt`; full raw files: `inventory-summary-run-a.json`, `inventory-allowlisted-full.json`, `inventory-source-tree-raw.json`.

## 2. No gettext conversion / machine-code translation / runtime modification

- `git diff --name-status origin/main...HEAD`: 5 added files only — `docs/i18n/PREPARATION.md`, `docs/i18n/inventory-summary.json`, `docs/review/210/IMPLEMENTATION.md`, `wordpress/release/i18n-inventory.php`, `wordpress/tests/release/i18n-inventory-cases.php`.
- `git diff origin/main...HEAD -- wordpress/writeleash/` is empty: **zero production plugin files touched**; no file added to the distribution allowlist; no gettext call added to any production path.
- The tool/test files are PR_FAST-only tooling (`wordpress/release/**`, `wordpress/tests/release/*.php`); no runtime hook/include changes.

## 3. Changed-file accounting for the eventual final #210 conversion inventory

| Conversion file | Manifest entry (line) | Inventory status |
|---|---|---|
| `class-product-selector.php` | `includes/free/class-product-selector.php` (L28) | scanned automatically — 90 candidates (40 text / 50 machine) |
| `class-free-admin.php` | `includes/free/class-free-admin.php` (L12) | scanned automatically — 2,054 candidates (861 / 1,193) |
| `free-selection.js` | `includes/free/free-selection.js` (L43) | scanned automatically — 131 candidates (91 / 40) |
| `free-progress.js` | added by PR #221 (`includes/free/free-progress.js`, file + manifest entry) | auto-included once #221 merges; not in this branch (0 rows in default run) |

Reasoning verified against the tool's manifest-driven design (it re-reads the manifest at runtime and scans every existing `.php`/`.js` entry) with a fixture (`manifest-fixture-after-221.txt` / `-output.json`): a manifest without the entry scans 2 files; adding `free-progress.js` to the manifest scans 3 and emits its rows (`'Progress label'` → text). No #222 tool/manifest change is needed.

Documented in `docs/i18n/PREPARATION.md` §8.1 (plus a round-3 note in §9) and `docs/review/210/IMPLEMENTATION.md` ("Round-3 verification"). The inventory is a candidate aid, not a catalog; **#210 remains PARTIAL — PREPARATION COMPLETE**.

## 4. Defects and fixes

None demonstrated: no failing test, no nondeterminism, no manifest mis-scoping. No tool/test/manifest/summary edit was made; only the two docs files were changed. No production Admin/PHP/JS source edited.

## 5. Gates and graph receipts

- `python3 .github/ci/ownership.py --audit` — **PASS** (exit 0) — `ownership-audit.log`.
- `bash .github/ci/pr-fast.sh` — **PASS**, `#133 PR_FAST PASS` (exit 0) — `pr-fast.log`.
- GitNexus (clone-local, `GITNEXUS_HOME=$PWD/.gitnexus-home`, CLI 1.6.12; re-indexed at `4c7f6b2` → 3,268 nodes / 9,734 edges / 237 flows before edits): pre-edit file-level `impact` on `docs/i18n/PREPARATION.md` = 0 resolved callers (file target, no process/community membership; docs-only edit); `detect-changes --scope all` and `--scope staged` = **complete** (no partial/truncated), 2 files / 4 section symbols, 0 affected processes, risk low — `detect-changes-all.json`, `detect-changes-staged.json`.
- GitHub CI on `079f5da`: PR_FAST pass, CI_COVERAGE pass.

## 6. Deliverable summary

- Changed files: `docs/i18n/PREPARATION.md`, `docs/review/210/IMPLEMENTATION.md` (docs only).
- Commit/pushed SHA: `079f5dad148a8718586db05b4abc322047908710` (fast-forward push, PR Draft kept).
- Determinism hashes: `9cf090ec…0256a5` / `0e62bb58…b5a46c` / `06223803…f5168ed`.
- `pr-fast`: PASS locally and on GitHub.
- #210 status: **PARTIAL — PREPARATION COMPLETE** (unchanged).
