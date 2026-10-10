# #207 — Clear and relative Sale Price operations

## Baseline and ownership

- Isolated worktree: `/tmp/opencode/writeleash-207`.
- Branch: `work/207-clear-relative-sale`.
- Exact live main base: `f02db253cfb08dc236f19ad4a62c567afa7be1b0`.
- Live #207/#208 and draft #228/#229 inspected on 2026-10-09.
- #229 parent correction source: `d8359a18c20930dabfc7342815b8ca43f6f3dc2e`.
  Its six-file narrow correctness patch is carried here, without its candidate
  packaging/version changes. Parent publication is checked at Preview/Apply and
  again during locked synchronization; Undo compares with the approved parent ID.
- #208 remains a later integration dependency. No price-ending controls or
  rounding modes are introduced. #228/#229 branches and candidate ZIPs are untouched.
- Draft implementation only. Founder retains final GO/NO-GO.

## Exact semantics

`CLEAR_SALE`, field `sale_price`, input `''`: only an empty input is valid.
The final target is the canonical empty string, never `0` or `null`. Already
blank is UNCHANGED with no product save. A numeric zero sale is nonblank.
Blank has no invented numeric delta. Clearing conservatively evaluates safety
caps against the return from the configured sale to the reviewed Regular Price;
this includes scheduled sales. Clearing a zero sale to a positive regular price
therefore retains the existing undefined-percentage-cap refusal. A changing
clear still counts toward the changing-product limit. This is not sale-date editing.

`SALE_DISCOUNT_PERCENT`, field `sale_price`, explicit decimal input in [0,100],
at most six fractional digits: target = Regular Price × (100 − discount) / 100.
String integer arithmetic has scale 14 and one final half-up rounding to store
precision (0–6). No binary floating-point money calculation. A 0% parameter is
arithmetically valid but its equal-to-regular result is ineligible. A 100%
parameter yields numeric zero, subject to existing zero/cap policy. Tiny discounts
that round back to Regular Price are also ineligible. Missing/invalid Regular
Price is never replaced by zero. A first relative sale evaluates percentage
caps against its reviewed Regular Price basis; historical first-sale SET keeps
its absent-ratio contract. The existing zero-target and count checks apply. Existing sales retain
their sale-field delta/cap behavior against the actual rounded target.

Each variation uses its own Regular Price. Published exact-core simple products
and variations of published exact-core variable parents are the supported boundary.

## Frozen execution contract

1. Admin authenticates and validates the operation. Both operations are exposed
   in the existing Free selector. Clear takes no amount, including the native
   no-JavaScript form; the enhanced form hides/disables its amount and chooses Sale.
2. Preview captures the original Sale Price, Regular Price and sale dates in the
   existing immutable snapshot. The exact parameter and final target are hashed
   with that snapshot in the frozen plan. No schema or database migration is needed.
3. Plan hydration permits a blank sale target only for CLEAR_SALE and never
   recomputes a price. Legacy field-less plans keep their original hash material.
4. Both new operations check their reviewed Regular Price in `precondition()`;
   Apply additionally compares the locked `_regular_price` storage value against
   the reviewed snapshot. Any numeric basis change conflicts, even if the old
   target is still below the new regular price. Insignificant formatting is equal.
5. The existing worker, transaction guards, journal and fence order remain in
   charge. Apply writes only `set_sale_price()`/`save()`, preserving Regular Price,
   dates and unrelated metadata. Existing cache/lookup verification and parent
   synchronization certify the resulting state. Resume consumes the same plan.
6. Compact journal rows store empty targets as strings. JSON field/value evidence
   distinguishes `''` from `0.00`; the full parameter/basis/target authority remains
   in the hash-bound job plan. No new reader requirement is imposed on old journals.
7. History and polled results use the shared money renderer: `Blank (no sale price)`
   versus numeric `$0.00 USD` versus `Unavailable`. CSV streams the original strings
   without parsing/casting, plus task description and `regular_price_at_preview`.
8. Undo uses original-sale and applied-sale evidence plus the existing guarded
   Regular Price context. Blank applied-sale equality now works without admitting
   numeric zero equality. Newer sale or regular edits conflict. Dates remain intact;
   approved parent identity/publication/type remains mandatory. Retry adopts durable
   outcomes without replaying saves.

## Acceptance and evidence

Status below distinguishes domain/model checks from real DB-backed runtime checks.

| Acceptance criterion | Status | Evidence |
|---|---|---|
| Explicit clear, blank/no-op/zero distinction | PASS | `free/sale-operations.php`; real `undo/sale-operations.php` on both engines/default/Redis |
| Exact discount across different regular bases and first sales | PASS | scale/precision/boundary cases, real simple/variation fixtures |
| Stale reviewed Regular Price conflicts, no recomputation | PASS | snapshot preconditions; locked DB comparison; real crash/resume fixture |
| Preserve Regular Price, schedules, metadata and parent state | PASS | real Woo CRUD, independent observers and DB/Redis fixtures |
| Frozen plan and journal/Undo/History/CSV evidence | PASS | blank round-trip, old-plan hashes, journal binding; real DB fixtures |
| Percentage malformed/bounds/precision and final-target policy | PASS domain | strict grammar, >100, >6 digits, 0%, 100%, rounded-equal target, zero/caps |
| Parent status/type/reparenting conflicts at Apply/Undo | PASS | carried #229 correction, both-operation real DB regressions |
| Crash/retry, durable adoption, Resume keeps approval | PASS | real SIGKILL cases on two engines/default/Redis |
| Existing five operations, #178/#179, old plans/provenance | PASS domain/DB; browser rerun pending | unchanged-operation matrix; genuine pre-#210 serialized plans; legacy journal/fingerprint cases |
| Free UI and localization | PASS HTTP/model; browser rerun pending | authenticated HTTP simple/variation sale journey, translatable strings, official WP-CLI POT |
| Targeted CI contracts | PARTIAL | runtime matrix PASS except Admin keyboard fixture; post-correction Admin/CI_COVERAGE required |

### Actual local checks

- `php wordpress/tests/free/unit.php`: PASS, 2,607 assertions (five operations ×
  0/1/2/3/6 decimal places, parser/policy/arithmetic, dirty catalog, 1,000 targets).
- `php wordpress/tests/free/sale-price.php`: PASS, 105 assertions, historical
  sale-field behavior, legacy hashes/provenance and compact journal binding.
- `php wordpress/tests/free/variations.php`: PASS, 77 assertions, parent invariants.
- `php wordpress/tests/free/sale-operations.php`: PASS, new #207 domain battery.
- The first-sale relative cap regression was demonstrated FAIL before its fix
  (20% discount bypassed a 10% cap), then PASS; historical first-sale SET is
  independently asserted unchanged.
- `php wordpress/tests/free/sale-provenance.php`: PASS, actual Undo provenance
  assembler with read-only journal double; blank target and malformed/tampered
  evidence distinguished. This is a model check, not a DB runtime claim.
- `php wordpress/tests/free/cache-public.php`: PASS, 73 model assertions.
- `php wordpress/tests/free/selection-count.php`: PASS, 63 model assertions.
- `php wordpress/tests/admin/recovery-unit.php`: PASS, old-plan/provenance recovery.
- `php wordpress/tests/admin/i18n-unit.php`: PASS, presentation machine invariance.
- `php wordpress/tests/admin/i18n-plan-unit.php`: PASS, genuine pre-#210 plan bytes.
- `node wordpress/tests/admin/progress-client.cjs`: PASS, 69,481 checks.
- `php wordpress/tests/jobs/no-replan-audit.php wordpress/writeleash`: PASS,
  Apply/Undo workers cannot select/replan/recalculate.
- `WP_CLI_PHAR=/home/vanta/.local/bin/wp bash wordpress/release/make-pot.sh`:
  official checksum-pinned WP-CLI 2.12.0; `admin/i18n-catalog.php` PASS, 523 entries.
- `bash .github/ci/pr-fast.sh`: PASS after documented #170 source-identity refresh.
  Original historical PNG/result evidence unchanged; audit assertions retained.
- PHP lint for new DB fixtures and JS syntax: PASS, not an integration claim.

### Runtime limitation / hosted checks

The local Docker daemon is stopped (`/var/run/docker.sock` absent); noninteractive
sudo cannot start it. No local DB-backed PASS is claimed. Changed-file ownership
selects plan, journal, jobs, Undo, Admin and acceptance, plus PR_FAST/CI_COVERAGE.
The #207 real Woo tests are wired into existing MySQL 8.0.44 / MariaDB 10.11.15,
default/Redis fixtures. Hosted run URLs and final statuses will be recorded after
the draft PR runs. Syntax/model tests are not substitutes for those results.

### Hosted execution and discovered blockers

Draft PR: https://github.com/MrDarkRoot/WriteLeash/pull/235.

Runtime tested source: `44b9ca98ca88d3da1471dfb604d0e608b81d8aa5`.
Run: https://github.com/MrDarkRoot/WriteLeash/actions/runs/37963345729.

| Executed check | Actual result at this evidence update |
|---|---|
| PR_FAST | PASS |
| Plan, including #207 domain/provenance and both DB engines | PASS |
| Journal crash/concurrency/default/Redis suite | PASS |
| Durable jobs crash/lease/scheduler suite | PASS |
| #207 real authenticated HTTP simple/variation Preview/Apply/Undo/blank History | PASS on current Woo/MySQL (including PHP 8.0/8.1 and previous WordPress profiles) |
| Previous Woo compatibility profile, both DB engines | PASS |
| Unsupported Woo fail-closed profile | PASS |
| Current acceptance profiles | FAIL after the #207 HTTP leg, at old dirty-catalog summary wording |
| Admin browser suite | FAIL at existing #170 independent-price assertion, before its later PHP integration leg |
| Dedicated Undo crash/parent/sale/CSV suite | Still running; not claimed PASS |
| CI_COVERAGE | Not green while selected jobs fail or remain incomplete |

The first-sale cap regression was proven to fail before the fix and pass after it.
Earlier hosted attempts exposed an Undo provenance numeric-only parser, missing
imports in the new HTTP fixture, and two pre-existing acceptance copy assertions.
These were corrected without weakening assertions. The PHP 7.4 package-audit
failure was a PHP-8-only helper in the test tooling; equivalent `strpos()` checks
retain the identical three catalog assertions and permit that profile to execute.

The current dirty-catalog assertion is likewise aligned with the existing #210
summary (`3 skipped`, not `3 skipped at preview`). Real variation observation also
exposed an undefined Woo tax-class table alias on the independent wpdb reader;
the reader now retains Woo's registered alias, with a DB regression assertion.
This does not add a price write, tax editing or migration. Post-correction hosted
verification remains mandatory.

The #170 browser failure was traced to its keyboard fixture, not assumed unrelated:
it used End with the assertion “last option = decrease percent”. The two new sale
options intentionally change that ordering, so End selected SALE_DISCOUNT_PERCENT,
and the fixture subsequently asserted regular-price edits. The fixture now finds
DECREASE_PERCENT's position, navigates there with Home/ArrowDown, and independently
asserts the selected operation. All independent price/save/conflict assertions
remain intact; no timeout or safety guard was relaxed.

Latest full runtime matrix on production source
`384e4342adc6029bfc10346864c042e08a92a144`:
https://github.com/MrDarkRoot/WriteLeash/actions/runs/37965043755.
PR_FAST, Plan, Journal, Jobs and dedicated Undo PASS. Current default and constrained
Redis acceptance, PHP 8.0/8.1, previous WordPress/Woo and unsupported-Woo refusal PASS.
PHP 7.4 was still running at the status capture. Admin stopped at the keyboard
fixture above; post-correction browser verification is required. The following
commit changes only that test and this evidence, not production pricing code.

Final observed result for that runtime matrix: all eight acceptance profiles PASS,
including PHP 7.4. The dedicated Undo suite logged the #207 sale/crash/parent/CSV
marker on MySQL/default, MySQL/Redis, MariaDB/default and MariaDB/Redis, then its
overall gate PASS. Admin failed at `regression-browser.cjs:253` for the now-traced
End-key operation-selection assumption. CI_COVERAGE correctly failed as a result.
No failed suite is represented as green. The corrected browser fixture must pass
on the new PR HEAD before the draft is considered merge-ready.

## Technical limits and #208 handoff

- Existing value-based ABA limitation remains: an external price edit away and
  back is indistinguishable without an external write event log.
- Third-party hooks and external side effects remain outside field-scoped Undo.
- Unsupported/missing Regular Price is refused, including Clear; no dirty-price
  repair path is introduced.
- Clear uses conservative configured-sale-to-regular safety caps, so a positive
  return from numeric-zero sale cannot bypass existing undefined-ratio policy.
- After #207 acceptance, rebase/integrate #208 onto this branch's accepted HEAD.
  Preserve CLEAR_SALE's empty target and the reviewed Regular Price authority;
  evaluate discount rounding/endings against the frozen final target. Do not
  retroactively add endings to existing plans. Resolve the three-file overlap
  with #229 by retaining equivalent parent checks, not by dropping them.

## Final integration repair — 2026-10-10

### Baseline, diagnosis and correction

- Previous PR HEAD: `3159e4420814cbea3f28a1eb9d4206147dca595c`.
- Current main integration base: `5d8d8cd129e198eaceb08f6363b812ef0f32429f`
  (merged #228 and #229). Integration uses a merge into the existing isolated
  `work/207-clear-relative-sale` branch; no rewritten history or force push.
- The actual failed run `37965043755` checked out production HEAD
  `384e4342adc6029bfc10346864c042e08a92a144`, not `3159e44`'s keyboard correction.
  Admin job `113937415053` failed in #170 Chromium at the independent regular-price
  assertion (`regression-browser.cjs:253` in that checkout). Its preceding End-key
  selection chose `SALE_DISCOUNT_PERCENT`/`sale_price`, while the fixture expected
  `DECREASE_PERCENT`/`regular_price`, both with input `20`. The fixture's SQL observer
  reads `_regular_price`, explaining the mismatch; this is not a calculation or
  AJAX authorization bypass. The frozen dependency still refused the externally
  changed product correctly.
- The failed job's preserved artifact `woo-admin-111` (ID `11634301629`) independently
  confirms the actual selection in `woo-selection-167/170-chromium/170-chromium-preview.png`:
  “Set sale prices to 20% below each product’s reviewed Regular Price”, with Sale price
  before blank and Sale price after $80.00. This screenshot was inspected from the
  original artifact, not recreated or added to historical review proof.
- Keep the semantic lookup plus actual Home/ArrowDown keyboard navigation from
  `3159e44`. Add an independent assertion on the frozen `{type, field, input}` tuple
  and emit that tuple into hosted logs. All original price, save-count, conflict,
  CSV, Undo, focus, accessibility and authorization assertions remain intact.
- Chromium stopped at the first error, so its later CSV/Undo/negative cases,
  Firefox #170 journey, later DB/cache browser profiles and Admin PHP integration
  were not covered by that failed job. A full Admin run is required to expose
  later independent failures; syntax PASS is not browser PASS.
- CI_COVERAGE job `113946539934` failed exactly at
  `Owning integration did not succeed: admin`. `NEEDS_JSON` showed all other selected
  owners succeeded and `acceptance_budget=approved`. The coverage contract is unchanged.

### Semantic merge and release isolation

The only Git conflict was in `wordpress/tests/undo/integration.php`: keep
`require parent-state.php` and `require sale-operations.php`, each once. Review of
the auto-merged hunks confirmed both operation semantics and frozen regular basis
checks survived. The following files are byte-identical to current main and thus
drop out of the PR diff: `wordpress/tests/free/variations.php`,
`wordpress/tests/undo/parent-state.php`, and `class-woo-undo-mutator.php`.
`Change_Plan::precondition()` retains #229's live published/core parent check;
`sync_variable_parent()` retains the locked published/core guard; Undo compares
the current parent ID with the immutable plan snapshot, never with itself.

Main's 0.2.0 header/constant, readme, changelog, release tooling, and #211/#212
documents/receipts are unchanged against the integration base. No #194 receipt,
historical v0.1 artifact, candidate ZIP or #208 branch is changed. The release audit
retains #211's changelog checks, using equivalent `strpos`/`substr` comparisons so
bare PHP 7.4 tooling needs no WordPress polyfill. No check is removed or loosened.
The two pre-existing #170 documentation/proof changes in this PR remain the
explicitly documented current-source identity refresh; historical PNGs and result
JSONs stay unchanged. No new historical proof is fabricated.

The reduced diff inventory has 26 files: 3 review-document/proof files; 4 acceptance
fixtures; 3 Admin fixtures; 3 Free fixtures; 1 release-audit helper; 2 Undo fixtures;
9 runtime PHP/JS files; and the POT catalog. All are sale semantics, verification,
localization or related audit evidence. There is no price-ending implementation.

### Post-integration checks before hosted execution

PASS locally: PR_FAST including main's 0.2.0 coherence checks, Free unit (2,607
assertions), sale domain/provenance, variation controls (77 assertions), genuine
pre-#210 frozen-plan compatibility, i18n unit checks and Apply/Undo no-replan audit.
The browser fixture parses successfully; actual browser and DB results remain
pending until the post-integration workflow completes. Docker remains unavailable
locally, so hosted MySQL/MariaDB default/Redis results are the runtime authority.

At this pre-push evidence snapshot the verdict is **HOLD**. Final run URLs, exact
tested integration HEAD, selected-profile results and mergeability will be appended
after completion. Cancelled historical runs are not counted as PASS. No documentation
push will supersede a long-running verification run.

### First completed post-integration run and hidden fixture failure

Integration HEAD `f36d9283789cb9e4ae136f3e42f07a90d824f18d` was tested by
https://github.com/MrDarkRoot/WriteLeash/actions/runs/38008199985 (completed, not cancelled).
PR_FAST, Plan, Journal, Jobs, Undo and all eight acceptance profiles PASS.
The Undo job `114081933361` emitted both #212 and #207 PASS markers on each of
MySQL/default, MySQL/Redis, MariaDB/default and MariaDB/Redis, confirming that the
merged parent guards and new operations execute together safely.

Admin job `114081933339` proves the original correction: Chromium (154.0.8037.97)
and Firefox (151.0) each PASS the #170 integrated keyboard/accessibility/safety
journey with 255 assertions. Both emit the exact reviewed tuple
`{"field":"regular_price","input":"20","type":"DECREASE_PERCENT"}`.
The later real Admin PHP integration reaches 851 assertions before uncovering a
separate #207 fixture error: `render_view('', '')` omits the required page offset.
`integration.php:103` requires three arguments; `sale-operations.php:9` supplied two.
The call site now supplies `0`, matching all other fixture calls. The shared helper,
merchant implementation and every assertion are unchanged. This error was hidden
behind the former first browser failure and is not a pricing/AJAX failure.
CI_COVERAGE again correctly failed only because Admin failed. Its contract stays
unchanged. The completed run is retained as partial evidence; the arity correction
requires a fresh full hosted run before READY can be claimed.
