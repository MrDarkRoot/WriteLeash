# PR #119 repair: shipping boundary matches #112 evidence

Audited predecessor: `39bf91bcf60c566cee68b304984aff7057aa5c6b`.
Recovery-semantics audit: `ce3ae08ba1359469f06450d8c9d80ef7829c6f08`.
Base main: `061d5f5ff4e1867327495c8ca07dd903f1f16b06`.

## Authoritative boundaries

```text
ENGINEERING SELECTOR MAX:             1000 internally (#107 unchanged)
FREE 1.0 NEW-JOB SUPPORTED MAX:         100
FREE WOO RANGE:                       10.0 through 11.x (10.0.0 <= v < 12.0.0)
FREE WORDPRESS RANGE:                 7.0 through 7.x (7.0 <= v < 8.0)
```

`Free_Support_Contract` is the one production authority for the Free ceiling
and the supported Woo/WordPress ranges. Admin dependency/version gating, Apply
and Undo mutators/workers, the version-tolerant conflict checks and the cache
eviction contract all use it. Activation is unchanged and does not depend on
`woocommerce_init`. No older-Woo private metadata SQL fallback was added.

The predecessor measured implementation could execute 1,000, but #112
discovered quadratic journal amplification and long approval latency.
The final Free 1.0 Admin boundary therefore refuses NEW >100 work before journal seeding.
The [pre-cap record](EVIDENCE.md), its precise source/run references and checked
CSV are preserved. No historical measurements are erased or relabeled.

## Enforcement

* Explicit IDs: validated input with >100 entries throws a typed supported-job
  refusal before the engineering selector or durable repository is called.
* Category: the immutable planner may resolve the selection, but the frozen
  selected count is checked before durable import—even for BLOCKED, unchanged
  or unsupported plan items. Engineering overflow also maps to the Free support
  refusal; the Admin does not suggest that 1,000 is supported.
* Safety policy: default/HTML maximum and independent server validation are
  100. A lower safety limit is valid; it cannot raise the support boundary.
* Approval: the authorized, nonce-bound controller hydrates/verifies frozen
  material, then independently refuses >100 **before** calling repository
  approval/`Price_Apply_Journal::seed()`. It does not trust `total_selected` alone.
* Oversized PLANNED/BLOCKED previews expose no approval form and cannot execute
  or seed new journals. History remains readable. They are not grandfathered.
* Grandfathered >100 jobs are **not a new support claim**. Durable post-approval
  state (READY/QUEUED/RUNNING/PAUSED/NEEDS_REVIEW where #109 permits) retains
  ordinary bounded Resume; COMPLETED/COMPLETED_WITH_ISSUES retains #110 eligible,
  conflict-aware Undo and continuation of the existing nonterminal operation.
  The new-work size predicate is not a generic execution-validity predicate.
  No fake migration flag, re-plan, new Apply journal seed, unbounded worker or
  bypass of lease/generation/fence/lifecycle/fresh-permission rules is introduced.
* Legacy warnings derive from the hydrated frozen size and durable lifecycle
  state, not `total_selected`. Already-approved old preview links route to
  existing progress/Undo; new approval remains prohibited.
* Unsupported Woo: the page reports the installed version and the supported
  range and exposes no preview/approval/resume form. Preview POST rejects
  before planning or schema import. Crafted approval/resume/Undo requests fail
  at the version boundary before job lookup, not as a schema failure. The
  plugin remains safely loaded; normal Woo-owned shop editing is not
  intercepted by this gate.
* Routine supported patch/minor drift is not a product-state change: a frozen
  plan whose versions and whose current versions are all inside the supported
  ranges still preconditions MATCH, and Undo fingerprints still verify, when
  every guarded price/type/status/sale/currency/decimals/lookup fact is
  unchanged. Drift outside the ranges still conflicts and refuses with zero
  overwrite.

Typed reasons:

```text
supported_job_limit_exceeded
invalid_product_limit                # user safety policy >100
woocommerce_unavailable              # missing/uninitialized dependency
woocommerce_version_unsupported      # initialized Woo, outside 10.0 through 11.x
multisite_unsupported                # multisite execution refused before mutation
db_transactions_unsupported          # non-mysqli handle or non-InnoDB tables
```

Limit notice (with a known count):

```text
WriteLeash Free 1.0 supports up to 100 products per job in the tested configuration.
101 products were selected. Narrow the selection and build a new preview.
No additional job or journal was created and no product was changed.
```

Woo notice:

```text
WriteLeash supports WooCommerce 10.0 through 11.x for price mutations.
Installed version: 9.9.7. No job was created and no product was changed.
```

Legacy warning (shown on executable/completed oversized historical jobs):

```text
Legacy oversized job
This existing durable job was already approved. The current Free 1.0 limit of 100
products applies to new work: new jobs above 100 cannot be created or approved.
Bounded recovery and conflict-aware eligible Undo remain available only to safely
finish or restore this existing job.
```

The warning does not say 1,000 is supported. Current Woo must still be inside
the supported range. Size grandfathering never grandfathers software/runtime safety.

## Final-head verification artifacts

> CI architecture note (#133): the 13-run/20-job statement below records the
> original #119 verification requirement. Current intentional RELEASE_FULL
> preserves those 20 evidence jobs and adds one exact-SHA preflight (21 jobs).
> Documentation PRs now run only the two cheap checks; see
> [CI policy](../../../.github/ci/CI-POLICY.md). Historical outcomes and hashes
> below are not relabeled as new-head execution.

The existing seven-profile/two-engine workflow remains seven jobs; all existing
regression workflows remain intact. The required final state remains **13
workflow runs / 20 jobs, all completed/success**, at the exact repaired head.

* `*-100.json`: complete actual HTTP selection/category preview/approval/
  Apply/reopen/progress/history/Undo, deterministic pagination, exact save counts,
  independent price/meta/lookup/cache/journal parity.
* `*-101.json`, `*-1000.json`, `*-10000.json`: post-cap typed category/ID
  refusals, before/after owned row counts equal, zero new journal/job/Undo rows,
  zero HTTP Woo saves, unchanged selected prices; no execution/Undo throughput.
* Previous-Woo profile `*-100.json`: full Preview → Apply → History → Undo on
  Woo 11.0.1, proving the workflow on a second supported-range release.
* Out-of-range profile `*-100.json` (Woo 9.9.7, below the 10.0 floor):
  `UNSUPPORTED_EARLY`, valid-session crafted preview POST refused, no job
  reference, no resume path, zero owned rows/saves, unchanged prices and
  explicit range copy rather than schema-unavailable copy.
* `*-support-boundaries.json`: UI max/default 100, policy 101 denied/100 valid,
  101 explicit/category typed results, policy-independent selection limit,
  preserved internal 101-item plan with a stale counter, Admin approval refusal
  with zero journal growth, hidden stale approval form, and ordinary 100-explicit
  preview/approval seeding exactly 100 journal rows.
* Existing `*-torture.json`, `*-lifecycle.json`, diagnostics, request metrics,
  debug/source audits and #107–#111/Guard/Redirection suites are retained.
* `*-legacy-recovery.json` and its test-only save trace exercise an actual
  immutable 101-item trusted predecessor `create_from_plan()`/`approve()`:
  - 101 Apply journal rows exist **before** recovery. The registered Action
    Scheduler worker callback executes one normal default bounded chunk, then
    production transitions pause it. Real authenticated Admin HTTP sees the
    warning and Resume, runs <=10 per POST, and completes all 101 at frozen 80.
  - Scheduler and HTTP Resume both refuse the same competing live #109 lease;
    its release uses the production fenced transition, not a forged expiry or
    generation. Both paths consume the same worker/transaction-fence authority.
  - Frozen material/hash/population and journal immutable bindings remain
    unchanged; save trace proves each product saved once and an outside sentinel
    untouched. No additional Apply rows are seeded during recovery.
  - Completed Apply retains Undo eligibility with all 101 APPLIED rows as
    authority. One later Woo edit to 75 conflicts; the other 100 restore to 100.
    First Undo POST remains nonterminal; fresh-session reopen continues the
    same operation ID in bounded leased chunks. Apply evidence is unchanged.
   - On out-of-range Woo (e.g. 9.9.7) a trusted historical READY plan remains
     visible only as early unsupported-runtime copy, with no actionable form;
     crafted Resume/Undo requests add zero evidence and mutate zero products.

The probe's `wl112_legacy_probe_active` option is **test-only instrumentation**,
never read by production and never grants authority; it is not a migration flag.

Exact PHP 7.4.33/8.0.30/8.1.34/8.2.34, WP 7.0.1/7.1.2, MySQL 8.0.44,
MariaDB 10.11.15 and Redis 7.4.2 / Redis Object Cache 2.7.0 pins are unchanged.
The old #111 accepted-input fixtures now submit the valid 100 safety maximum;
their nonce, authorization, durable progress, conflict, Undo, cache, lifecycle
and pagination assertions have not been weakened. #61 is unchanged; any flake
must retain attempt evidence and rerun the same SHA.

## Journal debt and release boundary

The journal still copies one full frozen `plan_json` into every changing row:
**O(N²) duplication remains documented technical debt**. No normalization,
evidence weakening or journal redesign is part of this repair. The 100 boundary
prevents NEW Admin work from seeding the measured ~596 MB 1,000-item journal.
Already-approved old work retains bounded recovery/eligible restoration so an
upgrade cannot strand partial mutations. A separate redesign can preserve
immutable material/evidence binding.

Emails, webhooks, remote HTTP, orders, external queues and arbitrary plugin
side effects remain **OUTSIDE CONTRACT**. Real-host pilot: **NOT TESTED**.
Multisite: **UNSUPPORTED**. No automatic support for untested versions/ranges.

**#77/#76/#65/#66 modified/executed: NO. Publication: NO. Merge: NO.**
