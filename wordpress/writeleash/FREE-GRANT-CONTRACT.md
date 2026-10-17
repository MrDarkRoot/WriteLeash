# Free standing grant: #298 engineering contract (v0.3.1 only)

This is the durable delegated-authority layer over the #107 immutable Change
Plan, the #108 Woo CRUD + price journal primitive, the #109 job engine and
#110 Undo. It adds no agent REST route, no MCP adapter, no inventory or other
asset family, no v0.3.0 change, no version bump and no public "agent-safe"
claim. Research #270/#271 remain research-only. Closed #290-#293 stay closed.

## Supported boundary

WriteLeash's own price writes for principals the grant constrains: stored
regular/sale prices for published simple products and eligible variations,
using frozen concrete product IDs and absolute target values. Not a database
firewall. Not a sandbox for other plugins, WooCommerce admin/REST, direct SQL,
arbitrary PHP, orders, refunds, coupons, tax, options, inventory or arbitrary
fields. Price writes stay on Woo CRUD (`Woo_Price_Mutator::set_field` +
`save()`); the journal stays the source of truth; Undo stays conditional
restoration.

## Ten decisions (founder-approved)

1. **Actor:** frozen plan `actor_id`, re-checked at execution
   (`Woo_Price_Mutator::authorize`). Worker impersonates the actor via
   `wp_set_current_user(actor)`; never substitutes issuer/approver/admin.
2. **Issuer:** authenticated human creator/delegator, stored as
   `grants.issuer_id` separate from actor/approver. Only
   `manage_woocommerce` + `edit_products` may issue/revoke/update/disable;
   no broad new capability.
3. **Approver:** human per-plan approval keeps `approver_id >= 1`,
   `approval_mode = human`. Grant-submitted work uses `approver_id = 0`
   with `approval_mode = grant`, never rendered as a person
   (`Grant_Repository::operator_label`, Admin grant copy). Grant authority
   alone does not imply human approval. Unapproved `PLANNED` uses mode
   `none`.
4. **Scope:** regular/sale prices, published simple + eligible variations,
   frozen IDs + absolute targets. `Grant_Repository::assert_scope` rejects
   BLOCKED plans, non-CHANGING sets, unsupported types/states/fields/targets.
   Apply/Resume never rerun selectors or percentage math; they consume frozen
   strings.
5. **Expiry/revocation/disable:** explicit `expires_at`, durable
   `status = revoked`, global `writeleash_grants_disabled` option.
   Re-checked at acceptance (SHARE + FOR UPDATE) and before each Woo CRUD
   write (`assert_grant_for_write`, held to COMMIT). Revocation wins
   pre-boundary; missing/uncertain evidence fails closed with zero Woo saves.
   No unrestricted fallback. Operator recovery: `Grant_Repository::enable`
   (same capability gate) sets the option to `0`; `disable` sets `1`.
   Only explicit `0` enables grants; absent/malformed denies grant writes.
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
   Canonical payload = `plan_hash + actor + grant + field + sorted targets`;
   same key + same hash replays the original job (no second plan/reservation);
   same key + different hash rejects (`GRANT_IDEMPOTENCY_MISMATCH`); one
   winner concurrently via the unique key + transaction rollback.
8. **Audit:** append-only `grant_audit` (no UPDATE/DELETE): actor, issuer,
   approver/mode, grant ID/version, key, frozen targets, before/after,
   reservation/budget events, item outcomes. Job pins
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
    with the grant reason, job paused, zero saves. Emergency control is the
    global disable above.

## Schema

- `writeleash_grants` (v1): `public_id` (uuid), `version`, `issuer_id`,
  `actor_id`, `budget_max/consumed/reserved`, `expires_at`, `status`,
  `revoke_reason`, timestamps. `version` bumps on revoke/update.
- `writeleash_grant_reservations` (v1): `(grant_db_id, job_id, product_id)`
  unique, `state RESERVED/CONSUMED/RELEASED`.
- `writeleash_grant_idempotency` (v1): `(grant_db_id, idempotency_key)`
  unique, `canonical_hash`, `job_id`, `plan_id`.
- `writeleash_grant_audit` (v1): append-only events.
- `writeleash_jobs` (v2): `approval_mode none/human/grant`,
  `grant_public_id`, `grant_version`, `grant_idempotency_key`. v1 -> v2 adds
  columns + backfills `human` where `approver_id > 0`; never drops evidence.
- `writeleash_grants_disabled` option: `0` enabled, `1` disabled.

`Change_Plan` JSON is untouched (immutable, hash-stable); the job row carries
the grant pin.

## Enforcement points

- Acceptance: `Grant_Repository::accept_plan` (atomic reserve + freeze).
- Per-item: `Grant_Repository::assert_grant_for_write` (after job fence,
  before journal lock), `consume_in_tx` (with journal `APPLIED`),
  `release_in_tx` (with refusal `CONFLICT`/`FAILED`).
- Worker: `GRANT_DENIED` -> terminal `FAILED` + pause; `reconcile` reconciles
  grant reservations from journal truth.
- Display: grant copy never renders `0` as a person.

## Operator recovery

- Revoke one grant: `Grant_Repository::revoke(public_id, revoker_id)`.
- Disable all restricted writes: `Grant_Repository::disable(operator_id)`.
- Re-enable: `Grant_Repository::enable(operator_id)`.
- Extend expiry / change budget: `update_grant` (bumps version, preserves
  consumed/reserved, refuses budget below committed).
- All require `manage_woocommerce` + `edit_products`; revoke/update additionally
  require issuer or `manage_options`.

## Non-goals

No inventory/orders/refunds/coupons/tax/options/arbitrary fields, no selector
reruns or percentage recalculation at Apply/Resume, no budget logic in
`woocommerce_before_product_object_save`, no unguarded alternate write path,
no weakened capability/journal/idempotency/price-write boundaries, no new
capability, no grant-as-human-approval, no firewall/sandbox claims, no
release/version artifact changes.
