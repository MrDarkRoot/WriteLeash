# Free conflict-aware Undo, history and retention: #110 engineering contract

This layer turns the #108 Woo CRUD + price journal facts and the #109
durable per-item outcomes into Free conflict-aware Undo and bounded history.
It contains no price arithmetic, selector query, approval UI, full Admin
journey (#111) or scale claim (#112). Undo restores exactly one stored
regular-price value WriteLeash previously changed; it is not a transaction
rollback, and it never promises to reverse emails, webhooks, remote API
calls, orders, sales already made, or arbitrary plugin side effects.

## Authority and reuse

- **#107 Change Plan** remains the only place a target price is computed.
  Undo consumes the frozen plan only through `Job_Repository::hydrate_plan()`
  and `assert_item_material()`-equivalent binding checks; the Undo path
  contains no planner, selector, `Price_Calculator`, operation, catalog
  query or `Price_Store_Context::current()` call (`tests/jobs/
  no-replan-audit.php` enforces this over the Undo files as well).
- **#108 `Woo_Price_Mutator::apply()`** remains the only apply primitive and
  is untouched. `Woo_Undo_Mutator::restore()` is its structural mirror: the
  same short per-item transaction on the same `Price_Apply_Connection`
  transport, the same row-lock discipline, the same centralized
  `Price_Cache_Verifier::invalidate()` cleanup, and the same fresh-observer
  certification. The Woo mutation is `set_regular_price(original)` +
  `save()` under the supported simple/no-sale/base-currency contract. No
  direct `_regular_price` SQL exists on the Undo path (audited).
- **#109 job engine** remains the only apply orchestrator and is untouched
  except for additive lifecycle wiring (deactivation cancels the new owned
  scheduler groups; reactivation reaps stale Undo leases). The Undo worker
  reuses the reviewed `Price_Apply_Transaction_Guard` extension point with a
  new `Undo_Transaction_Fence` implementation.
- The original apply job result is never rewritten. A job that ended
  `COMPLETED_WITH_ISSUES` keeps that status after Undo; Undo history is
  additive (`UNDO_COMPLETED_WITH_ISSUES`, never generic success).

## Undo identity and provenance

Two plugin-owned, per-site, InnoDB tables, version 1, installed lazily
through the normal WordPress connection (`writeleash_undo_schema` records
the version; unknown/partial state fails closed with zero mutation):

`<prefix>writeleash_undo_operations` (one row per apply job):
`id`, `job_id` (unique), `plan_id` (`ascii_bin`), `public_id` (UUID,
future-facing for #111), `initiator_id`, `status`, `status_reason`, durable
counters (`undo_eligible/pending/applying`, `undone`, `undo_conflict`,
`undo_failed`, `undo_needs_review`), `lease_owner/generation/expires_at`,
timestamps (`created_at`, `started_at`, `updated_at`, `completed_at`).

`<prefix>writeleash_undo_items` (one row per eligible product):
`job_id`, `undo_id`, `plan_id`, `product_id`, `sequence` (frozen apply
order), `expected_price` (pre-apply original), `applied_price` (WriteLeash
applied value), `apply_attempt_id` (source journal attempt), `state`,
`reason`, `attempt_id`, `attempt_count`, `claim_token/generation`,
`next_attempt_after`, `provenance` (canonical JSON), `fingerprint`
(sha256), `evidence` (canonical JSON), `undone_at`, timestamps. Unique
`undo_job_product (job_id, product_id)`, key `undo_operation_state`.

Provenance per APPLIED item references: `job_id`, `plan_id`, `product_id`,
pre-apply and applied prices, apply attempt identity, applied timestamp,
post-apply verified Woo/storage facts (active price, lookup min/max with
onsale=0), product type/status, sale configuration, currency, price
decimals, actor/approver provenance. No customer, order, credential, nonce,
cookie or raw-SQL data is stored.

Only journal-proven APPLIED items are Undo-eligible (`#108` journal state
`APPLIED` **and** `#109` item state `APPLIED`). `PENDING`, `APPLYING`,
`UNCHANGED`, `UNSUPPORTED`, `CONFLICT`, `FAILED` and `NEEDS_REVIEW` items
never produce Undo rows. A tampered provenance, price, identity, attempt or
fingerprint fails closed (`UNDO_PROVENANCE_MISMATCH`, zero Woo mutation).

## Undo precondition and fingerprint

Immediately before mutation, under row locks, fresh state must prove:
product exists, core `WC_Product_Simple`, `publish` status, current regular
price equals the WriteLeash applied price, active price and lookup agree,
sale configuration unchanged/empty, currency and price decimals unchanged,
supported Woo/WP versions, and current executor authorization
(`manage_woocommerce` + `edit_products` + per-product `edit_post`).

The post-apply fingerprint is a sha256 over the canonical blocking set:
`product_id`, applied regular price, active price, product type,
`core_simple`, status, sale price, sale dates, currency, price decimals,
lookup min/max, lookup onsale, WordPress and WooCommerce versions. Every
blocking value is a durable apply-time fact (journal COMMIT evidence, the
frozen plan snapshot proven MATCH by the apply precondition, or the frozen
job store context). Advisory `initiated_post_modified_gmt` is recorded for
history display only.

ABA limitation (residual, documented, never overstated): an external edit
that moves the price away and back (`100 -> 80` by WriteLeash, then
`80 -> 70 -> 80` externally through supported CRUD) reproduces every
blocking value, so the fingerprint matches and Undo proceeds. The required
conservative test proves the documented behavior: value-plus-context
equality is the strongest maintainable claim on the supported APIs, because
WriteLeash does not observe hostile or raw writers.

`post_modified_gmt` research (Woo 11.1.2 actual source,
`WC_Product_Data_Store_CPT::update()`): every `save()` unconditionally
bumps `post_modified`/`post_modified_gmt`, either through `wp_update_post`
(when post fields changed) or through a direct `$wpdb->update()` on the
posts row (price-only saves). It is nevertheless NOT a blocking safety
claim: DATETIME granularity is one second (rapid ABA is invisible), raw
writers bypass it, unrelated post touches would cause false conflicts, and
#108 journal evidence carries no apply-time `post_modified` for existing
applies. It is recorded advisory-only.

Any material mismatch yields typed `UNDO_CONFLICT` (with precise reasons
such as `PRODUCT_MISSING`, `PRODUCT_TYPE_CHANGED`,
`PRODUCT_STATUS_CHANGED`, `SALE_CONFIGURED`) and zero overwrite. Current
price already equal to the original without durable UNDONE evidence is
`UNDO_CONFLICT`, never assumed success. A retry of a durable UNDONE item
returns `ALREADY_UNDONE` with no Woo save and no hook replay.

## Transaction, fence and mutual exclusion

Conceptual order per Undo item: `BEGIN`, lock Undo operation row and verify
`(lease_owner, lease_generation)`, lock the parent apply job row (shared
with the #109 fence), lock the Undo item row, verify APPLIED evidence,
fresh precondition plus fingerprint, Woo CRUD restore, durable UNDONE
result, `COMMIT`. Deterministic lock order across the Undo path is
operation row -> job row -> Undo item row -> journal row -> Woo rows; the
apply path order (job row -> journal row -> Woo rows) is a subsequence, so
no lock cycle exists.

A job is Undo-eligible only in `COMPLETED`/`COMPLETED_WITH_ISSUES`: states
in which no apply worker can acquire a lease or claim an item. A `RUNNING`
job refuses initiation (`UNDO_JOB_RUNNING`); apply and Undo can never be
active simultaneously on one job. There is no job-wide Undo transaction and
no in-memory mode flag. `finish_chunk`, `cancel`, `pause_stalled` and
`reap_stalled_leases` bump the Undo generation on the same operation row,
so they block behind an in-flight Undo item exactly as in #109.

Crash matrix (tested with real SIGKILL on both engines): kill before Woo
save, between save and Undo journal, and between journal and COMMIT all
roll back to the applied price with Undo not UNDONE; retry restores exactly
once. Kill after COMMIT recovers UNDONE without a second save. Lost COMMIT
acknowledgement yields `UNDO_NEEDS_REVIEW`, never a blind retry; success is
never inferred from current price alone. Duplicate overlapping Undo workers
produce exactly one restore save, one hook firing, one durable UNDONE; the
other worker observes `ALREADY_UNDONE`/lease-held.

## Partial Undo, cancel, and later jobs

Undo operates only on eligible APPLIED items (e.g. 7 of 10 with 2
`CONFLICT` and 1 `FAILED` elsewhere). Terminal derivation mirrors #109:
any `UNDO_NEEDS_REVIEW` item yields `UNDO_NEEDS_REVIEW`; otherwise any
`UNDO_CONFLICT`/`UNDO_FAILED` yields `UNDO_COMPLETED_WITH_ISSUES`;
otherwise `UNDO_COMPLETED`. An ambiguous Undo COMMIT pauses the whole
operation conservatively; independent items do not continue past review.
Cancel stops future Undo claims and retains UNDONE facts; it never
re-applies. Once UNDONE, retrying the same Undo is `ALREADY_UNDONE`; a
later apply job is a new history chain, and cross-job or same-value ABA
cases (`Job A 100->80`, later `Job B 80->70`, or B back to 80) refuse Undo
A with `UNDO_CONFLICT`.

## History, retention and purge

Backend history models (no #111 UI): a recent-jobs page (`job ID/public
ID`, created/approved/completed timestamps, apply counts, Undo counts,
`undo_eligible`, `undo_expires_at`, typed reasons), a job summary, and an
item page with apply and Undo states plus reason messages. Pagination is
bounded (`limit <= 100`) with deterministic ordering; item reads filter by
apply or Undo state. History reads are scoped by creator/approver/admin
policy; possession of a public ID grants nothing. No sensitive data is
stored.

Retention is 30 days by default, filterable internally through
`writeleash_history_retention_days` (clamped 1..3650). The clock basis is
terminal apply `completed_at`, extended by Undo `completed_at`; never
`created_at`. After expiry, `undo_eligible` is false and no Restore action
is exposed. Storage evidence (row counts and table bytes for 100/1000-item
jobs) justifies the bound; see the lab activity record.

Purge is bounded (`LIMIT` deletes, at most 100 jobs per call), idempotent
(two concurrent purge workers are harmless), and restricted to terminal,
expiry-crossed history: apply `COMPLETED`/`COMPLETED_WITH_ISSUES`/
`CANCELLED` with no pending/applying/review items, and (if present) a
terminal non-review Undo operation with no pending/applying/review items.
`RUNNING`, `QUEUED`, `PAUSED`, `NEEDS_REVIEW`, active Undo, incomplete
apply/Undo and any `*_NEEDS_REVIEW` evidence are never purged. Safe logical
order: Undo items, Undo operations, job items, price journal rows of that
plan instance, jobs. Fresh rows never starve expired ones: candidates are
scanned oldest-first with bounded keyset pagination (`id > last`, never
`OFFSET`, which deletions would shift) until the batch fills or candidates
run out. Row accounting uses exact `COUNT(*)` per table, never stale
InnoDB estimates. Action Scheduler (group `writeleash-maintenance`) is
the wake-up only; purge eligibility comes from durable timestamps.

## Lifecycle, uninstall and side-effect boundary

Deactivation sets `writeleash_runner_state = deactivated` and cancels only
the owned scheduler groups (`writeleash-jobs`, `writeleash-undo`,
`writeleash-maintenance`); workers stop at the next boundary, in-flight
items finish at their transactional fence boundary, and all durable facts
stay readable. Reactivation verifies schema, reconciles stale apply leases
and stale Undo leases, and never mutates products by itself.

Uninstall removes exactly the owned option names (including
`writeleash_undo_schema` and `writeleash_undo_setup`) and cancels nothing
beyond what deactivation already cancelled. Deliberate, reviewed deviation
from the issue's candidate sketch: durable Free tables (jobs, job items,
price journal, Undo operations/items) are NOT dropped by `uninstall.php`.
The reviewed uninstall SQL classifier forbids DDL there, and dropping
tables from the uninstall process cannot be fenced against a surviving
worker; retaining tables keeps the kill condition (evidence deleted while
a worker still mutates) structurally false, preserves Woo product rows,
Action Scheduler tables and Guard/Strict objects untouched, and leaves no
worker changing prices without its journal. A product applied `100 -> 80`
stays `80` after uninstall; uninstall never auto-restores. The safe
shutdown barrier (no new claims after deactivation authority, in-flight
drain at the fence boundary, owned-action cancellation, evidence-first
conservatism) is implemented and race-tested; table removal remains
operator lifecycle, exactly as #109 established for job tables.

Hook/external side effects: the Undo Woo save fires hooks once (synthetic
hook fixture: apply 1, Undo 1, `ALREADY_UNDONE` retry 0). The original
apply's filesystem/remote markers are not erased by Undo; no external
rollback is claimed. Cache behavior reuses #108 centralized invalidation:
after Undo, `get_regular_price('edit')`, `_regular_price`, `_price`,
`wc_product_meta_lookup`, the fresh Woo object and persistent Redis all
agree; the rollback-cache regression (fault after save before COMMIT reads
back the applied price) is tested on default and Redis modes.

## Evidence

`tests/undo/integration.php` runs the required matrix on MySQL 8.0.44 and
MariaDB 10.11.15 with default and Redis 7.4.2 persistent object cache, on
the pinned WordPress 7.1.2 / PHP 8.2 / WooCommerce 11.1.2 fixture: schema
fresh/replay/unknown/partial/wrong-engine; clean Undo `100->80->100` with
regular/active/lookup/journal/hook parity; external-edit, deleted, type-,
status-, sale-, currency- and decimals-drift conflicts; revoked permission
and deleted initiator; ABA and fingerprint-tamper fail-closed; partial Undo
with exact counts; duplicate workers; kill before/after COMMIT and
ambiguous COMMIT; apply/Undo race in both directions; same-connection fence
evidence; deactivation during Undo; uninstall idle and uninstall-vs-worker
races; history pagination/authorization/expiry; bounded purge that skips
active/review/incomplete evidence; retention storage bounds.

Dedicated #110 markers in the artifact report clean Undo, external-edit
conflict, duplicate Undo, crash-before/after COMMIT and the uninstall race
for both engines and both cache modes.

This issue does not build the #111 Admin screens, preview wizard, history
page or progress dashboard; one narrow POST-only initiation endpoint
exists for tests and later #111 integration. No Pro, paywall, sale-price
editing, variations, stock, orders, subscriptions or Guard integration.
