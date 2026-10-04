# Free durable job engine: #109 engineering contract

This is the production orchestration layer over the #107 immutable Change Plan and
the #108 Woo CRUD + price journal primitive. It contains no price arithmetic,
selector query, approval UI, Undo or scale claim. Action Scheduler is a wake-up
mechanism only; the plugin-owned job tables are the only job truth.

## Authority and reuse

- **#107 Change Plan** remains the only place a target price is computed. A job
  stores the canonical frozen plan JSON (`plan->json()`), plan ID, plan schema
  version, hash version and fingerprint.
- **#108 `Woo_Price_Mutator::apply()`** remains the only product mutation
  primitive. The worker calls it with a *rehydrated* plan object and a claimed
  product ID; it never calls the planner, selector, `Price_Calculator` or raw
  Woo SQL. `tests/jobs/no-replan-audit.php` enforces this over the worker path.
- `Change_Plan::hydrate()` (new, additive) rebuilds the immutable object from
  stored canonical material and verifies the recomputed material hash, item
  shape, resolved ID list, status/decision agreement and recomputed summary.
  It is a trusted-storage rehydration boundary, **not** an import or
  authorization API: a writer who can rewrite both material and fingerprint is
  outside the threat model, exactly as with #108.

## Persistence

Version 1, plugin-owned, per site, InnoDB, created lazily through the normal
WordPress connection and its existing plugin-level table rights (no secondary
user, SSH, routines or manual SQL). `writeleash_job_schema` records the version;
unknown/partial state fails closed.

`<prefix>writeleash_jobs` (one row per frozen plan instance):

- identity: `id` (internal), `public_id` (UUID, future-facing), `plan_id`
  (`utf8mb4_bin`), `plan_schema_version`, `plan_hash_version`, `plan_hash`,
  `plan_json` (canonical frozen material including selection/operation/policy).
- actors/context: `creator_id` (= frozen plan actor), `approver_id`,
  `currency`, `price_decimals`, `wordpress_version`, `woocommerce_version`.
- truth: `status`, `status_reason`, summary counters
  (`total_selected/eligible/changing/unchanged/unsupported/blocked`) and
  durable item counters (`planned/pending/applying/applied/unchanged/conflict/
  failed/needs_review/unsupported`).
- lease: `lease_owner`, `lease_generation`, `lease_expires_at`,
  `last_worker_at`.
- timestamps: `created_at`, `approved_at`, `queued_at`, `started_at`,
  `updated_at`, `completed_at`, `paused_at`.
- unique `plan_instance (plan_id)`, unique `public_id`.

`<prefix>writeleash_job_items` (one row per concrete frozen plan item):

- `job_id`, `plan_id`, `product_id`, `sequence` (frozen plan order),
  `plan_result` (CHANGING/UNCHANGED/UNSUPPORTED), eligibility state/reason,
  blockers/warnings JSON, `expected_price`, `planned_price`.
- orchestration: `state`, `reason`, `attempt_count`, `claim_token`,
  `claim_generation`, `next_attempt_after`, `applied_at`, `last_attempt_at`.
- unique `job_product (job_id, product_id)`, unique `job_sequence
  (job_id, sequence)`, key `job_state_sequence (job_id, state, sequence)`.

WordPress options hold only `writeleash_job_schema`, `writeleash_job_setup`,
`writeleash_runner_state`; no per-item options. Action Scheduler rows are never
domain truth.

## Identity

`(job_id, product_id)` is execution identity. `plan_id` is the frozen plan
instance; `plan_hash` is only the material fingerprint. Two plan instances may
share one hash and receive separate journals, claims and outcomes; hash is never
job identity.

## State machines

Job states: `DRAFT, PLANNING, PLANNED, BLOCKED, READY, QUEUED, RUNNING, PAUSED,
COMPLETED, COMPLETED_WITH_ISSUES, NEEDS_REVIEW, CANCELLED`. Allowed transitions
live in `Job_State::TRANSITIONS` and are exhaustively tested. `COMPLETED`,
`COMPLETED_WITH_ISSUES` and `CANCELLED` never re-enter execution;
`PAUSED`/`NEEDS_REVIEW` execute only through an explicit manual resume.

Item states: `PENDING, APPLYING, APPLIED, UNCHANGED, CONFLICT, FAILED,
NEEDS_REVIEW, UNSUPPORTED`. Import birth states are the frozen plan outcomes.
`CONFLICT` and `NEEDS_REVIEW` are terminal and never return to `PENDING`;
`APPLIED` is never overwritten. Reconciliation may only move stale
`APPLYING`/previously claimed `PENDING` rows to the state the #108 journal
proves.

Derivation (never a cached counter): with zero pending/applying, any
`NEEDS_REVIEW` item yields job `NEEDS_REVIEW`; otherwise any `CONFLICT`/`FAILED`
yields `COMPLETED_WITH_ISSUES`; otherwise `COMPLETED`. Stored counters are an
absolute `GROUP BY state` refresh from durable rows, so concurrent or stale
workers cannot double count; `Plan`-derived totals are validated at import.

## Lease, fence and item ownership

- `acquire_lease()` is one conditional `UPDATE` that increments
  `lease_generation` only when the job is executable and the previous lease is
  absent/expired. The tuple `(lease_owner, lease_generation)` is the fence.
- **Lease expiry and authoritative takeover are different events.**
  `lease_expires_at` only makes a takeover *eligible*; execution authority
  changes only when the generation-bumping `UPDATE` on the job row succeeds and
  commits. An in-flight item transaction is never cancelled by wall-clock
  expiry.
- `claim_next_item()` is an optimistic CAS on the item row whose `EXISTS`
  subquery requires the live fence, so a worker whose generation was stolen
  cannot claim even if it skipped its own fence check.
- `record_item()` is a CAS on `claim_token`; a stale worker cannot overwrite a
  new claim's outcome.
- **Per-item transactional job-row lock.** Every item mutation passes a
  `Job_Transaction_Fence` guard into the #108 transaction
  (`Woo_Price_Mutator::apply( plan, product_id, guard )`). The guard runs after
  `BEGIN` and before the journal row lock, on the same
  `Price_Apply_Connection`: it executes
  `SELECT lease_owner, lease_generation FROM <prefix>writeleash_jobs WHERE id = ?
  FOR UPDATE` and verifies the worker's `(owner, generation)`. A mismatch
  returns `FENCE_LOST` before any Woo write; the transaction rolls back, the
  journal never becomes APPLIED, and the worker stops without retrying or
  claiming another item. A match holds the row lock until #108
  `COMMIT`/`ROLLBACK`.
- Because `acquire_lease`, `cancel`, `pause_stalled` and `reap_stalled_leases`
  bump the generation by updating that same job row, InnoDB blocks them behind
  the in-flight item transaction. **A newer generation cannot become
  authoritative while an older worker holds a valid per-item transactional
  fence lock.** Takeover can only occur between item transactions.
- Lock order is fixed across every path: (1) begin the #108 transaction,
  (2) lock/verify the job fence row, (3) lock/verify the #108 journal item,
  (4) Woo product/meta/lookup writes, (5) journal APPLIED, (6) COMMIT. No path
  takes the job and journal locks in the opposite order, so no deadlock cycle
  exists; different jobs lock independent job rows and remain parallel.
- The job-row fence lock is held for exactly one #108 item transaction, never
  across a whole worker chunk, so scheduler/manual operations are not
  serialized behind batches.
- The #108 journal row lock and state remain defense-in-depth after the job
  fence: same-product serialization, ALREADY_APPLIED suppression and
  concurrent-worker convergence are unchanged.
- Stale-worker rule: before the item transaction the pre-dispatch fence returns
  `FENCE_LOST` with zero claims; inside the transaction the guard returns
  `FENCE_LOST` with zero Woo writes and no APPLIED transition. A claimed item
  that raced a takeover is returned to PENDING best-effort; reconciliation of
  the new generation owns it.
- Crash/recovery: process death rolls back the item and releases the job-row
  lock. Kill before COMMIT leaves the original price and a PENDING journal;
  kill after a durable COMMIT leaves an APPLIED journal that reconciliation
  adopts without Woo replay.
- Cancel/reaper: operator `cancel()` and activation `reap_stalled_leases()`
  wait for the in-flight item. After that item commits, cancel/reap becomes
  authoritative, already committed items remain APPLIED, and pending items are
  never claimed under the revoked generation.

Reconciliation runs at every lease acquisition for stale claims
(`claim_generation < current`): journal `APPLIED` is adopted as
`APPLIED/DURABLE_RECONCILED` without Woo replay, other durable journal states
propagate, journal `PENDING` returns the item to `PENDING`, a missing or
inconsistent binding yields `NEEDS_REVIEW`. Real SIGKILL after COMMIT and
before job-item recording is tested.

## Execution bounds, retries and wake-ups

- Defaults: `max_items = 10`, `budget_seconds = 15`, filterable through
  `writeleash_job_limits`; tests pass explicit limits. One invocation never
  runs to unbounded completion. `#112` may tune these.
- Stop reasons: `BATCH_LIMIT`, `BUDGET_EXHAUSTED`, `NO_ITEMS`, `FENCE_LOST`,
  `JOB_TERMINAL`, `NOT_EXECUTABLE`, `LEASE_HELD`, or a typed pause.
- Retry policy: only #108's `FAILED` known-rollback and
  `TRANSACTION_UNAVAILABLE` return an item to `PENDING`, with
  `attempt_count < 3` and a 5 second `next_attempt_after` backoff; exhausted
  attempts become `FAILED/RETRY_BUDGET_EXHAUSTED`. `CONFLICT`,
  `PERMISSION_DENIED`, `UNSUPPORTED_PRODUCT_STATE`, `NEEDS_REVIEW` and
  ambiguous commits are never auto-retried.
- `NEEDS_REVIEW` (item or unexpected worker exception) pauses the whole job:
  conservative, no further claims. Manual resume may continue independent
  `PENDING` items; the review item stays terminal and the final derivation is
  still `NEEDS_REVIEW`.
- Normal `CONFLICT` does not pause the job; independent items continue and the
  job ends `COMPLETED_WITH_ISSUES`.
- At chunk end with work remaining, the worker **releases the lease first**
  (`QUEUED`) and then enqueues a follow-up with
  `as_enqueue_async_action('writeleash_process_job', array($job_id),
  'writeleash-jobs')`. Enqueue failure persists `PAUSED/SCHEDULER_UNAVAILABLE`;
  a wake-up can never be lost while the lease is still held.
- Action Scheduler 4.0.0 (bundled by the pinned Woo) is verified through
  `ActionScheduler::is_initialized()`, never inferred from Woo being active.
  Duplicate, delayed and scheduler-retried callbacks are harmless: the callback
  re-validates job existence, schema, state, dependency and the lease. It never
  throws for terminal domain states.

## Pause reasons (machine codes)

`SCHEMA_UNAVAILABLE, DEPENDENCY_UNAVAILABLE, SCHEDULER_UNAVAILABLE,
PERMISSION_REVOKED, DEACTIVATED, LEASE_RECOVERY, RECONCILE_UNAVAILABLE,
WORKER_EXCEPTION, JOB_MATERIAL_MISMATCH, ITEM_NEEDS_REVIEW, BLOCKED_BY_POLICY,
MANUAL_PAUSE`. Display copy is separate
(`Job_Reason::messages()`); consumers escape output.

## Permissions, resume and lifecycle

- Import and approval (`create_from_plan`, `approve`) are server-owned internal
  APIs in this issue; no public creation endpoint exists. Approval verifies the
  full binding, refuses blocked plans, seeds the #108 journal and moves
  `PLANNED -> READY` in one transaction using the #108 connection transport.
- Before every item mutation the worker sets the WP current user to the frozen
  plan actor so #108 re-reads capabilities fresh; it never uses root, a
  different admin, or cached capability state. Permission revocation or actor
  deletion yields `FAILED/PERMISSION_DENIED`, job `PAUSED/PERMISSION_REVOKED`,
  zero unauthorized saves, and no silent substitute.
- Manual resume is `POST /writeleash/v1/jobs/{public_id}/resume` only. It
  requires login + `manage_woocommerce` + `edit_products`, then creator,
  approver or `manage_options`. Core nonce handling applies; missing/wrong
  nonces, subscribers, foreign shop managers, GET, malformed IDs, terminal jobs
  and broken schema all mutate zero products. It performs one bounded chunk and
  enqueues a follow-up when items remain.
- Deactivation sets `writeleash_runner_state = deactivated` and cancels only the
  `writeleash-jobs` action group. Workers stop at the next boundary and persist
  `PAUSED/DEACTIVATED`; applied facts and history are retained. Reactivation
  re-verifies readiness, reaps stale leases to `PAUSED/LEASE_RECOVERY` and never
  mutates products by itself. Cancel stops claims and retains all facts; it is
  not Undo (#110). Uninstall removes only the three exact options; job tables
  are left to the operator lifecycle (the #58 uninstall SQL classifier forbids
  DDL in `uninstall.php`).
- Multisite and routed/custom `wpdb` drop-ins are refused. The supported
  execution dependency is exactly WooCommerce 11.1.2 with initialized
  Action Scheduler 4.0.0.

## Observable truth

`Job_Repository::observe()` returns raw status plus derived counts and a
stale-heartbeat view: a dead worker's `RUNNING` lease with an old `updated_at`
reports `stalled = true`, `effective_status = PAUSED`, reason `LEASE_RECOVERY`.
Item reads are paginated (`limit <= 100`), filterable by state and ordered by
frozen sequence. Work-in-progress is therefore never displayed as a generic
success.

## Evidence

`tests/jobs/integration.php` runs the required matrix on MySQL 8.0.44 and
MariaDB 10.11.15 with default and Redis 7.4.2 persistent object cache, on the
pinned WordPress 7.1.2 / PHP 8.2 / WooCommerce 11.1.2 fixture:

schema fresh/replay/unknown/partial/wrong-engine/no-DDL host; idempotent plan
import; 17-item mixed E2E (12 applied, 1 conflict, 2 unchanged, 2 unsupported);
browser-close request exit; 20-item bounded batching; duplicate and concurrent
callbacks; stale lease takeover with `processed = 0`; transactional fence races
(takeover blocked while an item transaction holds the job row lock; new
generation authoritative before mutation with inner `FENCE_LOST` and zero
saves; stale claim exercised past the outer pre-dispatch fence; cancel and
reaper waiting for the in-flight item); same-connection evidence
(`fence_connection_id == connection_id ==` Woo hook connection); SIGKILL while
holding the transactional fence with server rollback, lock release and safe
recovery; real SIGKILL after COMMIT and journal reconciliation without Woo
replay; controlled exception between items; scheduler failure; Woo dependency
loss; reactivation lease reaping; REST security negatives; permission
revocation and actor deletion; multi-job expected-old conflicts including
identical targets, overlap of different jobs on one product without deadlock or
lock-wait-timeout, and parallel isolation; counter tamper/repair; pagination;
exhaustive job and item transition tables; direct DB tampering of
target/hash/ID/policy/item product; cancel; deactivation; and progress
timestamps. Plugin Check 2.1.0 and the source/package audits gate the
distribution.

Dedicated #109 markers in the artifact report the transactional fence takeover,
stale worker, crash and cancel scenarios, plus the same-connection IDs, for
both MySQL and MariaDB and both cache modes.

This issue does not implement Undo/history retention (#110), the Admin journey
(#111), scale certification (#112), a custom scheduler, or job-wide atomicity.
