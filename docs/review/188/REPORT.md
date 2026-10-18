# Issue #188 — Customer reality check

## Executive verdict

**READY TO RELEASE**

Current `main` / PR #190: `2e059cacf46977cc3ae1eba15eba628ad6e7570e`. No reproducible merchant-facing release blocker was observed.

This is an AI simulation of four customer personas, not interviews with actual merchants. Prior product notes were present in the session; their conclusions were not adopted as findings. All results below come from fresh interactions with a new disposable WooCommerce store. No WriteLeash implementation code, SQL, CI logs, old reports or test harness internals were inspected to derive complaints. Public readme compatibility information was consulted during setup; task execution used the interface without consulting instructions.

## Environment and candidate

- WordPress **7.1.3**, WooCommerce **11.1.2**, PHP **8.2.32**, MariaDB **10.11.14**.
- **HPOS enabled**, compatibility synchronization unchecked, as observed in WooCommerce Advanced → Features.
- PHP memory limit **128 MB**, execution limit **30 seconds**; local PHP HTTP server with four workers, default theme, USD, no third-party pricing plugins.
- Fresh synthetic catalog: 1,000 agency products, 12 accessories, three sale products, three mixed simple products including one malformed stored price, and a variable product with two variations. The personas used practical groups within this shared fixture.
- [Installed validation ZIP](artifacts/writeleash-main-2e059ca.zip), generated from the tracked plugin directory at the stated SHA using Git archive. ZIP SHA-256: `86816758f52360f59c95a01122fe14570cdc11bb5dcb4702292003f1162057bd`.
- Normal wp-admin ZIP upload and activation; all **82 installed files** matched the archive immediately after installation and again after the tests. This run evaluates the current main product, using that local validation ZIP.

[Artifact identity](evidence/artifact-identity.json) · [Environment](evidence/environment.json) · [Fixture seed](evidence/fixture-seed.php) · [Run ledger](evidence/run-ledger.json)

## Persona results

These keep/trust/recommend decisions are evaluator judgments based on the observed tasks, not measured customer opinions.

| Persona | Completed task? | Trust product? | Would keep/install? | Main friction |
|---|---|---|---|---|
| A — small owner | Yes: 12-item category, Preview, Apply, inspect Woo price, Undo | Yes, within the stated price-editing scope | Keep | “Unsigned amount” is unfamiliar terminology; progress needs reload |
| B — store manager | Yes: SKU/name/category, simple + parent-selected variations, Regular and Sale Price, Resume, History, Undo | Yes | Install and keep | Exact SKU input requires expanding an additional section; manual refresh |
| C — agency | Yes: 1,000-target Apply, close/reopen, Resume, History, large Undo | Yes in the tested environment | Install on a supported client store | Several minutes of background work; periodically reload to check progress |
| D — cautious admin | Yes: dirty catalog, mixed variations, external edit after Preview, Resume and protected Undo | Yes | Keep | Invalid-price reason uses “supported decimal contract” instead of merchant language |

## Core flow verification

| Flow | Result | Evidence |
|---|---|---|
| Install / activation | PASS: normal ZIP upload, plugin activated, correct source bytes | [Install](evidence/01-install.txt), [activation](evidence/03-activated.txt), [identity](evidence/artifact-identity.json) |
| Find Bulk Prices | PASS: Products → Bulk Prices; numbered selection/change/safety sections | [First-use interface](evidence/04-bulk-prices.txt) |
| Search / SKU / category | PASS: SKU prefix suggestions, product-name results, named direct-member category, exact variation SKU | [SKU search](evidence/13-B-SKU-search.txt), [mixed preview](evidence/14-B-regular-variation-preview.txt), [exact SKU preview](evidence/35-B-variation-sale-preview.txt) |
| Preview | PASS: exact products, before/after, delta, warnings/skips, price field and preservation explanation | [A preview](evidence/06-A-preview.txt), [sale preview](evidence/17-B-sale-preview.txt) |
| Apply / progress | PASS: 12/12 changed; truthful remaining counts; no premature completion; shopper-price distinction explained | [In progress](evidence/07-A-apply.txt), [finished](evidence/08-A-complete.txt), [Woo price](evidence/09-A-woo-price.txt) |
| 1,000-target job | PASS: 1,000 planned and 1,000 changed, no apply conflicts/failures; recovered via History | [Preview](evidence/21-C-1000-preview.txt), [reopened](evidence/23-C-reopened-history.txt), [finished](evidence/41-C-apply-status.txt) |
| Variations | PASS: parent selection expanded to two named variations; Regular Price +5 and safe Undo; one exact-SKU Sale Price change also passed | [Regular result](evidence/15-B-regular-applied.txt), [Undo](evidence/16-B-regular-undo.txt), [sale result](evidence/39-B-variation-sale-complete.txt), [sale Undo](evidence/40-B-variation-sale-undo.txt) |
| Sale Price / sale-configured products | PASS: $60→$54, $75→$67.50, $30→$27; all restored. Regular-price changes preserved sale prices | [Sale Resume](evidence/19-B-sale-resume.txt), [sale Undo](evidence/20-B-sale-undo.txt), [Woo preserved sale](evidence/15-B-woo-sale-preserved.txt) |
| Close / reopen / Resume | PASS: saved job found in History; Resume processed remaining targets; large job later completed. Undo also survived close/reopen | [History recovery](evidence/23-C-reopened-history.txt), [Resume](evidence/24-C-resume.txt), [later Resume](evidence/38-C-resume-remaining.txt), [Undo reopened](evidence/46-C-undo-reopened.txt) |
| External edit after Preview | PASS: regular $50→$52 in Woo editor after Preview; planned $51 was not written; clear conflict | [External edit](evidence/27-D-external-price-saved.txt), [outcome](evidence/30-D-conflict-results.txt) |
| Dirty catalog handling | PASS: malformed `not-a-price` skipped; clean simple/variation targets continued; no whole-job crash | [Preview skip](evidence/25-D-dirty-preview.txt), [result](evidence/30-D-conflict-results.txt), [final data](evidence/final-price-verification.json) |
| History | PASS: task, operator, time, Apply outcome and Undo state persisted | [Final history](evidence/55-final-history.txt) |
| Undo | PASS: small jobs restored; mixed job restored two and protected later $44; large job restored 999 and protected later $150, zero remaining | [Mixed conflict](evidence/32-D-undo-conflict.txt), [large Undo](evidence/53-C-undo-status.txt), [Woo $150](evidence/54-C-woo-150-preserved.txt) |
| HPOS | PASS: enabled throughout the tested configuration | [Woo features](evidence/13-HPOS.txt) |

The large Apply was observed complete within about **6 minutes 42 seconds** of approval; large Undo within about **5 minutes 52 seconds** of initiation. These are elapsed observation intervals including UI interactions, background intervals, close/reopen and manual continuation, not benchmark execution times. The merchant could leave the admin page while work continued. [Apply timing](evidence/C-timing-complete.json), [Undo timing](evidence/C-undo-timing-complete.json).

After every first-pass UI flow finished, a fresh WooCommerce API read checked **1,019 clean targets**, including all **1,000 agency products**, against the expected restored or intentionally newer prices: **zero mismatches**. The malformed stored price remained untouched. This corroboration happened after UI testing and used no WriteLeash internals or schema inspection. [Checks](evidence/final-price-verification.json) · [Reader](evidence/verify-final-prices.php).

## Release blockers

**NONE**

## Pre-release polish

**NONE required.** No implementation changes were made.

## Post-release improvements

1. Refresh progress automatically; the current explicit reload/Resume instructions work but require manual checks.
2. Replace “supported decimal contract” with an explanation that the product has an invalid stored price and should be reviewed in WooCommerce.
3. Open the exact-SKU section automatically when that selection method is chosen.

## Evidence limitations

- CSV export was attempted through the UI, but Chrome returned `ERR_BLOCKED_BY_CLIENT`. Export contents are **NOT_TESTED** in this run; the browser failure is not classified as a WriteLeash defect. [Browser error](evidence/42-csv-browser-block.txt).
- Two screenshot attempts timed out in browser instrumentation. Complete rendered DOM snapshots and actual Woo editor observations are retained instead.
- This is one supported local configuration, synthetic data and simulated personas. It does not establish a hosting compatibility matrix or human adoption statistics.
- No source fixes were necessary, so no fix branch, PR, merge or CI rerun was performed. No plugin was published and no new issue was created.

## Twelve customer questions

| Question | A | B | C | D |
|---|---|---|---|---|
| Find feature? | Yes, Products menu | Yes | Yes | Yes |
| Understand intended job? | Yes, change one stored price with review and Undo | Yes, Regular vs Sale explained | Yes, supported scope stated | Yes, checked conditions and newer-value protection explained |
| Complete without documentation? | Yes during task execution | Yes during task execution | Yes during task execution | Yes during task execution |
| Trust Preview? | Exact 12 prices shown | Exact selected simple/variation prices and sale baseline shown | 1,000 targets saved and paginated | Invalid product visibly skipped; frozen preview retained |
| Understand Apply? | Explicit approval and background notice | Explicit approval | Explicit approval and saved progress | Explicit approval with conflicts explained |
| Truthful progress? | Remaining then finished | Waiting/resumed then finished | Counts advanced to 1,000; Undo to 999+1 conflict | Changed/skipped/conflict counts matched outcomes |
| Understand conflicts? | Not encountered in A task | Shared mixed-job evidence is understandable | Newer $150 explicitly preserved | Newer $52 and $44 explicitly preserved |
| Resume as expected? | Apply completed automatically; Continue Undo was harmless | Three sale targets resumed safely | Partial large job resumed without lost results | Remaining mixed targets completed; conflict not overwritten |
| Undo understandable and safe? | 12 restored, confirmed Woo $20 | Simple and variation regular/sale restored | 999 restored; newer $150 kept | Two restored; later $44 kept |
| Enough friction to uninstall? | No observed reason | No observed reason | No observed reason | No observed reason |
| Trust on live store? | Yes within supported scope | Yes within supported scope | Yes in the tested supported configuration | Yes, demonstrated fail-safe behavior |
| Recommend to another merchant? | Yes for these price tasks | Yes for these price tasks | Yes for this supported configuration | Yes for these safety needs |

## Final founder recommendation

Release WriteLeash v0.1 from the tested current main. The observed product lets a merchant select named products, review exact price changes, apply them, recover saved progress and undo eligible values while preserving newer WooCommerce edits. The 1,000-target workflow completed on the constrained local fixture, and both intentional external edits and malformed catalog data were handled safely. The remaining wording and refresh conveniences do not justify delaying this release; this recommendation is based on the actual tested behavior and the disclosed AI/persona and browser limitations.
