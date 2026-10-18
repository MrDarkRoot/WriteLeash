# Issue #210 preparation handoff

Scope: translation-readiness preparation only — literal inventory tooling, tooling tests, baseline inventory evidence and preparation documentation. No production Admin PHP/JS string was converted, no shipped gettext catalog or asset was added, and no text-domain wrapping was applied. Local preparation only; founder retains GO / NO-GO. No push, PR, merge, deployment, publication, issue update or RELEASE_FULL dispatch.

| Field | Evidence |
|---|---|
| Issue | #210 — Make merchant-facing UI translation-ready |
| Base SHA | `c48e307efe7750d0fb36015c39d50a34675f9579` |
| Branch | `work/210-i18n-preparation` in `/tmp/writeleash-approved-20261008/agent-D` |
| Head SHA | Preparation commit `chore(i18n): add translation inventory tooling and preparation docs (#210)` created after all gates passed; HEAD moves from base `c48e307efe7750d0fb36015c39d50a34675f9579` to that commit (exact SHA recorded in `/tmp/writeleash-approved-20261008/evidence/agent-D/` — a commit cannot embed its own SHA). |
| PR status | Not created; local review-ready preparation only |
| Scope deviation | None. Inventory/tooling/tests/docs only; no active Admin PHP/JS touched; no string conversion. |
| Support claims | Unchanged. Public allowlist, package closure and existing local asset model untouched. |
| Acceptance verdict | **PARTIAL — PREPARATION COMPLETE**. All five live acceptance criteria remain open/untested for full #210; see the criterion table. |

## Files added

- `wordpress/release/i18n-inventory.php` — SHA-256 `4e5956cb6da1a66e6ea7a6a7cb007a6e800cca76cad1556cdcffc81efd1672b6`
- `wordpress/tests/release/i18n-inventory-cases.php` — SHA-256 `90ccdb9e2a967081de7adae5ffd48bdc0b898d9d2f749e9d75f86906df9f01ae`
- `docs/i18n/baseline-literals.json` — SHA-256 `7276a68c4e71cdfceaae7549311c03d5e2774634d226b0e7157f8247239e99ac`
- `docs/i18n/PREPARATION.md` — conversion strategy, coverage map, never-translate list, JS localization, allowlist path, follow-up
- `docs/review/210/IMPLEMENTATION.md` — this report

These five files are exactly the three prepared files (hash-identical to the verified snapshot/STATE.json) plus the two required documents. No other file is staged.

## What was prepared

`i18n-inventory.php` walks the source tree (default `wordpress/writeleash`), sorts paths, and emits raw literal candidates: `T_CONSTANT_ENCAPSED_STRING` tokens for PHP (with source line) and a conservative quote-pair scan for JS. It emits a JSON envelope (`format`, `purpose: literal-review-candidates-not-gettext-catalog`, `text_domain: writeleash`, declared limitations, `candidates` with `file`/`line`/`literal`/`review: unclassified`). It is explicitly not a gettext extractor and makes no completeness claim.

The baseline contains 9,436 candidates (9,305 PHP / 131 JS) across 60 PHP/JS source files; 2,623 distinct. Heuristic classes, shipped-allowlist subset and known noise/gaps are documented in `docs/i18n/PREPARATION.md` §2 and §10: empty/numeric/HTML/machine-key noise dominates; 1,389 source-tree rows are sentence-like (736 in the 38 allowlisted PHP/JS files); 187 rows carry printf placeholders; 48 rows hit accessibility keywords. Two accuracy caveats are recorded rather than hidden: the JS scan includes comment text and can bridge across code (false positives and possible false negatives — make-pot is the JS extraction authority), and the inventory is source-tree-wide, so 22 non-shipped PHP files contribute 3,074 candidates that must not be converted for #210.

WP-CLI evidence (`i18n make-pot`, not an invented gettext parser): official `wp-cli.phar` 2.12.0 fetched to `/tmp`, sha512 verified against `wp-cli/builds` before use (`be928f6b…fe8832`). `i18n make-pot` on the baseline plugin source exits 0 and produces a 989-byte POT with **only 4 entries, all plugin header** (`Plugin Name`/`Description`/`Author`/`Author URI`) — expected, because the shipped source has zero gettext calls. A `/tmp` fixture containing `__()`, `_e()`, `_n()`, `_x()`, `esc_html__()`, `esc_attr_e()` and JS `wp.i18n` calls extracts 13 entries including 2 `msgid_plural` pairs, 1 `msgctxt` ("Apply", button label, PHP+JS merged) and a round-tripped `#. translators:` comment; make-pot's 5 placeholder warnings demonstrate the translators-comment audit. The fixture is tooling evidence only and is not committed (PR_FAST is network-free; adding a WP-CLI download step to CI would violate that policy).

## Acceptance criteria

`PASS` below is restricted to the executed tooling/static checks. Full #210 acceptance is not claimed.

| Live issue criterion | Preparation evidence | Full acceptance |
|---|---|---|
| Generated catalog captures runtime/Admin/JS/accessibility strings under `writeleash`; plural/context extraction correct | PARTIAL: raw literal inventory + baseline JSON + documented extraction strategy; make-pot on current source yields 4 header-only msgids; fixture demonstrates `_n`/`_x`/`esc_html__`/JS extraction | NOT_TESTED: no converted production strings and no real catalog for the shipped source |
| Controlled non-English/pseudo-translation exercises selection/Preview/progress/errors/conflicts/History/Undo incl. 0/1/many and long labels | NOT_TESTED: no catalog, no pseudo-locale build, no runtime | BLOCKED: Docker daemon absent (socket missing; no sudo), no browser, and #204/#206 UI unmerged |
| Translations do not alter canonical prices/IDs/plan/journal/CSV keys; old plans hydrate | DESIGN ONLY: never-translate list documents codes/IDs/hashes/canonical money/CSV keys; no production string changed in this lane | NOT_TESTED: requires translated runtime and plan hydration fixtures after conversion |
| Existing keyboard/browser checks cover touched flows; no focus/name loss | NOT_TESTED: no touched flow in this lane; accessible-name conversion guidance only | BLOCKED: Docker/browser unavailable; #204 live-progress accessibility changes unmerged |
| Catalog/asset additions use the public release allowlist and local asset model | DOCUMENTED: `writeleash-distribution-files.txt` + package/inventory/public/source audits + `plugins_url()`/`wp_set_script_translations()` path, no CDN | NOT_TESTED: no catalog/asset added by this lane (deliberately) |

## Executed validation

- `php wordpress/tests/release/i18n-inventory-cases.php` — **PASS** (`i18n literal inventory: PASS (PHP comments, source lines, JS candidates, machine-string review, determinism, missing root)`).
- `php -l wordpress/release/i18n-inventory.php` and `php -l wordpress/tests/release/i18n-inventory-cases.php` — **PASS**, no syntax errors.
- Deterministic regeneration: two consecutive `php wordpress/release/i18n-inventory.php` runs and the committed `baseline-literals.json` are byte-identical, SHA-256 `7276a68c4e71cdfceaae7549311c03d5e2774634d226b0e7157f8247239e99ac` (old = new; tool was not modified because no genuine tooling defect was found, only documented limitations).
- `python3 .github/ci/ownership.py --audit` — **PASS**; new paths classify PR_FAST-only (`wordpress/release/**`, `wordpress/tests/release/*.php`, `docs/**`) and no policy rule was weakened.
- `bash .github/ci/pr-fast.sh` — **PASS**, `#133 PR_FAST PASS`; this lane changes no runtime source/asset/package file, so the unchanged #170/asset and all release audits pass exactly as at baseline. No first failure exists to report; no gate was edited.
- `php /tmp/opencode/wp-cli.phar i18n make-pot wordpress/writeleash … --domain=writeleash` — exit 0, 989-byte POT, 4 header-only msgids (see above).
- `php /tmp/opencode/wp-cli.phar i18n make-pot /tmp/opencode/i18n-fixture …` — exit 0, 13 entries with plurals/context/JS/translator comment (see above).

## Graph, localization and integration notes

- GitNexus (`GITNEXUS_HOME=$PWD/.gitnexus-home`, cached 1.6.12 CLI): `status` initially reported the two prepared tool files as added but unindexed; `analyze --index-only` refreshed to `Status: ✅ up-to-date` at indexed commit `c48e307` (3,228 nodes / 9,677 edges / 237 flows). The two tool files resolve as `File` nodes only — no functions/classes — so there is no symbol impact to assess; a text search for `i18n-inventory` finds exactly one reference, the test invoking the tool by path, and no production include/require or runtime caller.
- `detect_changes` `--scope all` and `--scope staged` receipts (complete, no partials/truncation) are recorded in the evidence directory for the five staged files.
- Localization/content ownership: no production owner is consumed by this lane; `wordpress/release/**` and `wordpress/tests/release/*.php` are PR_FAST-only under the existing map. No shared production file (`class-free-admin.php`, `free-selection.js`) was touched, so there is no collision with A (#206) or C (#204) here; conversion must rebase on the accepted UI set.
- Safety: no plan/job/journal/Undo/price/conflict/Resume code path is modified; inventory is read-only. `baseline-literals.json` is not shipped and is not in the distribution allowlist.

## Unrun validation and blockers

Docker daemon is not running (`unix:///var/run/docker.sock` absent, no passwordless sudo) and no browser harness is available, so native extraction against a runtime locale, pseudo/non-English full-flow checks, 0/1/many and long-label rendering, keyboard/focus/announcement checks and plan-hydration-after-translation are **NOT_TESTED / BLOCKED**, not PASS. No privileged/network/Docker workaround was attempted. The real catalog depends on final gettext conversion of the accepted UI, which this lane deliberately did not perform; #204/#206 (and any selected #207/#208) strings are unmerged and the baseline inventory does not certify them. RELEASE_FULL is neither authorized nor dispatched.

## Founder attention

Review the documented noise/coverage caveats before scheduling conversion: source-tree vs allowlist scope, JS candidate heuristics, and the never-translate boundary. #210 remains **PARTIAL** — preparation complete, implementation (conversion + catalog + pseudo-locale/browser acceptance) not started. No tool monkeypatch, ignored error, gate edit or sandbox bypass was used.
