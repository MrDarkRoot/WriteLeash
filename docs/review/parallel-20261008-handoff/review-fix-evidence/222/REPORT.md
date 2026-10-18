# PR #222 review-fix evidence — P2 oversized inventory artifact

- Clone: `/tmp/writeleash-approved-20261008/agent-D`
- Branch: `work/210-i18n-preparation`
- Base: `c48e307efe7750d0fb36015c39d50a34675f9579`
- Previous HEAD: `b09fd5f226ea45e711352b8654e3ab3f09d0d1f3`
- New HEAD / pushed: `4c7f6b2fd919b0c2a84922f15dd1b9bfdaca26fd`
- Commit: `chore(i18n): scope inventory to the public manifest and drop generated dump (#210)`
- Push: `git push origin work/210-i18n-preparation` → `b09fd5f..4c7f6b2` (no force)
- PR #222 stayed Draft; description updated via REST (`gh pr edit` hit the Projects-classic GraphQL deprecation). CI on new HEAD: PR_FAST pass, CI_COVERAGE pass.

## Finding resolution

| Requirement | Result |
|---|---|
| Scan only manifest allowlist | Default manifest `wordpress/release/writeleash-distribution-files.txt`; second CLI arg accepted; `#`/blank skipped; resolves plugin-root-relative; missing/unsafe/empty handled with exit 2/3/4/5 |
| Separate text vs machine | Deterministic `class` + `reason`; 11 documented heuristic reasons; conservative (`text` default) |
| Deterministic extraction | Stable sort(`file`,`line`,`seq`) + fixed JSON key order; all modes byte-identical across runs |
| Document omissions | Tool `limitations` + PREPARATION §1/§10 (PHP interpolation/heredoc, JS template literals, JS comment-bridging); make-pot authority |
| Avoid committing large generated data | `git rm docs/i18n/baseline-literals.json`; only `docs/i18n/inventory-summary.json` committed; full/raw reproducible on demand |
| Extend tooling tests | Manifest scoping, classification, determinism, `--all`, summary counts, missing root/manifest/entry, unsafe entry, empty manifest, unknown option |
| No production source edits | Changed paths: `wordpress/release/i18n-inventory.php`, `wordpress/tests/release/i18n-inventory-cases.php`, `docs/i18n/*`, `docs/review/210/*` only |

## Before / after

| Metric | Before | After |
|---|---|---|
| Committed inventory artifact | `baseline-literals.json` 1,797,569 B / 56,628 lines | `inventory-summary.json` 7,020 B / 263 lines (−99.6%) |
| Candidates | 9,436 (60 files) | 6,362 allowlisted (38 files), 1,735 distinct |
| Non-shipped PHP candidates | 3,074 (22 files) | 0 by construction |
| Classification | `review: unclassified` on every row | 2,479 `text` / 3,883 `machine` |
| Legacy raw run | not reproducible through tool | `--all` reproduces exactly 9,436 |

## Classification totals (allowlisted)

`key` 1,895 · `code` 998 · `html` 320 · `empty` 261 · `symbols-only` 152 · `identifier` 114 · `numeric` 75 · `path` 45 · `selector` 17 · `url` 3 (`php://temp/…`, `//u` ×2) · `format-placeholder` 3. Text review candidates 2,479 (889 sentence-like, 134 printf-bearing, 103 a11y-keyword).

## Regeneration hashes (two runs each, byte-identical; `cmp` verified)

| Mode | SHA-256 | Notes |
|---|---|---|
| `--summary` (committed) | `9cf090ecb01483cc1034854ce19d3dff45e30bab334b6229400d965a510256a5` | 6,362 / 38 files |
| default (full allowlisted) | `0e62bb58dc9512267809c486c30d4db6caf74ec5a914ae31a3e338b8fdb5a46c` | non-shipped absent |
| `--all` (legacy raw) | `06223803d739357149b876fc87f63ed44ade9908e70b4d38845d1b2dcf5168ed` | exactly 9,436 / 60 files |

Source hashes: tool `bb5d0411a888529c229a501b3bae7cc96d23853daef2fd5c97a081e9e3b5fe01`; tests `3a6a1600e19322fb4f68a3c4dd3ae5d2eeac0b404fb1791a8672424a5f6bbb4b`.

## Validation results

- `php wordpress/tests/release/i18n-inventory-cases.php` — PASS (all extended cases)
- `php -l` both tool files — PASS
- `python3 .github/ci/ownership.py --audit` — PASS
- `bash .github/ci/pr-fast.sh` — PASS (`#133 PR_FAST PASS`, exit 0)
- GitHub CI on `4c7f6b2`: PR_FAST pass, CI_COVERAGE pass
- GitNexus: pre-edit file impact `UNKNOWN` (text-confirmed only test-by-path invocation); post-edit `writeleash_i18n_classify` upstream impact LOW/exact (1 tooling-local caller); `detect_changes --scope all` and `--scope staged` complete, risk low, 0 affected processes; full symbol enumeration in `changed-symbols-full.json`

## Remaining limitations (unchanged, honest)

- Runtime/locale NOT_TESTED: no catalog conversion, no pseudo/non-English flows, no browser/keyboard evidence, no plan-hydration-after-translation (Docker/browser absent; #204/#206 UI unmerged).
- Inventory is a candidate aid: PHP interpolated/heredoc strings and JS template literals not captured; JS comment-bridging false positives/negatives; classification is conservative heuristics, not merchant-visibility review.
- Manifest scoping is only as accurate as the allowlist file; `--all` is comparison-only.
