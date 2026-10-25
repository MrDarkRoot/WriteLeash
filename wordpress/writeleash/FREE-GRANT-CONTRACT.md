# Free standing grant: v0.3.0 restricted-agent boundary

A seatbelt, not a firewall: a restricted WordPress principal is bounded to
WriteLeash's controlled price/stock job path and fails closed everywhere else.
It is not a sandbox or universal "agent-safe" guarantee.

The restricted-agent layer is opt-in. No WordPress user is restricted until an
operator issues a standing grant. Grant-bound submission is only
`POST /wp-json/writeleash/v1/grants/{uuid}/jobs`: the authenticated **issuer**
submits for the grant **actor** (the restricted principal), and the route
validates the grant and frozen scope, reserves budget, and calls
`Job_Worker::queue_job`. Execution impersonates the actor; the actor cannot
substitute itself as issuer or approve its own work. The route is not an MCP
adapter, not a second price writer, and not a database firewall. The global
kill-switch is fail-closed: only an explicit `0` in
`writeleash_grants_disabled` enables grant-bound writes; an absent, malformed
or non-zero value denies them.

Important assets in v0.3.0 are **price and stock only**. Orders, refunds,
coupons, tax, options and arbitrary meta are outside this boundary.

## Who it is for

1. **AI agent running inside WordPress.** A WP user holding
   `manage_woocommerce` + `edit_products`, granted by a human operator, is
   the restricted actor. Its price/stock jobs are submitted by the
   authenticated issuer only through
   `POST /wp-json/writeleash/v1/grants/{uuid}/jobs`, and execution
   impersonates the actor. Direct WooCommerce REST/save/stock/meta/trash/
   delete are denied outside an Execution Frame.
2. **Background automation, cron or Action Scheduler.** If the job runs as
   the grant actor (for example `wp --user=<actor>` or a scheduled callback
   that sets the actor), the same seatbelt applies. A system cron with
   `user_id = 0` is outside the grant and deliberately unaffected; v0.3.0
   does not claim cron coverage for user-0 jobs.
3. **Human-supervised bulk operations.** Preview → Approve → Apply → Undo
   (price) remain unchanged. Grants are opt-in and do not alter the merchant
   workflow.
4. **Delegated agency or merchant automation.** The operator issues,
   inspects, pauses, revokes or removes the restriction, with an append-only
   audit trail.

## Supported boundary

WriteLeash's own writes for a restricted principal are price and stock through
the controlled job path: stored regular/sale prices for published simple
products and eligible variations, plus `stock_quantity` and `stock_status`
under the frozen stock bounds below. Targets are frozen absolute values;
Apply/Resume never rerun selectors or percentage math.

A restricted principal is denied on direct WooCommerce REST product writes,
`$product->save()`, WooCommerce stock helpers, protected price/stock postmeta,
and trash/delete when no matching Execution Frame is open. `Perimeter_Guard`
denies those paths before persistence. Non-restricted principals keep their
normal WooCommerce behavior; WriteLeash does not intercept them.

The journal remains the source of truth. Undo is price-only; stock Undo is not
supported.

## Can / cannot

| Can | Proving test |
| --- | --- |
| Submit price/stock jobs for the granted actor through the WriteLeash grant route, within frozen scope, budget and expiry. | `wordpress/tests/jobs/matrix-integration.php` (28 cells × 4 engine/cache combinations), `wordpress/tests/jobs/stock-integration.php` |
| Apply price and stock through the controlled item transaction; variation parent price/stock-status sync inside the frame. | `wordpress/tests/jobs/matrix-integration.php`, `wordpress/tests/jobs/stock-integration.php`, `wordpress/tests/jobs/perimeter-integration.php` |
| Operator: issue/inspect/revoke a grant; pause/resume; revoke-all; remove restriction (administrator-only, never self). | `wordpress/tests/admin/grant-admin-integration.php`, `wordpress/tests/jobs/grant-removal-integration.php`, `wordpress/tests/admin/grant-cli.sh` |
| Read job/stock progress and the append-only grant/denial audit. | `wordpress/tests/jobs/grant-integration.php`, `wordpress/tests/jobs/perimeter-integration.php` |

| Cannot | Proving test |
| --- | --- |
| Write products through WooCommerce REST, `$product->save()`, `wc_update_product_stock()`, direct price/stock meta, trash/delete, outside a matching Execution Frame. | `wordpress/tests/jobs/matrix-integration.php`, `wordpress/tests/jobs/perimeter-integration.php` |
| Exceed grant scope, budget, expiry, or the stock bounds (`max_abs_delta`, floor 0); no stock Undo. | `wordpress/tests/jobs/stock-integration.php`, `wordpress/tests/jobs/grant-integration.php`, `wordpress/tests/jobs/matrix-integration.php` |
| Un-leash itself; remove its own restriction; operate pause/resume/revoke-all without site administration. | `wordpress/tests/jobs/grant-removal-integration.php`, `wordpress/tests/admin/grant-admin-integration.php`, `wordpress/tests/admin/grant-cli.sh` |
| Cover administrators, hostile plugins, direct SQL, third-party hook side effects, orders/refunds/coupons/tax/options, arbitrary meta, or user-0 system cron. | `wordpress/tests/jobs/matrix-integration.php` (non-interference cells), `wordpress/tests/jobs/perimeter-integration.php` |
| Claim universal "agent-safe" or firewall behavior. | `wordpress/tests/release/claim-matrix-audit.php`, `wordpress/tests/release/readme-validate.php` |

## Ten decisions (founder-approved)

1. **Actor:** frozen plan `actor_id`, re-checked at execution
   (`Woo_Price_Mutator::authorize` / `Woo_Stock_Mutator::authorize`). Worker
   impersonates the actor via `wp_set_current_user(actor)`; never substitutes
   issuer/approver/admin.
2. **Issuer:** authenticated human creator/delegator, stored as
   `grants.issuer_id` separate from actor/approver. Only
   `manage_woocommerce` + `edit_products` may issue/revoke/update; no broad
   new capability.
3. **Approver:** human per-plan approval keeps `approver_id >= 1`,
   `approval_mode = human`. Grant-submitted work uses `approver_id = 0` with
   `approval_mode = grant`, never rendered as a person
   (`Grant_Repository::operator_label`, Admin grant copy). Grant authority
   alone does not imply human approval. Unapproved `PLANNED` uses mode `none`.
4. **Scope:** `product_price` and `product_stock`, published simple products
   and eligible variations, frozen concrete IDs and absolute targets.
   `product_stock` permits SET / INCREASE_INT / DECREASE_INT on
   `stock_quantity`, bounded by `max_abs_delta` and `min_stock_floor >= 0`,
   and SET on `stock_status` to `instock`, `outofstock` or `onbackorder`.
   `Grant_Repository::assert_scope` rejects BLOCKED plans, non-CHANGING sets,
   unsupported types/states/fields/targets and out-of-bounds stock values.
   Apply/Resume never rerun selectors or percentage math; they consume the
   frozen strings, and there is no re-selection at Apply.
5. **Expiry/revocation/operator control:** explicit `expires_at`, durable
   `status = revoked`, and the global `writeleash_grants_disabled` option.
   Re-checked at acceptance (SHARE + FOR UPDATE) and before each Woo CRUD
   write (`assert_grant_for_write`, held to COMMIT). Revocation wins
   pre-boundary; missing/uncertain evidence fails closed with zero Woo saves.
   No unrestricted fallback. Operators can revoke one grant (the principal
   stays restricted), pause/resume restricted execution (the durable
   kill-switch; an in-flight item finishes and the next item is denied),
   revoke all active grants (principals stay restricted), and remove a
   restriction (site administrator only, never self, audited). Only explicit
   `0` enables grant writes; absent/malformed denies them.
6. **Budget:** grant-wide `budget_max` counts distinct items reaching durable
   `APPLIED` across all jobs. Conditional `UPDATE` reserves atomically before
   accept; concurrent last-unit contention has one winner. Consumed exactly
   once in the same item transaction as journal `APPLIED`
   (`consume_in_tx`); conflicts/definitive failures/review-only/Undo never
   consume. `RELEASED` on definitive non-applied (`CONFLICT`/`FAILED`) in the
   refusal transaction; `RESERVED` retained on ambiguous (`NEEDS_REVIEW`,
   `AMBIGUOUS_COMMIT`, store uncertainty) until `reconcile_item` establishes
   journal truth. Denial causes zero Woo saves.
7. **Idempotency:** key `1-64 [A-Za-z0-9_-]`, scoped to `(grant, key)` unique.
   Canonical payload = `plan_hash + actor + grant + field + sorted targets`
   plus the asset discriminator for stock plans; same key + same hash replays
   the original job (no second plan/reservation); same key + different hash
   rejects (`GRANT_IDEMPOTENCY_MISMATCH`); one winner concurrently via the
   unique key + transaction rollback. The route validates an identical retry
   against the stored frozen plan before any live selector/plan run, so a
   catalog edit after acceptance cannot block replay. A different payload
   rejects without planning. The frozen plan stays the only canonical
   evidence; the durable `accept_plan` short-circuit stays authoritative when
   the pre-check row cannot be read.
8. **Audit:** append-only `writeleash_grant_audit` (no UPDATE/DELETE): actor,
   issuer, approver/mode, grant ID/version, key, frozen targets, before/after,
   reservation/budget events, item outcomes, operator control events
   (`GRANT_CREATED`, `GRANT_ACCEPTED`, `GRANT_REVOKED`, `GRANT_DISABLED`,
   `GRANT_ENABLED`, `GRANT_RESTRICTION_REMOVED`, `ITEM_APPLIED`,
   `ITEM_RELEASED`, and related denial/reconciliation events). Job pins
   `(grant_public_id, grant_version, grant_idempotency_key)` at acceptance and
   never rewrites it; later grant edits bump `grants.version` without touching
   frozen jobs. Reconciliation/Undo preserve the original outcome.
9. **Concurrency/recovery:** lock order `runner option -> job row ->
   grant row/reservation -> journal/item -> Woo rows + CRUD`. Disable option
   is `LOCK IN SHARE MODE` with the runner discipline. Reserve before accept;
   freeze plan + idempotency + reservations + journal seed atomically or roll
   back. Ambiguous Woo commit retains the reservation and enters
   reconciliation; no blind Woo retry. Revocation/disable UPDATEs block
   behind the held grant row lock.
10. **Failure policy:** fail closed on grant-store, budget, identity, version,
    lock or audit uncertainty (`GRANT_STORE_UNAVAILABLE` -> `NEEDS_REVIEW`,
    retain). Definitive denials map to `GRANT_DENIED` -> terminal `FAILED`
    with the grant reason, job paused, zero saves. The global kill-switch and
    per-grant controls are the emergency brakes; neither falls back to an
    unguarded save.

## Schema

- `writeleash_grants` (schema v2): `public_id` (uuid), `version`,
  `issuer_id`, `actor_id`, `budget_max/consumed/reserved`, `expires_at`,
  `status`, `revoke_reason`, timestamps and `restriction_removed_at`. v1
  installs upgrade in place; the marker is added without dropping grant
  evidence. `version` bumps on revoke/update.
- `writeleash_grant_reservations` (v1): `(grant_db_id, job_id, product_id)`
  unique, `state RESERVED/CONSUMED/RELEASED`.
- `writeleash_grant_idempotency` (v1): `(grant_db_id, idempotency_key)`
  unique, `canonical_hash`, `job_id`, `plan_id`.
- `writeleash_grant_audit` (v1): append-only events.
- `writeleash_price_apply_journal` (schema v4): asset discriminator; every
  pre-v4 row is a price row, and stock rows carry `product_stock`.
- `writeleash_jobs` (v2): `approval_mode none/human/grant`,
  `grant_public_id`, `grant_version`, `grant_idempotency_key`. v1 -> v2 adds
  columns + backfills `human` where `approver_id > 0`; never drops evidence.
- `writeleash_grants_disabled` option: `0` enabled, `1` disabled.

`Change_Plan` JSON is untouched (immutable, hash-stable); the job row carries
the grant pin. No other migration is claimed.

## Enforcement points

- Acceptance: `Grant_Repository::accept_plan` (atomic reserve + freeze).
- Per-item grant state: `Grant_Repository::assert_grant_for_write` (after job
  fence, before journal lock), `consume_in_tx` (with journal `APPLIED`),
  `release_in_tx` (with refusal `CONFLICT`/`FAILED`).
- Perimeter: `Perimeter_Guard` denies restricted-principal REST/save/stock/
  meta/trash/delete outside a matching `Execution_Frame`; the frame is bound
  to actor, job/plan, exact frozen product IDs and mutation kind.
- Worker: `GRANT_DENIED` -> terminal `FAILED` + pause; `reconcile` reconciles
  grant reservations from journal truth.
- Display: grant copy never renders `0` as a person.

## Operator recovery

- Issue and inspect from the Grants screen, or:

  ```bash
  wp writeleash grant list --user=<operator>
  wp writeleash grant create --user=<operator> --actor=<actor> \
    --budget=100 --expires="2026-12-31 23:59:59"
  ```

- Revoke one from the Grants screen, or:

  ```bash
  wp writeleash grant revoke <public_id> --user=<operator>
  ```

  The principal stays restricted.
- Pause or resume restricted execution:

  ```bash
  wp writeleash grant disable --user=<administrator>
  wp writeleash grant enable  --user=<administrator>
  ```

- Revoke all from the Grants screen confirmation, or:

  ```bash
  wp writeleash grant revoke --all --user=<administrator>
  ```

  Principals stay restricted.
- Remove restriction: Grants screen only, site administrator
  (`manage_options` + the product pair), never self, with the standing
  warning and an audit row carrying the target user ID. A later grant for the
  same principal re-imposes the restriction.
- All CLI commands require an authenticated `--user`; emergency controls
  additionally require site administration and refuse a restricted operator.
  See [OPERATION.md](OPERATION.md) for the full runbook.

## Non-goals

No orders, refunds, coupons, tax, options, arbitrary meta or direct SQL. No
containment of administrators who are not grant actors, hostile plugins,
third-party hook side effects or a compromised database account. Direct
WooCommerce REST/save/stock/meta/trash/delete paths are denied for restricted
principals outside an Execution Frame, but remain unchanged for non-restricted
principals and other plugins. No selector reruns or percentage recalculation
at Apply/Resume, no budget logic in `woocommerce_before_product_object_save`,
no unguarded alternate write path, no weakened
capability/journal/idempotency/price-write boundaries, no new capability, no
grant-as-human-approval, no firewall/sandbox claims, no MCP adapter, no stock
Undo, no claim that user-0 system cron is covered, and no release/version
artifact changes.
