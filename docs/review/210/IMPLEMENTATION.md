# Issue #210 implementation and acceptance

Implementation baseline: fetched `origin/main` on 2026-10-09,
`fd7a0dcd588bdfe0537a64f90f5417471950a8c0` (#224 merge).
Branch: `work/210-merchant-ui-i18n`. One Draft PR; no merge, issue closure, release,
Marketplace certification or #211/#212 dispatch is authorized by this report.

This replaces the obsolete preparation-only verdict. The #222 inventory tooling is
retained. Actual current shipped source, including #204/#205/#206, is converted.

## Implementation

- PHP: complete literal messages, numeric plurals, positional placeholders,
  translator comments, contextual workflow labels, escaped HTML/attributes.
  Converted setup/dependency errors, navigation, discovery/product/category labels,
  selection/counts/safety, Preview/approval, Apply/results, History/CSV human copy,
  Undo, polling and conflict-to-fresh-Preview recovery. Dedicated reason-copy tables
  and REST user-facing errors are localized; machine reason keys remain unchanged.
- JS: both shipped clients use `wp.i18n`, including SelectWoo messages, accessible
  Remove names, operation labels, status/error/retry text and complete announcement
  templates. Both script handles depend on `wp-i18n` and register local translations.
- Catalog: `wordpress/writeleash/languages/writeleash.pot`; allowlisted, with explicit
  Admin CI ownership. Regenerate with
  `WP_CLI_PHAR=/path/wp-cli-2.12.0.phar bash wordpress/release/make-pot.sh`.
  Pinned artifact and documented local language-pack layout are in
  [LOCALIZATION.md](../../i18n/LOCALIZATION.md).
- Tests: catalog integrity, effective translated presentation/counts/escaping,
  canonical plan/Undo compatibility, translated shipped polling client, real
  MO/Jed browser journey in the existing Admin runner. Fixtures are not shipped.

## Coverage and exclusions

All scoped shipped merchant surfaces are converted. Remaining literal bytes are
machine protocols/codes/keys, raw diagnostic exceptions, support evidence state
names, numeric punctuation/currency codes, brand identifiers and store content
(product names, SKUs, category/user names). Stored English is not rewritten.
Complete linked sentences translate before safe anchor substitution. Compact
summaries filter numeric counts rather than matching English `0 ` prefixes.

## Acceptance (candidate validation pending hosted execution)

| Original #210 criterion | Verdict | Evidence |
| --- | --- | --- |
| Catalog captures runtime/Admin/JS/accessibility with plural/context extraction | PASS locally | Official WP-CLI make-pot, catalog test, byte-identical repeat generation |
| Effective controlled locale selection/Preview/progress/errors/conflicts/History/Undo; 0/1/many and long labels | PARTIAL | Unit/client passes; real MO/Jed JS/no-JS browser runner added, hosted result pending |
| Canonical prices/IDs/plan/journal/CSV unchanged; old plans hydrate | PARTIAL | Plan/hash/Undo unit and polling contract checks pass; real stored-source/CSV browser assertions pending |
| Existing keyboard/browser checks; translated updates retain focus/names | PARTIAL | #204 client focus battery passes including effective pseudo strings; #170 and localized browser hosted result pending |
| Catalog/assets honor allowlist and local model | PASS locally | Explicit POT allowlist, `wp-i18n`/script translation registrations, PR_FAST package/ownership checks |

## Tests and evidence

Local commands (PASS): PHP lint, JS syntax, inventory cases, catalog consistency and duplicate
regeneration, `i18n-unit.php`, `i18n-plan-unit.php`, `progress-unit.php`,
`progress-client.cjs`, `recovery-unit.php`, regular/sale/variation/selection-count/cache
model tests, Admin source audit, PR_FAST and workflow lint. First hosted candidate `316a9ab74fbfd631c6cb2d31a04cd5fe9cca7184`:
[run 37905468636](https://github.com/MrDarkRoot/WriteLeash/actions/runs/37905468636).
PR_FAST, plan, journal, jobs, Undo, both foundation engines, engine, research and
adapter passed. #170 passed 255 Chromium and 253 Firefox assertions. Admin reached
effective translated PHP/JS/long-label checks, then the localized Remove assertion
failed because its product description correctly contained a nested pseudo marker.
That expectation is corrected. Historical Plugin Check rejected a discouraged
`load_plugin_textdomain()` call. Local lookup now uses WordPress's public text-domain
registry with just-in-time loading, including a focused real late-activation test;
the classifier and Plugin Check assertions were not weakened.
The next source candidate `5b0916c` passed every owner except Admin; its translated
JS assertions reached the end of recovery, then Chromium reported a native
cross-document "Page already revealed" abort. The focused locale harness uses the
existing Firefox engine with the identical assertions (including zero page errors),
while the unchanged #170 gate continues to cover both engines. The final exact-head
verdict and artifacts are recorded in the Draft PR checks and description.

Local Docker is blocked: daemon socket missing, `sudo` requires a password and
rootless Docker lacks `newuidmap`. Runtime coverage is assigned to the existing
owning Admin job; it is not called PASS until that job executes successfully.
The #170 historical PNGs/result JSON remain byte-identical. Updated source
fingerprints identify this candidate and require current-head Chromium/Firefox
execution under the existing gate contract.

Safety intent: no calculator, canonical monetary value, selector authority, plan/job
schema, journal, approval, worker, cache or recovery authorization changes. Persisted
plans hydrate without migration. Translated labels keep original submitted values.
CSV machine columns/codes remain literal. Polling keeps read-only server authority,
safe outcome HTML, meaningful controls and focus guards. Final safety acceptance
requires the browser assertions and owning hosted checks.

Delivery: Draft PR and exact HEAD/hosted links recorded in the PR after push.
Merge readiness: HOLD until required hosted checks and effective-locale evidence pass.
Firefox initially exposed a fixture search that used `fill()` without SelectWoo's
keyboard events. The harness now uses real key presses and its own AJAX response,
following #170's pattern. A targeted output-boundary check also verifies escaped
translated submenu titles; WordPress renders menu labels as HTML. Small genuine
pre-#210 simple/variation serialized fixtures now pin old plan bytes/hashes/IDs.
