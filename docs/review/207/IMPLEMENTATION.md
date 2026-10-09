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
| Explicit clear, blank/no-op/zero distinction | PASS domain; runtime pending | `free/sale-operations.php`; `undo/sale-operations.php` |
| Exact discount across different regular bases and first sales | PASS domain; runtime pending | scale/precision/boundary cases, simple/variation fixtures |
| Stale reviewed Regular Price conflicts, no recomputation | PASS domain; runtime pending | snapshot preconditions; locked DB comparison; crash/resume fixture |
| Preserve Regular Price, schedules, metadata and parent state | PARTIAL | unchanged public CRUD path; DB/Redis fixtures await hosted execution |
| Frozen plan and journal/Undo/History/CSV evidence | PASS domain; runtime pending | blank round-trip, old-plan hashes, journal binding; DB fixtures |
| Percentage malformed/bounds/precision and final-target policy | PASS domain | strict grammar, >100, >6 digits, 0%, 100%, rounded-equal target, zero/caps |
| Parent status/type/reparenting conflicts at Apply/Undo | PASS domain; runtime pending | carried #229 correction, both-operation real DB regressions |
| Crash/retry, durable adoption, Resume keeps approval | PARTIAL | real SIGKILL cases wired into existing two-engine/default/Redis Undo suite |
| Existing five operations, #178/#179, old plans/provenance | PASS local domain; runtime pending | unchanged-operation matrix; genuine pre-#210 serialized plans; legacy journal/fingerprint cases |
| Free UI and localization | PASS local model; runtime pending | authenticated Admin fixture, translatable strings, official WP-CLI POT |
| Targeted CI contracts | PARTIAL | PR_FAST local PASS; hosted six-suite DB matrix pending |

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
