# #210 — Merchant-facing UI translation readiness (preparation)

Status: **PARTIAL — preparation only.** No production Admin PHP/JS string was converted, no gettext catalog was generated for the shipped source, and no catalog/asset was added to the release allowlist. This document records the baseline inventory, the conversion strategy, the never-translate boundary and the follow-up needed once the #204–#208 UI changes settle. The baseline inventory does **not** certify any unmerged #206/#204 UI string.

Baseline: `c48e307efe7750d0fb36015c39d50a34675f9579` (live `main`, 2026-10-07). Scope: inventory/tooling/docs/tests under `wordpress/release/`, `wordpress/tests/release/` and `docs/i18n/` only.

## 1. Inventory assets and determinism

| File | SHA-256 | Role |
|---|---|---|
| `wordpress/release/i18n-inventory.php` | `4e5956cb6da1a66e6ea7a6a7cb007a6e800cca76cad1556cdcffc81efd1672b6` | PHP-tokenizer-backed literal inventory + conservative JS candidate scan. Not a gettext extractor and not a catalog. |
| `wordpress/tests/release/i18n-inventory-cases.php` | `90ccdb9e2a967081de7adae5ffd48bdc0b898d9d2f749e9d75f86906df9f01ae` | Tooling tests: PHP comment exclusion, source lines, JS candidates, machine-string `review` field, byte determinism, missing-root refusal. |
| `docs/i18n/baseline-literals.json` | `7276a68c4e71cdfceaae7549311c03d5e2774634d226b0e7157f8247239e99ac` | Generated baseline inventory (raw candidates, not translations). |

Regenerate with `php wordpress/release/i18n-inventory.php` (default root `wordpress/writeleash`) or pass an explicit root. Verified 2026-10-08: two consecutive regenerations and the committed baseline are byte-identical (same SHA-256). Ordering is file-path `SORT_STRING` then token/offset order; the tool sorts paths before scanning, so filesystem enumeration order cannot affect output.

Tool limits (declared in its JSON `limitations`):
- PHP: only `T_CONSTANT_ENCAPSED_STRING` (single/double quoted, non-interpolated). Interpolated strings and heredocs are not captured.
- JS: conservative regex over `'…'` / `"…"` that deliberately includes comments. Template literals are not captured; comment quotes can bridge across code and produce multi-line pseudo-candidates and can skip real strings (see §2).
- No translation-completeness or extractor-correctness claim is made by the tool.

## 2. Baseline composition and noise

`docs/i18n/baseline-literals.json`: format 1, text domain `writeleash`, **9,436 candidates** (9,305 PHP / 131 JS) across 60 PHP/JS source files; 2,623 distinct literals; 6,813 duplicate occurrences.

Heuristic classes (overlapping, not a reviewed classification; `review` stays `unclassified` for every row):

| Class | Source tree | Shipped allowlist subset |
|---|---|---|
| Sentence-like (has letters and whitespace) | 1,389 | 736 |
| Machine code (ALLCAPS/digits/underscore) | 1,462 | 1,017 |
| Machine key (snake_case) | 5,064 | 3,489 |
| Machine key (slug/path-like) | 53 | 50 |
| Single-token (other non-whitespace) | 285 | 231 |
| HTML fragment / markup | 393 | 342 |
| Numeric/symbol only | 417 | 236 |
| Empty string | 373 | 261 |
| Contains printf placeholder (`%s`, `%d`, `%1$s`) | 187 | 134 |
| Accessibility keyword hit (`aria`, `label`, `role`, `announce`, `focus`…) | 48 | 36 |

Obvious noise for conversion triage: empty strings, numeric/format tokens, HTML markup and attributes, stored machine keys/status codes/reason codes/option names/meta keys/action names (the large majority), and repeated state labels across views. Task-anticipated URL noise is absent: `0` candidates match `https?://` under `wordpress/writeleash` because URLs are built at runtime (`admin_url()`, `plugins_url()`); plugin-header URLs live in the file comment and are extracted by WP-CLI, not by this inventory. CSS noise is minimal: only 2 inline `style="…"` fragments, and `includes/free/free-selection.css` contains no `content:` literals (verified), so no translatable CSS string exists today.

Coverage scope caveat: the inventory scans the source tree, but the public distribution allowlist (`wordpress/release/writeleash-distribution-files.txt`, 42 entries: 37 PHP + 1 JS + 1 CSS + LICENSE/readme/txt/png) is smaller. **22 non-shipped PHP files contribute 3,074 candidates** (legacy/research classes such as `class-admin-page.php`, `class-update-engine.php`, `class-compatibility-doctor.php`, `class-provisioning-plan.php`). #210 conversion must be restricted to the allowlisted merchant path; the extra candidates are inventory noise for this issue.

Hot spots inside the allowlist: `includes/free/class-free-admin.php` 2,054 candidates (281 sentence-like), `includes/free/free-selection.js` 131 (39 sentence-like), `class-undo-repository.php` 614, `class-woo-undo-mutator.php` 353, `class-job-repository.php` 253, `class-price-cache-verifier.php` 246, `class-product-snapshot.php` 270. Many sentence-like literals in repository/state classes are exception or diagnostic text; the `review` field is intentionally unclassified and merchant visibility must be triaged per call site.

JS caveat: the 131 JS rows are candidates, not a complete literal list. The conservative scan includes comment text and can bridge from a quote in a comment to a later quote, producing multi-line pseudo-strings (for example around `free-selection.js:78`), while real strings can be consumed by a spanning match. During conversion, WP-CLI `i18n make-pot` (which parses JS `wp.i18n` calls) is the extraction authority; the raw inventory alone must not be treated as the JS catalog.

## 3. Conversion strategy

- Text domain: `writeleash` (already declared in the plugin header). All new gettext calls use it. WordPress.org plugin-language loading applies to .org-hosted plugins; for the Woo Marketplace candidate, decide explicitly whether to ship a `languages/` catalog with `load_plugin_textdomain()` and register it in the allowlist (see §7). No domain string may be built dynamically.
- PHP functions: `__()`, `_e()`, `esc_html__()`, `esc_html_e()`, `esc_attr__()`, `esc_attr_e()`; plurals `_n()` / `_nx()`; context `_x()` / `_ex()`; combined `_nx()` where both apply. Always pass the literal string and the domain; no variable as the msgid.
- Complete sentences: never split sentences for interpolation. Today `reason_message()`, `result_summary()` and similar code concatenate fragments (for example `'…' . $count . ' products were selected. '`). Conversion replaces concatenation with `sprintf( __( '…%1$s…', 'writeleash' ), … )` using numbered placeholders when a value repeats, and moves any human-readable value (counts, product names, dates, money) through a placeholder rather than string building. Manual plural branches (`1 === $n ? '' : 's'`) become `_n()` with both full singular/plural sentences.
- Translator comments: add `/* translators: %s: product name */` (and `%1$s…%2$s` descriptions) wherever a placeholder is not self-evident. WP-CLI `i18n make-pot` audits this and emits warnings (demonstrated in §9); warnings must be zero for the final catalog.
- HTML/accessibility: translate the text value, never the attribute name or stored value (`role`, `aria-live="polite"`, ids, classes). Build `aria-label` from translated complete strings with placeholders; the current pattern `'Remove ' . $item['text']` becomes `sprintf( __( 'Remove %s', 'writeleash' ), $item['text'] )`.
- Escape order: escape the translated string with `esc_html__()`/`esc_attr__()`/`esc_html_e()` rather than translating an already-escaped value; never pass user data through the msgid.
- JS: use `wp.i18n` (`__`, `_n`, `_x`, `sprintf`, `isRTL` where needed) and `wp_set_script_translations()`, see §5.
- Money/decimals: format with placeholders (numbers/currency stay canonical, see §6); the stored decimal strings and hashes never pass through gettext or `sprintf` transforms that could alter them.

## 4. Merchant string coverage map (allowlisted sources)

Line ranges are from baseline `c48e307`; strings inside them are raw English today (zero gettext calls in shipped PHP; zero `wp.i18n` in shipped JS — verified).

| Merchant area | Baseline evidence (source) | Conversion notes |
|---|---|---|
| Setup / dependency errors | `class-free-admin.php:88-97` (`dependency_ok`/`dependency_refusal`), `reason_message():756-832` (`woocommerce_version_unsupported`, `woocommerce_unavailable`, `multisite_unsupported`, `db_transactions_unsupported`, capability/POST/nonce/rights refusals), notices `:669-686`, `:1357` | Reason codes stay machine-stable; only the display copy returned by `reason_message()` is translated. Version/limit values become placeholders. |
| Selection | `class-free-admin.php:252-291` (`build_selection`), `:1181-1285` (`selector_field`, `selection_button`, `render_selector_form`): method labels, option labels, help text, category wording, buttons, max-selection data; `free-selection.js:15,31-42,50-71` selected list, empty state, placeholders, searching/limit/no-match/offline messages | PHP + JS share wording (for example "Search products", "Remove"); keep one msgid per concept, context (`_x`) where the same word means different things (for example button vs operation label "Apply"). |
| Preview | `class-free-admin.php:388-428` (`process_preview`), `:1331-1446` (`render_preview_view`), `preview_extra_counts():728-755`, `reason_message` `preview_ready`/`plan_policy_blocked`/`plan_blocked` | Counts and “up to N products” strings are placeholder/plural work. Frozen prices render through `money_display()`; values are never translated. |
| Apply / approve | `class-free-admin.php:455-517` (`process_approve`), `render_job_view():1491-1593`; `reason_message` `approved`, `approved_scheduler_unavailable`, `job_not_approvable` | Status text and action labels; job/plan identifiers remain untranslated. |
| Progress | `job_label():833-844`, `item_label():845-864`, `result_summary():865-878`, `render_job_view():1491-1593`; `job-resume-rest.php` / `undo-rest.php` error strings | `result_summary()` needs `_n()` for each count bucket; stored job/item states remain codes. C's #204 `free-progress.js` is unmerged and not in this baseline. |
| Conflicts | `item_label` (`CONFLICT`, `UNDO_CONFLICT`), `render_item_outcome():1012-1036`, `reason_message` `job_material_mismatch`, `worker_failed`; `class-woo-price-mutator.php` / `class-woo-undo-mutator.php` failure copy | The word "conflict" is already merchant copy in `result_summary()`; reason codes stay codes. |
| History | `render_history_table():1055-1075`, `render_history_view():1637+`, `undo_availability():1037-1054`, `job_label`/`item_label` maps | Table headers, status labels, export link; `role="region" aria-label="Job history"` is translatable text in an accessible name. |
| Export / CSV | `render_export_form():1076-1084` ("Download job CSV"), `export_job():1085-1097`, `csv_cell():1098-1104`, `write_job_csv():1105-1137`, exceptions `CSV unavailable` / `CSV evidence limit exceeded` | CSV header/keys and cell codes stay machine-stable (§6). The download button label is translatable; the two exceptions are internal diagnostics unless surfaced as merchant notices — decide per call site. Filename `writeleash-<public_id>.csv` stays. |
| Undo | `process_undo():578-634`, `undo_availability():1037-1054`, `render_undo_section():1611-1636`, `undo_item_label():1008-1011`, `item_label` `UNDO_*`, `reason_message` `undo_chunk`/`undo_terminal`/`undo_unavailable` | Same `_n()`/placeholder discipline as progress; restoration states stay codes. |
| Accessibility names / announcements | `aria-label` literals `:1056`, `:1404`, `:1571`; built name `:1200` + call sites `:1243` (`'Remove ' . $item['text']`); `role="status" aria-live="polite"` `:1225`; JS `free-selection.js:34` `'Remove ' + this.text`; `button_label`/`aria-describedby` targets | Every accessible name gets a translated, complete string with placeholder; never leave a translated control with an English-only aria-label. Ensure `_n()` counts are used inside labels (for example selection counts). |

## 5. Supported JS localization

Baseline JS ships as a single classic script (`free-selection.js`) enqueued via `plugins_url()` with `jquery` + `selectWoo` dependencies (`class-free-admin.php:73-79`). It uses no `wp.i18n` today.

Requirements for conversion:
- Add `wp-i18n` (and `wp-polyfill` only if already present in the supported WordPress range) to the script dependencies; import/use `window.wp.i18n` (`__`, `_n`, `_x`, `sprintf`, `isRTL`) in `free-selection.js` and in any new polling client.
- Register JS translations with `wp_set_script_translations( 'writeleash-free-selection', 'writeleash', WRITELEASH_PLUGIN_DIR . 'languages' )` (path form) so WordPress loads the `.json` Jed catalog for the registered handle. Use the same text domain `writeleash`.
- Dynamic counts and lists: `_n( '%d product selected.', '%d products selected.', count, 'writeleash' )` (or `sprintf( _n( … ), count )` where the number must be formatted), never string concatenation of a count and a noun.
- Errors: reuse the same msgids/messages as PHP where the same situation is shown (for example discovery-unavailable wording exists both in `reason_message()` and `free-selection.js:60,69`; one extraction pass must produce one consistent msgid).
- Accessible names: translate `aria-label` text values, including the interpolated `'Remove ' + text` pattern; ensure the name remains when the DOM node is updated (existing accessibility behavior must be preserved, not re-scoped).
- Placeholders: numbered (`%1$s`) when a value repeats or order may change; add translators comments in JS as well; the make-pot `--skip-js` default must stay off so JS strings are extracted.
- Build/catalog: a source `.pot` plus per-locale `.po`/`.mo` and JS `.json` files belong under `languages/` only if the release decision includes shipping catalogs (see §7). Do not inline translated strings into the JS bundle.

## 6. Never-translate list

Machine values stay byte-stable and must never be wrapped in gettext or formatted where identity matters:

- Machine reason codes (`supported_job_limit_exceeded`, `invalid_nonce`, `permission_denied`, `undo_unavailable`, …) — persisted in journal/reason fields and CSV; display copy is produced by the `reason_message()` mapping.
- Stored job/item states (`PLANNED`, `RUNNING`, `COMPLETED_WITH_ISSUES`, `CONFLICT`, `UNDO_PENDING`, …) — mapped to merchant labels by `job_label()`/`item_label()`; codes are never translated.
- Operation identifiers: action names (`writeleash_free_*`), nonce actions, `selector` kinds, price-operation constants (`SET`, `PERCENT`, `INCREASE`, `DECREASE`), field keys, option/meta keys, table names, post types, REST routes, hook names, CSS ids/classes/selectors.
- Plan hashes and public identifiers (`public_id`, job ids, fingerprints) — including the CSV filename `writeleash-<public_id>.csv`.
- Canonical monetary values and stored decimals (`expected_price`, target price, deltas): never translated, never reconstructed through translated text; `money_display()`/`percentage_display()` only format values for display. Localized decimal/currency presentation is a separate product decision, not a translation side effect.
- CSV machine keys/columns and machine cell values: the CSV header row and coded values remain stable for consumers; do not translate them. If a human CSV is ever wanted, add separate, versioned columns — not now.
- URL/attribute/stored values: `role`, `aria-live`, `aria-describedby` target ids, `data-*` values, `Content-Type`/filename headers, upload/media types.
- Log diagnostics that are never rendered to merchants stay as-is unless explicitly promoted to merchant copy.

## 7. Catalog and asset additions (not done in this lane)

Any `.pot`/`.po`/`.mo`/JS `.json` catalog or generated asset added later must follow the existing public release path:

- Add each file to `wordpress/release/writeleash-distribution-files.txt` (sorted, unique, no leading slash/`..`/backslash), then pass `package-preflight.php`, `inventory-audit.php`, `public-audit-cases.php`, `source-audit.php` and the PHP/JS lint loops already wired into `bash .github/ci/pr-fast.sh`. The auditors reject unlisted/compiled/dependency files, so a catalog cannot be added silently.
- Use the existing local asset model: `plugins_url()` + `WRITELEASH_PLUGIN_FILE` + `WRITELEASH_VERSION` for PHP-referenced JS/CSS, and `wp_set_script_translations()` for registered script handles. No CDN/external asset, no network request at runtime, no build-time network dependency.
- Keep ownership classification green: allowlisted `wordpress/release/**` and `wordpress/tests/release/*.php` are PR_FAST-only; if a catalog lands under `wordpress/writeleash/languages/` it becomes production PHP-adjacent content and must satisfy the same closure audits (and be reflected in the ownership review if it affects production paths).
- The catalog build step (WP-CLI `i18n make-pot`) is a maintainer/prep command, not a runtime dependency; do not add network-dependent extraction to CI.

## 8. Follow-up after #204–#208 settle

- A (#206) and C (#204) UI changes are **unmerged** at this baseline; their strings (selection-count copy, progress client `free-progress.js`, focused/announcement text) are not in `baseline-literals.json` and are **not certified** by it. B (#209) has no merchant copy.
- Re-run the inventory and `i18n make-pot` after the accepted UI set is merged; reconcile by file/line, then convert in small PRs isolated from changing Admin views (coordinate `class-free-admin.php` with #204/#205/#209).
- Final #210 acceptance still requires: a real extracted catalog for the shipped source; plural/context extraction; a controlled non-English/pseudo-translation covering selection/Preview/progress/errors/conflicts/History/Undo with 0/1/many counts and long labels; proof that canonical values/hashes/IDs/CSV keys are unchanged and old plans hydrate; and existing keyboard/browser accessibility checks on the touched flows. None of those were executable in this lane.

## 9. Evidence commands and observed results (2026-10-08)

Environment: PHP 8.2.32; WP-CLI 2.12.0 (`wp-cli.phar` fetched from `wp-cli/builds` gh-pages, sha512 `be928f6b8ca1e8dfb9d2f4b75a13aa4aee0896f8a9a0a1c45cd5d2c98605e6172e6d014dda2e27f88c98befc16c040cbb2bd1bfa121510ea5cdf5f6a30fe8832` verified with `sha512sum -c`); Docker daemon absent; network available but no push/PR.

| Command | Result |
|---|---|
| `php wordpress/tests/release/i18n-inventory-cases.php` | **PASS** — `i18n literal inventory: PASS (PHP comments, source lines, JS candidates, machine-string review, determinism, missing root)` |
| `php -l wordpress/release/i18n-inventory.php` / `php -l wordpress/tests/release/i18n-inventory-cases.php` | **PASS** — no syntax errors |
| `php wordpress/release/i18n-inventory.php > regen1.json && … > regen2.json && cmp …` | **PASS** — both regenerations and `docs/i18n/baseline-literals.json` share SHA-256 `7276a68c…e99ac` |
| `php wp-cli.phar i18n make-pot wordpress/writeleash … --domain=writeleash` | **exit 0**, 989-byte POT, **4 extracted entries, all plugin header** (`Plugin Name`, `Description`, `Author`, `Author URI`); zero code strings because the shipped source has no gettext calls |
| `php wp-cli.phar i18n make-pot /tmp/opencode/i18n-fixture …` | **exit 0**, 13 extracted entries: headers (2), PHP `__()/_n()/_x()/esc_html__()/esc_attr_e()` (8), JS `wp.i18n` calls (3); 2 `msgid_plural` pairs, 1 `msgctxt` ("Apply" as a button label, referenced from both PHP and JS), 1 `#. translators:` comment round-trip; 5 placeholder warnings demonstrate the translators-comment audit |
| `python3 .github/ci/ownership.py --audit` | **PASS** — new paths classify PR_FAST-only (`wordpress/release/**`, `wordpress/tests/release/*.php`, `docs/**`); no policy weakening |
| `bash .github/ci/pr-fast.sh` | **PASS** — `#133 PR_FAST PASS` (baseline-like source; no D change touches runtime/source/package) |

The make-pot fixture lives only in `/tmp/opencode/i18n-fixture/` as tooling evidence and is intentionally **not** committed: the repository's PR_FAST gate is network-free, and adding a WP-CLI download step to CI would violate that policy. If a permanent extraction test is wanted later, it must be a proper artifact under `wordpress/tests/release/` that does not require network access.

## 10. Known gaps in the baseline inventory

- PHP interpolated double-quoted strings, heredocs/nowdocs, and `printf`-built messages that hide literals in variables are not listed; conversion must still find them (text search + make-pot).
- JS template literals and some real strings are not listed; JS rows include comment-derived false positives (multi-line pseudo-candidates) and are not a complete literal set.
- No gettext-function usage exists yet; make-pot on the current source extracts only plugin headers (4 msgids) and is expected to stay near-empty until conversion.
- The inventory is source-tree-wide, not allowlist-filtered (22 extra non-shipped files / 3,074 candidates).
- Unmerged #206/#204 UI strings are absent and uncertified.
- No pseudo-locale, browser, keyboard or runtime evidence exists in this lane; no catalog/asset was shipped.
