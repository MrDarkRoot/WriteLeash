# PR #119 repair: shipping boundary matches #112 evidence

Audited predecessor: `39bf91bcf60c566cee68b304984aff7057aa5c6b`.
Base main: `061d5f5ff4e1867327495c8ca07dd903f1f16b06`.

## Authoritative boundaries

```text
ENGINEERING SELECTOR MAX:             1000 internally (#107 unchanged)
FREE 1.0 SUPPORTED/RUNTIME ADMIN MAX:   100
FREE WOO VERSION:                     11.1.2 exactly
```

`Free_Support_Contract` is the one production authority for the Free ceiling
and exact Woo version. Admin dependency/version gating, Apply and Undo
mutators/workers, and the version-sensitive cache eviction contract all use
it. Activation is unchanged and does not depend on `woocommerce_init`.
No older-Woo private metadata SQL fallback was added.

The predecessor measured implementation could execute 1,000, but #112
discovered quadratic journal amplification and long approval latency.
The final Free 1.0 Admin boundary therefore refuses >100 before journal seeding.
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
* Old oversized previews expose no approval form. Admin resume/Undo controllers
  also check the frozen population before invoking bounded workers/initiation;
  oversized history remains readable, without actionable mutation controls.
* Unsupported Woo: the page reports the exact installed/supported versions and
  exposes no preview/approval/resume form. Preview POST rejects before planning
  or schema import. Crafted approval/resume/Undo requests fail at the version
  boundary before job lookup, not as a schema failure. The plugin remains safely
  loaded; normal Woo-owned shop editing is not intercepted by this gate.

Typed reasons:

```text
supported_job_limit_exceeded
invalid_product_limit                # user safety policy >100
woocommerce_unavailable              # missing/uninitialized dependency
woocommerce_version_unsupported      # initialized Woo, wrong exact version
```

Limit notice (with a known count):

```text
WriteLeash Free 1.0 supports up to 100 products per job in the tested configuration.
101 products were selected. Narrow the selection and build a new preview.
No additional job or journal was created and no product was changed.
```

Woo notice:

```text
WriteLeash Free 1.0 currently supports WooCommerce 11.1.2 for price mutations.
Installed version: 11.0.1. No job was created and no product was changed.
```

## Final-head verification artifacts

The existing seven-profile/two-engine workflow remains seven jobs; all existing
regression workflows remain intact. The required final state remains **13
workflow runs / 20 jobs, all completed/success**, at the exact repaired head.

* `*-100.json`: complete actual HTTP selection/category preview/approval/
  Apply/reopen/progress/history/Undo, deterministic pagination, exact save counts,
  independent price/meta/lookup/cache/journal parity.
* `*-101.json`, `*-1000.json`, `*-10000.json`: post-cap typed category/ID
  refusals, before/after owned row counts equal, zero new journal/job/Undo rows,
  zero HTTP Woo saves, unchanged selected prices; no execution/Undo throughput.
* Previous-Woo profile `*-100.json`: `UNSUPPORTED_EARLY`, valid-session crafted
  preview POST refused, no job reference, no resume path, zero owned rows/saves,
  unchanged prices and explicit version copy rather than schema-unavailable copy.
* `*-support-boundaries.json`: UI max/default 100, policy 101 denied/100 valid,
  101 explicit/category typed results, policy-independent selection limit,
  preserved internal 101-item plan with a stale counter, Admin approval refusal
  with zero journal growth, hidden stale approval form, and ordinary 100-explicit
  preview/approval seeding exactly 100 journal rows.
* Existing `*-torture.json`, `*-lifecycle.json`, diagnostics, request metrics,
  debug/source audits and #107–#111/Guard/Redirection suites are retained.

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
prevents the default Admin path from seeding the measured ~596 MB 1,000-item
journal. A separate redesign can preserve immutable material/evidence binding.

Emails, webhooks, remote HTTP, orders, external queues and arbitrary plugin
side effects remain **OUTSIDE CONTRACT**. Real-host pilot: **NOT TESTED**.
Multisite: **UNSUPPORTED**. No automatic support for untested versions/ranges.

**#77/#76/#65/#66 modified/executed: NO. Publication: NO. Merge: NO.**
