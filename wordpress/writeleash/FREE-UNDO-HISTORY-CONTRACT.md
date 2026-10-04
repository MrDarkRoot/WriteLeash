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
`id`, `job_id` (unique), `plan_id` (`utf8mb4_bin`), `public_id` (UUID,
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

Deterministic global lock order: every new item claim and mutation transaction
locks the existing indexed **lifecycle option row first**, then the parent
**apply job row**,
then the **Undo operation row**, then the Undo item row, then the apply job
item / #108 journal row, then Woo product/context rows:

```
lifecycle option row -> apply job row -> Undo operation row
                     -> Undo item / apply job item / journal -> Woo rows
```

Conceptual order per Undo item: `BEGIN`, lock/verify the active lifecycle
option row, lock/verify the parent apply job
row, lock the Undo operation row and verify `(lease_owner,
lease_generation)`, lock the Undo item row, verify APPLIED evidence, fresh
precondition plus fingerprint, Woo CRUD restore, durable UNDONE result,
`COMMIT`. Initiation and purge never hold the lifecycle row: they still
start at the parent job row; lease takeover and cancel/reaper update only
their job/operation row; shutdown touches ONLY lifecycle, releases it and
then cancels AS. Activation writes `active` before lease reconciliation,
in separate autocommit operations. Thus no path takes job -> lifecycle
while holding both, and the #109 apply order remains lifecycle -> job ->
journal -> Woo. Shared lifecycle record locks allow different jobs to
remain parallel; no new lock cycle exists.

Initiation re-evaluates terminal status, plan binding and retention under
the locked job row and creates the operation/items in that same
transaction. The authoritative purge transaction takes the same locks and
re-evaluates eligibility from the locked rows before deleting. Concurrent
initiation vs purge therefore has exactly two safe, serializable outcomes:
(A) initiation wins and commits the operation/items; purge observes the
non-terminal operation and removes nothing; or (B) purge wins and commits
complete deletion; initiation then finds no job row and refuses cleanly
with `UNDO_NOT_ELIGIBLE`. Partial purge, orphan Undo rows and Undo created
against missing journal evidence are structurally impossible. The race is
tested with two real processes and a deterministic barrier at the job-row
lock, in both orderings, on both engines/cache modes; neither mutates a
product.

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
bounded (`limit <= 100`) with deterministic ordering. History item pages are
evaluated over the **complete logical result set** (repaired for #110
blocker 1): `apply_state` and `undo_state` filters run inside one
identifier-qualified `LEFT JOIN` query and the page envelope exposes
`total`/`next_offset` for the filtered set, so offsets through the full
1000-product #107 selection range and matches beyond item 100 are never
truncated by an internal read window. History reads are scoped by
creator/approver/admin policy; possession of a public ID grants nothing. No
sensitive data is stored.

Retention is 30 days by default, filterable internally through
`writeleash_history_retention_days` (clamped 1..3650). The clock basis is
terminal apply `completed_at`, extended by Undo `completed_at`; never
`created_at`. After expiry, `undo_eligible` is false and no Restore action
is exposed. Storage evidence for the justified bound is a measured
**100-item** fixture (exact row counts and table bytes; see the lab output
line `#110 storage:`); 1000-item scale/host certification is deliberately
deferred to #112 and is not claimed here.

Purge is bounded, idempotent (two concurrent purge workers are harmless),
and restricted to terminal, expiry-crossed history: apply `COMPLETED`/
`COMPLETED_WITH_ISSUES`/`CANCELLED` with no pending/applying/review items,
and (if present) a terminal non-review Undo operation with no
pending/applying/review items. `RUNNING`, `QUEUED`, `PAUSED`,
`NEEDS_REVIEW`, active Undo, incomplete apply/Undo and any
`*_NEEDS_REVIEW` evidence are never purged.

One purge candidate = one short bounded transaction (repaired for #110
blocker 2). The transaction locks the parent job row first, then the Undo
operation row, then all Undo item/job item/journal rows, re-evaluates the
complete eligibility decision from those locked rows, deletes in the
reviewed logical order (Undo items, Undo operations, job items, journal
rows of that plan instance, job row last), requires every `DELETE` to
affect exactly the locked row count, and commits only when all deletes
succeed. Any SQL error, invariant mismatch, lost transaction or unexpected
state rolls the whole candidate back; the failure is reported as
`purge_failed`, never as success, and the parent job row is never deleted
after a failed child/evidence delete. A later clean purge removes the
complete history. No global transaction spans multiple jobs. Evidence
counts are bounded by the frozen #107 selection ceiling before any delete,
so no unbounded `DELETE` runs. Fresh rows never starve expired ones:
candidates are scanned oldest-first with bounded keyset pagination
(`id > last`, never `OFFSET`, which deletions would shift) until the batch
fills or candidates run out; row accounting uses exact `COUNT(*)` per
table, never stale InnoDB estimates. Action Scheduler (group
`writeleash-maintenance`) is the wake-up only; purge eligibility comes from
durable timestamps.

## Lifecycle, deactivation, uninstall and table retention

### Fail-closed runner authority

Both `Job_Worker` and `Undo_Worker` read the durable
`writeleash_runner_state` option uncached, directly from the options table,
and authorize a new claim or mutation boundary **only** for an explicit
`active` value. Missing (including after uninstall deleted the option),
empty, `deactivated`, malformed and unknown values all return false. A
worker whose item transaction already acquired its authoritative fence may
finish that one boundary under the reviewed #108/#109/#110 semantics, then
stops before the next claim. Activation durably writes `active`;
deactivation durably writes `deactivated`. This is intentional fail-closed
behavior: a missing lifecycle authority never enables a price mutation.

The PHP read is **not** transaction authority. The same normal WordPress
InnoDB `options` table has an exact `option_name='writeleash_runner_state'`
predicate over the stock UNIQUE `option_name` index (engine/index verified
before each locking read). The claim transaction and the separate Woo
mutation transaction each issue `SELECT option_value ... WHERE
option_name=? LOCK IN SHARE MODE` and require `active`. On both pinned
MySQL/MariaDB engines that is a shared lock on the EXISTING indexed option
record held to COMMIT/ROLLBACK. The item claim UPDATE additionally requires
both the live owner/generation and `EXISTS` on that same active option row.
Uninstall's `delete_option()` DELETE or deactivation's direct indexed UPDATE
requires an exclusive lock on that record: if it commits first, a later
locking read sees missing/nonactive and refuses; if the item transaction's
shared lock wins first, shutdown waits until that item's COMMIT/ROLLBACK.
Missing rows are never claimed to provide gap-lock exclusion: a missing
locking read returns false and is immediately rolled back. A direct uncached
check after uninstall's DELETE refuses to continue cleanup if `active`
survived (or the read fails). The shutdown transition is short and precedes
owned-AS cancellation; it does not span a batch or all of uninstall.

A claim committed while active but fenced out by shutdown before Woo starts
returns typed `DEACTIVATED`: journal remains PENDING, the claim token is
CAS-released to PENDING, and its attempt counter is refunded (shutdown
consumes no retry budget). Worker pauses `DEACTIVATED`, with no Woo save or
APPLIED/UNDONE evidence. A stale generation still returns `FENCE_LOST`;
ambiguous commits still require review. Budget exhaustion immediately
after an item boundary also checks lifecycle before scheduling a new chunk.

### Lifecycle stages

The lifecycle stages are deliberately distinct:

1. **Deactivation** sets `writeleash_runner_state = deactivated` and cancels
   only the owned scheduler groups (`writeleash-jobs`, `writeleash-undo`,
   `writeleash-maintenance`); no new apply or Undo claim starts, in-flight
   items finish at their transactional fence boundary, and all durable
   facts stay readable. Reactivation verifies schema, reconciles stale
   apply leases and stale Undo leases, and never mutates products by itself.
2. **Uninstall** deletes the runner option FIRST (waiting for any held
   lifecycle record locks), verifies the DB row is no longer `active`, then
   removes exactly the other owned option names (including
   `writeleash_undo_schema` and `writeleash_undo_setup`) and cancels only
   the same three WriteLeash-owned Action Scheduler groups through the
   public `ActionScheduler::store()` API (`cancel_actions_by_group`);
   unrelated actions and all Action Scheduler tables survive. The option
   deletion/verification is the short authority transition; scheduler
   cancellation is defense in depth. All of this runs
   through `uninstall.php`, which is DDL-free by the reviewed uninstall SQL
   classifier. Deleting `writeleash_runner_state` is the durable fail-closed
   shutdown signal: a surviving worker stops before its next claim even if
   scheduler cancellation never ran or failed. Uninstall performs no
   product deletion, no `_regular_price` rewrite, no Action Scheduler table
   deletion and no Guard/Strict object removal; it never auto-restores a price
   (`100 -> 80` stays `80`).
3. **Durable table retention.** The plugin-owned Free tables (`jobs`, job
   items, price journal, Undo operations/items) are intentionally **not
   dropped** by uninstall. They remain until the operator lifecycle, and
   their rows are removed only by the bounded retention purge once they are
   terminal, expiry-crossed and not active/review/incomplete.

Uninstall sequencing is safe in both success and failure modes: scheduler
cleanup is defense-in-depth, while the durable fail-closed lifecycle
authority is what prevents new claims. An uninitialized or unavailable
Action Scheduler cannot enable mutation.

**Explicit acknowledgment for maintainer review:** issue #110's candidate
wording asked uninstall to "remove only plugin-owned tables/options". The
table-removal half of that candidate is deliberately superseded here and
must **not** be claimed as satisfied. The reasons are structural, not
cosmetic: (a) the reviewed uninstall SQL classifier forbids DDL in
`uninstall.php`, and a short lifecycle-row shutdown fence does not justify
dropping durable evidence that surviving/recovery workers still need;
(b) `DROP TABLE` while a surviving
worker still holds mutation authority is exactly the kill condition
"uninstall can delete evidence while a surviving worker can still mutate";
(c) dropping the tables cannot be shown to preserve #109's invariant that
journal evidence outlives any writer. Retaining tables makes that kill
condition structurally false and preserves Woo product rows, Action
Scheduler tables and Guard/Strict objects untouched. The maintainer decides
whether to amend the issue text before merge; the Free cleanup never
becomes unsafe `DROP TABLE` behavior merely to match old wording.

### External side-effect boundary

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

Blocker-repair regressions: a 170-item job pages `offset=0/50/100/150` and
`offset=160,limit=100` over the complete logical set with in-query
`apply_state`/`undo_state` filters whose matches sit beyond item 100 and
whose paginated `total`/`next_offset` describe the filtered set; ten
deterministic purge failure-injection points (before/after each of the five
deletion stages) each report `purge_failed` with a fresh-observer oracle
proving job/job-items/journal/Undo-operation/Undo-items all survive, and a
later clean purge removes the complete history; and the initiation-vs-purge
race runs as two real processes with a deterministic job-row-lock barrier
in both orderings (initiation wins => purge removes nothing and the
intact evidence still completes the Undo; purge wins => complete deletion
with zero orphan rows and a clean `UNDO_NOT_ELIGIBLE` refusal), with no
lock timeout and no product mutation in either outcome.

Lifecycle/uninstall regressions: explicit `absent`, `''`, `deactivated` and
unknown runner states stop both the real Apply worker loop and the real Undo
worker loop with zero claims, zero Woo saves, zero price mutation and typed
`DEACTIVATED` pauses; uninstall seeds one pending wake-up per owned AS group
plus an unrelated sentinel and proves only the owned actions are canceled
while the sentinel and all AS tables survive; and both real-process
surviving-worker races use two items so that the in-flight item completes
its already-fenced boundary and the lifecycle gate then blocks the next
claim (Undo: item 1 UNDONE / item 2 pending with zero second save; Apply:
item 1 APPLIED / item 2 PENDING with journal still `PENDING`), verified by
fresh DB observers. The durable fail-closed gate, not scheduler
cancellation, is what stops the worker.

Dedicated #110 markers in the artifact report clean Undo, external-edit
conflict, duplicate Undo, crash-before/after COMMIT, the missing-state
fail-closed proof, owned-AS-only cleanup, and the Undo/Apply multi-item
uninstall races. The lifecycle TOCTOU matrix adds real-process barriers
AFTER the ordinary active read BEFORE claim (uninstall wins), AFTER the
durable claim BEFORE mutation (uninstall wins; CAS release/refund), and
INSIDE the fenced Woo transaction (item wins; indexed DELETE/UPDATE waits).
Both Apply and Undo outcomes are independently checked by fresh DB and
Woo observers on both engines and both cache modes; no timeout is a
correctness mechanism.

This issue does not build the #111 Admin screens, preview wizard, history
page or progress dashboard; one narrow POST-only initiation endpoint
exists for tests and later #111 integration. No Pro, paywall, sale-price
editing, variations, stock, orders, subscriptions or Guard integration.
