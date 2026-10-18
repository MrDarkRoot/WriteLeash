Refs #210 — **PARTIAL: preparation only. Draft; do not merge; founder GO/NO-GO pending.**

Base: `c48e307efe7750d0fb36015c39d50a34675f9579` · Previous HEAD: `b09fd5f226ea45e711352b8654e3ab3f09d0d1f3` · New HEAD: `4c7f6b2fd919b0c2a84922f15dd1b9bfdaca26fd`
Round-3: previous HEAD `4c7f6b2fd919b0c2a84922f15dd1b9bfdaca26fd` → new HEAD `079f5dad148a8718586db05b4abc322047908710` (docs-only note, fast-forward, PR stays Draft)

## Round-3 verification (2026-10-08)
- **Tooling regression**: `php wordpress/tests/release/i18n-inventory-cases.php` **PASS** (exit 0, full case list).
- **Determinism** (2 runs per mode, `cmp` byte-identical): `--summary` `9cf090ec…0256a5` (equals committed `docs/i18n/inventory-summary.json`); full manifest-scoped `0e62bb58…b5a46c`; `--all` `06223803…f5168ed` — byte-identical to the round-2 raw evidence and reproduces the pre-rework **9,436 candidates / 60 files**.
- **Manifest scoping**: default run scanned exactly the 38 allowlisted PHP/JS files (42 manifest entries, 4 non-PHP/JS skipped) and zero non-allowlisted files; root/manifest defaults resolve from the tool location (cwd-independent).
- **No conversion/runtime change**: branch diff vs `origin/main` is tooling/tests/docs only; zero files under `wordpress/writeleash/`; no gettext call added to any production path.
- **Changed-file accounting**: `class-product-selector.php`, `class-free-admin.php` and `free-selection.js` are existing distribution-manifest entries → scanned automatically; `free-progress.js` is added to the manifest by PR #221 → auto-included once #221 merges (fixture-verified: manifest without entry scans 2 files, with entry 3; no #222 change needed). Documented in `docs/i18n/PREPARATION.md` §8.1 and `docs/review/210/IMPLEMENTATION.md`.
- **Gates**: `python3 .github/ci/ownership.py --audit` **PASS**; `bash .github/ci/pr-fast.sh` **PASS** (`#133 PR_FAST PASS`). CI re-runs on `079f5da`.
- **No tooling defect** (failing test, nondeterminism, manifest mis-scoping) was demonstrated; tool, tests, manifest and committed summary are untouched by this re-check.
- Status unchanged: **#210 PARTIAL — PREPARATION COMPLETE**; the inventory is a candidate aid, not a catalog. Evidence: `/tmp/writeleash-approved-20261008/evidence/pr-fix3/222/`.

## Review fix (P2 — oversized inventory artifact) — resolved in this push
- **Manifest scoping**: `wordpress/release/i18n-inventory.php` now defaults to `wordpress/release/writeleash-distribution-files.txt` (second CLI arg accepted), resolves plugin-root-relative entries against the source root and scans only `.php`/`.js` allowlist entries. Non-shipped PHP can no longer appear.
- **Classification**: deterministic, documented `text` vs `machine` per row (`class` + `reason`) with conservative heuristics (ALLCAPS codes, snake/dot/colon/kebab keys, URLs, printf-only, numbers, HTML, symbols, selectors, identifiers); per-file counts and reason totals live in the summary.
- **Deterministic**: explicit stable sort (`file`, `line`, encounter) + fixed JSON key order; two runs of every mode byte-identical.
- **Dump removed**: `git rm docs/i18n/baseline-literals.json` (56,628 lines / 1,797,569 bytes). Only `docs/i18n/inventory-summary.json` (7,020 bytes / 263 lines, **~99.6% smaller**) is committed.
- **Documented omissions**: interpolated PHP strings/heredocs, JS template literals, JS comment-bridging, classifier conservatism; WP-CLI `i18n make-pot` remains the extraction authority. WP-CLI evidence retained in docs; no WP-CLI artifacts committed.
- **Tests extended** (`wordpress/tests/release/i18n-inventory-cases.php`): fixture-manifest scoping (unlisted file excluded), text/machine classification, summary counts, determinism, `--all` raw scan, missing root/manifest/listed-entry, unsafe entry, empty manifest, unknown option.
- **No production source edits**; no Admin source owned by other PRs touched.

## Artifacts and counts
| Artifact | Before | After |
|---|---|---|
| Committed inventory data | `docs/i18n/baseline-literals.json` 1,797,569 B / 56,628 lines | `docs/i18n/inventory-summary.json` 7,020 B / 263 lines |
| Candidates | 9,436 raw (60 files; 3,074 non-shipped) | 6,362 allowlisted (38 files), 1,735 distinct |
| Classification | every row `review: unclassified` | 2,479 `text` / 3,883 `machine` |

Machine reasons: `key` 1,895; `code` 998; `html` 320; `empty` 261; `symbols-only` 152; `identifier` 114; `numeric` 75; `path` 45; `selector` 17; `url` 3 (all non-HTTP: `php://temp/…`, `//u` regex modifiers); `format-placeholder` 3.

Regeneration hashes (full/raw are reproducible on demand, **not** committed):
- summary: `9cf090ecb01483cc1034854ce19d3dff45e30bab334b6229400d965a510256a5`
- full allowlisted inventory: `0e62bb58dc9512267809c486c30d4db6caf74ec5a914ae31a3e338b8fdb5a46c`
- `--all` raw legacy: `06223803d739357149b876fc87f63ed44ade9908e70b4d38845d1b2dcf5168ed` (**exactly 9,436** — the pre-rework run stays reproducible)

## Scope (inventory/tooling/docs/tests only — no production UI edits)
- `wordpress/release/i18n-inventory.php`: manifest-scoped deterministic literal inventory + classification (`--summary` for the committed artifact, `--all` legacy raw scan). Explicitly **not** a gettext catalog.
- `wordpress/tests/release/i18n-inventory-cases.php`: extended tooling tests.
- `docs/i18n/inventory-summary.json`: small committed review artifact (per-file counts + text/machine totals).
- `docs/i18n/PREPARATION.md`: conversion strategy (`writeleash` text domain, gettext/plural/context, complete sentences, translator comments), merchant string coverage map (selection, Preview, Apply, progress, conflicts, History, export/CSV, Undo), JS `wp-i18n` + `wp_set_script_translations`, never-translate machine values, release allowlist requirements, artifact policy.
- `docs/review/210/IMPLEMENTATION.md`: acceptance accounting, **PARTIAL — PREPARATION COMPLETE**, review-fix record.
- Official WP-CLI `i18n make-pot` workflow evidence: phar 2.12.0 sha512-verified; baseline catalog header-only (source still uses raw literals, as expected); `/tmp` fixture demonstrates plurals, `msgctxt`, JS format, translator comment and placeholder warnings (fixture not committed).

## Local validation (this branch)
- Updated tooling tests **PASS**; `php -l` clean for both tool files.
- Deterministic regeneration: two runs of each of `--summary` / default / `--all` byte-identical; committed summary matches regenerated output.
- `python3 .github/ci/ownership.py --audit` **PASS**; `bash .github/ci/pr-fast.sh` **PASS** (`#133 PR_FAST PASS`).
- GitNexus: pre-edit file impact `UNKNOWN` (no resolved callers; text-confirmed only the test invokes the tool by path); post-edit `writeleash_i18n_classify` impact `LOW`/exact (1 caller, tooling-local); `detect_changes` `all` + `staged` complete, risk low, 0 affected processes.
- Support claims unchanged: public allowlist, package closure and the existing local asset model are untouched.

## NOT_TESTED (blocked) — unchanged
- Runtime/locale: native catalog generation, pseudo/non-English full flows (0/1/many, long labels), translated plan hydration, browser/keyboard accessibility — requires runtime/browser and the accepted #204–#208 UI. #210 remains **PARTIAL**.
- Inventory is a candidate aid, not an extractor: PHP interpolated strings/heredocs, JS template literals, JS comment-bridging false positives/negatives, and conservative classification remain documented limitations.

No merge/deploy/publish; issue #210 not modified. Evidence: `/tmp/writeleash-approved-20261008/evidence/pr-fix/222/` (local). Full accounting: `docs/review/210/IMPLEMENTATION.md` (this branch).
