# #208 — Optional Price Endings

## Baseline and design

- Exact latest-main base: `5a10195e8eed1637aacd32bf72c545fd5a078bc6` (merged #235).
- Isolated branch/worktree: `work/208-price-endings`, `/tmp/opencode/writeleash-208`.
- Final HEAD: the immutable commit containing this document, reported with the
  exact SHA and final-HEAD Actions run in the Draft PR. A document cannot embed
  its own Git commit hash; hosted results below identify tested implementation
  commits separately from the final evidence commit.
- Reuse: #207 `Price_Operation` / `Price_Calculator`, `Change_Plan_Item::compute`,
  existing frozen operation material, Admin control/task descriptions, and
  `free`, `admin`, `undo`, `acceptance` sale-operation fixtures. No schema migration.

Immutable snapshot → existing exact operation intermediate → optional ending →
existing target eligibility/delta/safety → frozen Plan → existing worker/mutator
→ journal → existing guarded Undo/History/CSV. Apply never calls the calculator.
The ending is an optional operation key, omitted for Default (including Clear).

## One rounding rule

Choose the nearest **nonnegative** price matching the selected ending, using
exact intermediate string-integer units before store-precision formatting.
Exact ties choose the higher price. No intermediate rounding followed by ending
rounding. Already-matching prices are unchanged. Whole numbers include zero;
fractional endings start at 0.99, 0.95 or 0.90, so values below the first matching
price use that minimum. No negative candidates, native large integers, floats,
BCMath requirement or backend-specific money calculations.

| Ending | Input | Final target (2 decimals) |
|---|---|---|
| .99 | 10.20 | 9.99 |
| .99 | 10.70 | 10.99 |
| .99 | 10.49 (tie) | 10.99 |
| .95 | 10.45 (tie) | 10.95 |
| .90 | 10.40 (tie) | 10.90 |
| .99 | 0 or 0.01 | 0.99 |
| Whole | 0.49 / 0.50 | 0.00 / 1.00 |
| Whole | 10.49 / 10.50 | 10.00 / 11.00 |

All five historical numeric operations and #207 relative Sale Discount support
endings. Clear Sale explicitly ignores them, disables the enhanced control, and
retains canonical `''`, including native/no-JS submissions. Numeric zero remains
distinct. Default delegates to the original exact decimal target routine with
unchanged arguments and unchanged operation hash material.

.99/.95 require precision ≥2; .90 requires ≥1; whole works at every supported
precision 0–6. Unsupported options are disabled with a visible explanation;
server computation refuses incompatible selections without changing precision.
Formatting uses frozen store precision and retains the existing overflow bound.

## Final-target safety and compatibility

- Old 10.50, decrease 0.01: intermediate 10.49 is safe under a zero-increase cap;
  .99 yields 10.99, blocked by the final increase cap and reversed direction.
- SET 0.49 → whole 0.00: blocked by the existing zero policy.
- Sale 10.50 under regular 10.80 → .99 10.99: existing sale eligibility refuses.
- Regular 10.20 over sale 10.00 → .99 9.99: existing lower-bound refusal.
- Increase 10.10 by 0.01 → .99 9.99: direction reversal blocks approval.
- Original 9.99, SET 10.20 → .99 9.99: UNCHANGED; no save/count/cap warning.
- Relative first-sale caps still use the frozen own Regular Price; existing sales
  retain sale-field deltas. Frozen/current regular drift still conflicts.

Direction protection applies only to an explicit ending with the existing
increase/decrease operations; SET has no promised direction, and relative sale
is constrained by its own frozen regular basis and existing sale eligibility.
Final policy decisions and refusal explanations exist before approval.

Missing ending metadata leaves old plan bytes/hash untouched. Hydration validates
new ending metadata but never calculates/re-rounds a stored target. Old schema,
signatures, journal binding and Undo provenance are reused unchanged. Genuine
pre-#210 plans and historical operation/domain suites are rerun.

The #229 Apply publication/core-parent checks, locked parent sync and Undo frozen
parent-ID checks are untouched. New real Undo fixtures exercise rounded simple
and variation sales, valid parent synchronization, exact fractional restoration,
basis/sale/precision drift, draft/type-changed parents and reparenting at Apply
and Undo. Schedules and unrelated metadata retain the #207 preservation contract.

## Verification

Local PASS: 99 new domain assertions in `free/sale-operations.php`; original
2,607 Free assertions; 105 sale-field, 77 variation, provenance, cache/selection,
recovery, i18n and genuine old-plan checks; 69,481 progress-client checks;
Apply/Undo no-replan audit; official WP-CLI POT generation (535 entries).

The initial PR_FAST run correctly refused stale #170 source fingerprints. The
current-source identity refresh follows #207's convention; historical screenshots
and result JSONs are untouched. No audit assertion was weakened.

Local Docker is unavailable (`/var/run/docker.sock` absent). Real MySQL/MariaDB ×
default/Redis, Chromium/Firefox, and all selected acceptance profiles must pass
on the exact final HEAD in hosted CI. **HOLD until those results complete.**

### Completed first hosted matrix and final correction

Implementation HEAD `0b8618a4dd7268a20c12aa2215bc194e73f3ec9a` completed green:
https://github.com/MrDarkRoot/WriteLeash/actions/runs/38019977485.
Draft PR: https://github.com/MrDarkRoot/WriteLeash/pull/237.

| Gate | Result | Job ID |
|---|---|---|
| PR_FAST | PASS | 114118661941 |
| Plan | PASS | 114118745319 |
| Journal | PASS | 114118745314 |
| Jobs | PASS | 114118745373 |
| Undo | PASS | 114118745359 |
| Admin | PASS | 114118745299 |
| CI_COVERAGE | PASS | 114127172208 |
| current-normal-default | PASS | 114118745463 |
| current-constrained-redis | PASS | 114118745478 |
| previous-woo | PASS | 114118745416 |
| previous-wordpress | PASS | 114118745484 |
| php74 | PASS | 114118745432 |
| php80 | PASS | 114118745502 |
| php81 | PASS | 114118745492 |
| unsupported-woo refusal | PASS | 114118745823 |

Admin logs prove Chromium 154.0.8037.97 and Firefox 151.0 each passed 264 keyboard/
accessibility/safety assertions including #208 final rounded Preview. The real
Admin PHP suite passed 900 assertions on each MySQL/MariaDB default/Redis profile,
including 24 new #208 assertions. Undo logs prove 58 new #208 assertions on all
four profiles: frozen 79.99 sale, journal/History/CSV parity, exact 90.123456
restoration, preserved schedules/metadata and all rounded basis/parent/precision
conflicts. Existing #207 SIGKILL recovery and #229/#212 parent controls also pass.
All selected HTTP acceptance profiles exercised default and .99 relative sales,
simple/variation Apply/Undo and ignored Clear endings; unsupported Woo refused.

Legitimate ownership skips: foundation, research, native, engine, adapter,
feasibility and historical. CI_COVERAGE reports six selected integrations
succeeded and no skipped owner counted PASS. Initial same-SHA preparation run
`38019960463` was cancelled by attaching the required SHA-bound budget label;
it is not counted as a pass. The full run above finished before any subsequent push.

Final review found that forging a precision-disabled ending could reach hydration
of an all-unsupported plan and return a generic material error. The final small
correction checks compatibility in the existing `Woo_Price_Planner::preview()`
immediately after capturing store context, before resolving/persisting products.
It returns `price_ending_precision` with the existing actionable explanation;
Clear's canonical Default remains permitted. One additional real Admin assertion
pins this refusal. This correction does not modify the calculator or mutators.

The commit containing this correction/evidence must finish its own exact-HEAD
matrix before READY. The final SHA, run ID, conclusions and mergeability are
published in PR #237's handoff after completion; no evidence-only push supersedes
that final verification. No official QIT result is claimed.

Graph: refreshed schema-4 runner identity, exact base HEAD, no incomplete reasons.
Calculator impact LOW: compute → create → Preview. Hydration LOW: job repository
and legacy journal consumers. UNKNOWN class/file edges were confirmed by source
searches; not treated as unused. Whole-repository flow discovery is budget-limited;
commit gating requires nonpartial/nontruncated changed-symbol analysis separately.

## Final inventory and limitations

Runtime: `class-price-operation.php`, `class-change-plan.php`, `class-free-admin.php`,
`class-product-snapshot.php`, `free-selection.js`, `languages/writeleash.pot`.
Tests: `free/sale-operations.php`, `undo/sale-operations.php`,
`admin/sale-operations.php`, `admin/regression-browser.cjs`,
`acceptance/sale-operations.php`. Evidence: this file, `170/README.md`, `170/proof.json`.
Total: 14 focused files. Existing harness wiring supplies all owner matrices.

Existing value-based ABA and third-party hook effects remain. Below-minimum endings
can increase low prices and are subject to final safety/direction refusals. No
formula/currency platform, paid dependency, support-limit/selection change, release
receipt/ZIP mutation, official QIT claim or shipping gate. Founder retains GO/NO-GO.
