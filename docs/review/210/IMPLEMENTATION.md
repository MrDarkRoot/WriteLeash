# Issue #210 preparation handoff

Scope: translation-readiness preparation only — literal inventory tooling, tooling tests, baseline inventory evidence and preparation documentation. No production Admin PHP/JS string was converted, no shipped gettext catalog or asset was added, and no text-domain wrapping was applied. Local preparation only; founder retains GO / NO-GO. No push, PR, merge, deployment, publication, issue update or RELEASE_FULL dispatch.

| Field | Evidence |
|---|---|
| Issue | #210 — Make merchant-facing UI translation-ready |
| Base SHA | `c48e307efe7750d0fb36015c39d50a34675f9579` |
| Branch | `work/210-i18n-preparation` in `/tmp/writeleash-approved-20261008/agent-D` |
| Head SHA | Preparation commit `b09fd5f226ea45e711352b8654e3ab3f09d0d1f3`, then review-fix commit `chore(i18n): scope inventory to the public manifest and drop generated dump (#210)` (`4c7f6b2fd919b0c2a84922f15dd1b9bfdaca26fd`), then round-3 docs-note commit `docs(i18n): record round-3 preparation verification (#210)` created after the round-3 gates passed; exact SHA recorded in `/tmp/writeleash-approved-20261008/evidence/pr-fix3/222/` (a commit cannot embed its own SHA). |
| PR status | PR #222 (Draft, OPEN) on `MrDarkRoot/WriteLeash`; the review fix is pushed to the same branch; no merge/deploy/publication |
| Scope deviation | None. Inventory/tooling/tests/docs only; no active Admin PHP/JS touched; no string conversion. |
| Support claims | Unchanged. Public allowlist, package closure and existing local asset model untouched. |
| Acceptance verdict | **PARTIAL — PREPARATION COMPLETE**. Review fix P2 (oversized inventory artifact) resolved: manifest-scoped tool, deterministic text/machine classification, 1.8 MB dump removed, small summary committed. All five live acceptance criteria remain open/untested for full #210; see the criterion table. |

## Review fix P2 (2026-10-08)

Finding: `docs/i18n/baseline-literals.json` (~56,000 lines / 1.8 MB) included historical/non-shipped code, machine identifiers and false-positive candidates, creating git/review overhead.

| Requirement | Resolution |
|---|---|
| Scan only `wordpress/release/writeleash-distribution-files.txt` entries | Tool defaults to the manifest, resolves entries against the plugin root, skips `#`/blank lines and non-`.php`/`.js` entries; non-shipped PHP no longer appears (6,362 candidates vs 9,436 raw) |
| Separate merchant-facing candidates from machine identifiers | Deterministic `class` (`text`/`machine`) + `reason` per row with documented conservative heuristics (ALLCAPS codes, snake/dot/colon/kebab keys, URLs, printf-only, numbers, HTML); totals in the summary |
| Deterministic extraction, byte-identical regeneration | Explicit stable sort (`file`, `line`, sequence) + fixed JSON key order; two runs of each mode verified byte-identical |
| Document omissions; make-pot remains authority | Tool `limitations` + `PREPARATION.md` §1/§10 document interpolated PHP strings, JS template literals and JS comment-bridging; make-pot evidence retained |
| Avoid committing large generated data | `git rm docs/i18n/baseline-literals.json`; only `docs/i18n/inventory-summary.json` (7,020 bytes) committed; full inventory reproducible via the tool, raw run via `--all` |
| Focused tooling tests extended | `i18n-inventory-cases.php` now covers fixture-manifest scoping (unlisted file excluded), text/machine classification, determinism, `--all`, summary counts, missing root/manifest/listed-entry, unsafe entry, empty manifest, unknown option |
| No production source edits | Only `wordpress/release/i18n-inventory.php`, `wordpress/tests/release/i18n-inventory-cases.php`, `docs/i18n/*` changed; no Admin PHP/JS touched |

## Round-3 verification (2026-10-08)

Minimal regression check on the existing branch (previous HEAD `4c7f6b2fd919b0c2a84922f15dd1b9bfdaca26fd`, `origin/main` `c48e307efe7750d0fb36015c39d50a34675f9579` unchanged; no maintainer comments, no third-party fixes). No tooling defect (failing test, nondeterminism, manifest mis-scoping) was demonstrated, so the tool, tests, manifest and committed summary were left untouched; only this documentation was updated.

| Check | Result |
|---|---|
| `php wordpress/tests/release/i18n-inventory-cases.php` | **PASS** — full case list, exit 0 |
| Determinism, 2 runs per mode (`cmp` byte-identical) | `--summary` `9cf090ec…0256a5` (equals committed `docs/i18n/inventory-summary.json`); full `0e62bb58…b5a46c`; `--all` `06223803…f5168ed` |
| `--all` reproduces the pre-rework raw population | **PASS** — byte-identical to round-2 evidence `inventory-source-tree-raw.json`; 9,436 candidates / 60 files |
| Manifest scoping | **PASS** — default run scanned exactly the 38 allowlisted PHP/JS files (42 manifest entries, 4 non-PHP/JS skipped) and zero non-allowlisted files; root/manifest defaults resolve from the tool location (cwd-independent) |
| No gettext conversion / machine translation / runtime modification | **PASS** — branch diff vs `origin/main` is tooling/tests/docs only; zero files under `wordpress/writeleash/`; no gettext call added to any production path |
| Changed-file accounting for the final conversion inventory | Documented in `docs/i18n/PREPARATION.md` §8.1: `class-product-selector.php`, `class-free-admin.php` and `free-selection.js` are existing manifest entries scanned automatically; `free-progress.js` is added to the manifest by PR #221 and is auto-included once #221 merges (fixture-verified), so #222 needs no further change |
| `python3 .github/ci/ownership.py --audit` | **PASS** |
| `bash .github/ci/pr-fast.sh` | **PASS** — `#133 PR_FAST PASS` |

The inventory remains a candidate aid, not a catalog; #210 is still **PARTIAL — PREPARATION COMPLETE**. Evidence: `/tmp/writeleash-approved-20261008/evidence/pr-fix3/222/`.

## What was prepared

`i18n-inventory.php` (default mode) reads `wordpress/release/writeleash-distribution-files.txt`, resolves its plugin-root-relative entries against the source root, skips blank/`#`/non-PHP-JS entries, and emits raw literal candidates only for allowlisted files: `T_CONSTANT_ENCAPSED_STRING` tokens for PHP (with source line) and a conservative quote-pair scan for JS. `--summary` emits only the small review artifact; `--all` restores the superseded source-tree-wide scan. It emits a JSON envelope (`format`, `purpose: literal-review-candidates-not-gettext-catalog`, `text_domain: writeleash`, `source` mode/root/manifest metadata, classification totals + documented heuristics, declared limitations, `candidates` with `file`/`line`/`literal`/`class`/`reason`). It is explicitly not a gettext extractor and makes no completeness claim.

Current file inventory (SHA-256):

| File | SHA-256 | Role |
|---|---|---|
| `wordpress/release/i18n-inventory.php` | `bb5d0411a888529c229a501b3bae7cc96d23853daef2fd5c97a081e9e3b5fe01` | Manifest-scoped deterministic inventory + classification |
| `wordpress/tests/release/i18n-inventory-cases.php` | `3a6a1600e19322fb4f68a3c4dd3ae5d2eeac0b404fb1791a8672424a5f6bbb4b` | Extended tooling tests |
| `docs/i18n/inventory-summary.json` | `9cf090ecb01483cc1034854ce19d3dff45e30bab334b6229400d965a510256a5` | Committed small summary (7,020 bytes; supersedes the deleted 1,797,569-byte dump) |
| `docs/i18n/PREPARATION.md` / `docs/review/210/IMPLEMENTATION.md` | — | Preparation strategy + this report |
| `docs/i18n/baseline-literals.json` | deleted (`git rm`) | Previous oversized dump; full inventory now reproducible on demand |

The manifest-scoped baseline contains 6,362 candidates (6,231 PHP / 131 JS) across the 38 allowlisted PHP/JS files; 1,735 distinct; classification totals 2,479 `text` / 3,883 `machine`. The 22 non-shipped PHP files that contributed 3,074 candidates to the old dump are excluded by construction; `--all` still reproduces the exact 9,436-candidate raw run. Classification, hot spots and known noise/gaps are documented in `docs/i18n/PREPARATION.md` §2 and §10: machine keys/codes dominate; 889 allowlisted rows are sentence-like; 134 carry printf placeholders; 103 hit accessibility keywords. Two accuracy caveats are recorded rather than hidden: the JS scan includes comment text and can bridge across code (false positives and possible false negatives — make-pot is the JS extraction authority), and the conservative classifier leaves single lowercase words as `text` for reviewer triage.

WP-CLI evidence (not an invented gettext parser): official `wp-cli.phar` 2.12.0 fetched to `/tmp`, sha512 verified against `wp-cli/builds` before use (`be928f6b…fe8832`). `i18n make-pot` on the baseline plugin source exits 0 and produces a 989-byte POT with **only 4 entries, all plugin header** (`Plugin Name`/`Description`/`Author`/`Author URI`) — expected, because the shipped source has zero gettext calls. A `/tmp` fixture containing `__()`, `_e()`, `_n()`, `_x()`, `esc_html__()`, `esc_attr_e()` and JS `wp.i18n` calls extracts 13 entries including 2 `msgid_plural` pairs, 1 `msgctxt` ("Apply", button label, PHP+JS merged) and a round-tripped `#. translators:` comment; make-pot's 5 placeholder warnings demonstrate the translators-comment audit. The fixture is tooling evidence only and is not committed (PR_FAST is network-free; adding a WP-CLI download step to CI would violate that policy). No WP-CLI artifact is added to the repository.

## Acceptance criteria

`PASS` below is restricted to the executed tooling/static checks. Full #210 acceptance is not claimed.

| Live issue criterion | Preparation evidence | Full acceptance |
|---|---|---|
| Generated catalog captures runtime/Admin/JS/accessibility strings under `writeleash`; plural/context extraction correct | PARTIAL: manifest-scoped literal inventory + committed summary + documented extraction strategy; make-pot on current source yields 4 header-only msgids; fixture demonstrates `_n`/`_x`/`esc_html__`/JS extraction | NOT_TESTED: no converted production strings and no real catalog for the shipped source |
| Controlled non-English/pseudo-translation exercises selection/Preview/progress/errors/conflicts/History/Undo incl. 0/1/many and long labels | NOT_TESTED: no catalog, no pseudo-locale build, no runtime | BLOCKED: Docker daemon absent (socket missing; no sudo), no browser, and #204/#206 UI unmerged |
| Translations do not alter canonical prices/IDs/plan/journal/CSV keys; old plans hydrate | DESIGN ONLY: never-translate list documents codes/IDs/hashes/canonical money/CSV keys; no production string changed in this lane | NOT_TESTED: requires translated runtime and plan hydration fixtures after conversion |
| Existing keyboard/browser checks cover touched flows; no focus/name loss | NOT_TESTED: no touched flow in this lane; accessible-name conversion guidance only | BLOCKED: Docker/browser unavailable; #204 live-progress accessibility changes unmerged |
| Catalog/asset additions use the public release allowlist and local asset model | DOCUMENTED: `writeleash-distribution-files.txt` + package/inventory/public/source audits + `plugins_url()`/`wp_set_script_translations()` path, no CDN | NOT_TESTED: no catalog/asset added by this lane (deliberately) |

## Executed validation

- `php wordpress/tests/release/i18n-inventory-cases.php` — **PASS** (`i18n literal inventory: PASS (manifest scoping, PHP comments, source lines, JS candidates, text/machine classification, summary counts, determinism, --all raw scan, missing root/manifest/entry, unsafe entry, empty manifest, unknown option)`).
- `php -l wordpress/release/i18n-inventory.php` and `php -l wordpress/tests/release/i18n-inventory-cases.php` — **PASS**, no syntax errors.
- Deterministic regeneration (two runs each; byte-identical): committed summary SHA-256 `9cf090ec…0256a5` (6,362 candidates / 38 files; 2,479 text / 3,883 machine); full manifest-scoped inventory `0e62bb58…b5a46c` (non-shipped files absent); legacy `--all` raw scan `06223803…f5168ed` (**9,436** candidates across 60 files — exact reproduction of the pre-rework run).
- Manifest scoping audit: 42 manifest entries read (4 non-PHP/JS skipped); zero non-allowlisted files in the default output; fixture test proves an unlisted PHP file is excluded and reappears only under `--all`.
- `python3 .github/ci/ownership.py --audit` — **PASS**; new paths classify PR_FAST-only (`wordpress/release/**`, `wordpress/tests/release/*.php`, `docs/**`) and no policy rule was weakened.
- `bash .github/ci/pr-fast.sh` — **PASS**, `#133 PR_FAST PASS`; this lane changes no runtime source/asset/package file, so the unchanged #170/asset and all release audits pass exactly as at baseline. No first failure exists to report; no gate was edited.
- `php /tmp/opencode/wp-cli.phar i18n make-pot wordpress/writeleash … --domain=writeleash` — exit 0, 989-byte POT, 4 header-only msgids (see above).
- `php /tmp/opencode/wp-cli.phar i18n make-pot /tmp/opencode/i18n-fixture …` — exit 0, 13 entries with plurals/context/JS/translator comment (see above).

## Graph, localization and integration notes

- GitNexus (`GITNEXUS_HOME=$PWD/.gitnexus-home`, CLI 1.6.12): re-indexed after the review fix (`analyze --index-only` → 3,268 nodes / 9,734 edges / 237 flows). Pre-edit file-level `impact` on `i18n-inventory.php` returned `risk: UNKNOWN` (no resolved callers; a text search confirms the only reference is the test invoking the tool by path — no production include/require or runtime caller). Post-edit `impact writeleash_i18n_classify --direction upstream` is `LOW` / `exact` (1 direct caller: the script's own main body) with no process/module membership; the new functions are tooling-local.
- `detect_changes` `--scope all` receipt (complete, no partial/truncation) covers the 5 changed paths / 23 symbols with 0 affected processes and `risk low`; `--scope staged` is re-run after staging and both receipts are recorded in `/tmp/writeleash-approved-20261008/evidence/pr-fix/222/` (`detect-changes-all.json`, `detect-changes-staged.json`).
- Localization/content ownership: no production owner is consumed by this lane; `wordpress/release/**` and `wordpress/tests/release/*.php` are PR_FAST-only under the existing map. No shared production file (`class-free-admin.php`, `free-selection.js`) was touched, so there is no collision with A (#206) or C (#204) here; conversion must rebase on the accepted UI set.
- Safety: no plan/job/journal/Undo/price/conflict/Resume code path is modified; inventory is read-only. `inventory-summary.json` is not shipped and is not in the distribution allowlist; `baseline-literals.json` was deleted and is likewise not in the allowlist.

## Unrun validation and blockers

Docker daemon is not running (`unix:///var/run/docker.sock` absent, no passwordless sudo) and no browser harness is available, so native extraction against a runtime locale, pseudo/non-English full-flow checks, 0/1/many and long-label rendering, keyboard/focus/announcement checks and plan-hydration-after-translation are **NOT_TESTED / BLOCKED**, not PASS. No privileged/network/Docker workaround was attempted. The real catalog depends on final gettext conversion of the accepted UI, which this lane deliberately did not perform; #204/#206 (and any selected #207/#208) strings are unmerged and the manifest-scoped inventory does not certify them. The inventory remains a candidate list, not an extractor: PHP interpolated strings/heredocs, JS template literals and JS comment-bridging false positives/negatives are documented limitations, and the `text`/`machine` classification is a conservative heuristic, not a reviewed merchant-visibility decision. Manifest scoping is only as accurate as the allowlist file it reads; `--all` reproduces the superseded raw run for comparison only. RELEASE_FULL is neither authorized nor dispatched.

## Founder attention

Review the documented caveats before scheduling conversion: conservative classification heuristics and still-unclassified single-word `text` rows, JS candidate limits, and the never-translate boundary. Review fix P2 is resolved: the 1.8 MB dump is gone, the default inventory is manifest-scoped to the public distribution, and the only committed generated artifact is the 7,020-byte `inventory-summary.json` (regenerate with `php wordpress/release/i18n-inventory.php --summary`). #210 remains **PARTIAL** — preparation complete, implementation (conversion + catalog + pseudo-locale/browser acceptance) not started. No tool monkeypatch, ignored error, gate edit or sandbox bypass was used.
