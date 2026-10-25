# Restricted agent seatbelt - v0.3.0 operator runbook

A seatbelt, not a firewall: a restricted WordPress principal is bounded to
WriteLeash's controlled price/stock job path and fails closed everywhere else.
It is not a sandbox or universal "agent-safe" guarantee.

Important assets in v0.3.0 are **price and stock only**. The merchant
Preview → Approve → Apply → Undo (price) workflow is unchanged; grants are an
opt-in layer for delegated work.

## Before you start

- **No grant, no restriction.** A WordPress user becomes a restricted
  principal only when they are the actor on a standing grant. Fresh installs
  create no grants.
- **The kill-switch is fail-closed.** `writeleash_grants_disabled = 0` enables
  grant-bound writes. `1` pauses them. An absent or malformed value denies.
  Pause is an explicit operator action; it never falls back to an unguarded
  write.
- **Capabilities.** Issuing, listing and revoking a grant require
  `manage_woocommerce` + `edit_products`. Pause/resume, revoke-all and remove
  restriction additionally require site administration (`manage_options`) on
  top of that pair. A restricted principal can never operate the emergency
  controls or remove its own restriction.
- **Issuer submits for the actor.** The job route authenticates the grant
  issuer, and the request names the grant actor. The actor is the restricted
  principal; the actor cannot submit as itself or approve its own work.
- **Execution Frame.** A restricted principal's only permitted catalogue
  writes are inside WriteLeash's controlled item transaction, in a frame bound
  to the actor, job/plan, exact frozen product IDs and mutation kind.

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

## Runbook

### 1. Enable grant-bound execution

Grant-bound execution is opt-in: issue no grant and no principal is
restricted. If the kill-switch is absent or not exactly `0`, grant-bound
writes are denied. Set it to `0` only when you intend to allow them; set it to
`1` to pause them. The plugin never silently changes the switch.

### 2. Issue a grant

- Actor: an existing user ID, different from the issuer.
- Budget: `1..10000` distinct items reaching durable `APPLIED`.
- Expiry: a future UTC datetime in `YYYY-MM-DD HH:MM:SS` format.
- The actor must hold `manage_woocommerce` + `edit_products`; the issuer must
  too.

Use **Products → Grants** or:

```bash
wp writeleash grant create --user=<issuer> --actor=<actor> \
  --budget=100 --expires="2026-12-31 23:59:59"
```

### 3. Inspect and list

The Grants table shows the grant public ID, actor, issuer, status, budget
(`consumed / reserved / max`), expiry, version and restriction state. CLI:

```bash
wp writeleash grant list --user=<operator>
wp writeleash grant list --user=<operator> --format=json
```

`consumed` is durable applied work; `reserved` is accepted work not yet
resolved. Both count against `max`.

### 4. Revoke one grant

Revoking stops future work. The principal **stays restricted**; revocation is
not un-leashing.

```bash
wp writeleash grant revoke <public_id> --user=<issuer-or-administrator>
```

### 5. Pause or resume restricted execution

Pause is the durable global kill-switch. An item already inside its item
transaction may finish; the next item is denied, and new acceptance is
refused. Resume only with an explicit `0`.

```bash
wp writeleash grant disable --user=<administrator>
wp writeleash grant enable  --user=<administrator>
```

### 6. Revoke all active grants

Emergency control: every active grant is revoked in one transaction.
Principals **stay restricted**. The admin screen requires a confirmation
checkbox; the CLI requires site administration.

```bash
wp writeleash grant revoke --all --user=<administrator>
```

### 7. Remove a restriction

This is stronger than revoking: the user returns to ordinary WooCommerce
capability rules. It is available only on the Grants screen, only to a site
administrator (`manage_options` + the product pair), and never to the
restricted principal itself. The screen shows a standing warning; the action
is audited with the target user ID. Existing grant evidence is retained. A
later grant for the same principal re-imposes the restriction.

### 8. CLI summary

| Action | Command |
| --- | --- |
| List | `wp writeleash grant list --user=<operator>` |
| Create | `wp writeleash grant create --user=<operator> --actor=<actor> --budget=<1..10000> --expires="YYYY-MM-DD HH:MM:SS"` |
| Revoke one | `wp writeleash grant revoke <public_id> --user=<operator>` |
| Revoke all | `wp writeleash grant revoke --all --user=<administrator>` |
| Pause | `wp writeleash grant disable --user=<administrator>` |
| Resume | `wp writeleash grant enable --user=<administrator>` |

Remove restriction is intentionally not a CLI command. Every CLI command
requires the authenticated `--user`; without it the command refuses.

## What a refusal means

A refusal is fail-closed and happens before persistence:

- **Zero durable writes.** No product row, postmeta or lookup row changes.
  There is no unguarded fallback and no "try the normal WooCommerce path"
  branch.
- **REST:** HTTP `403` with code `writeleash_perimeter_denied`.
- **PHP product/stock/trash/delete paths:** `WC_Data_Exception` with code
  `writeleash_perimeter_denied` when WooCommerce is loaded; protected meta
  filters return `false`.
- **Denial audit:** one JSON line is appended to the WordPress debug log
  (when `WP_DEBUG_LOG` is enabled) with `code`, `where`, `actor`,
  `object_id`, `kind`, `detail` and, for REST, `route`.
- **Grant-job denials** use typed reasons such as `GRANT_DISABLED`,
  `GRANT_REVOKED`, `GRANT_EXPIRED`, `GRANT_BUDGET_EXHAUSTED`,
  `GRANT_SCOPE_DENIED`, `GRANT_IDENTITY_INVALID` or
  `GRANT_STORE_UNAVAILABLE`.

Check, in order:

1. The kill-switch: is `writeleash_grants_disabled` exactly `0`?
2. The grant: status `active`, expiry in the future, budget not exhausted.
3. The scope: supported asset, published simple product or eligible
   variation, frozen target, stock bounds.
4. The frame: the write must be inside the controlled item transaction for
   the same actor, product and mutation kind.

A pause, revoke or revoke-all never retries the denied write. Re-enable or
issue a new grant deliberately, then submit a fresh job.

## Evidence locations

- **Grant audit:** `{prefix}writeleash_grant_audit`, append-only. Includes
  creation, acceptance, revocation, pause/resume, restriction removal,
  budget/reservation events and item outcomes.
- **Denial log:** `writeleash_perimeter_denied` JSON lines in the WordPress
  debug log (default `wp-content/debug.log` when `WP_DEBUG_LOG` is true).
- **Job journal:** `{prefix}writeleash_price_apply_journal` (schema v4, asset
  discriminator) is the source of truth for item outcomes.
- **Budget evidence:** `{prefix}writeleash_grant_reservations` and
  `{prefix}writeleash_grant_idempotency` show reserved, consumed, released
  and replayed work.

## Historical: Free V0.1 certified operation

### Free V0.1 certified operation (exactly one)

> This is the existing advanced V0.1 technical operation, not the intended
> public Free 1.0 product. #106 governs planned safe WooCommerce bulk price
> changes; that workflow is not implemented, #107–#112 remain pending, and
> public release is deferred.

Free V0.1 ships **one** code-known operation. There is no operation registry,
plugin adapter SDK, dynamic callback registration or Admin-authored policy. The
operation is the one selected by #85 and certified by #87; #78 adds its
descriptor, the tiny mutable config, live readiness, and wires the production
REST path to both.

```text
ID:                 redirection-5.5.2-bulk-disable-global
Descriptor version: 1
Plugin:             Redirection 5.5.2 (exact; no ranges)
Target:             <active WordPress prefix>redirection_items
REST route:         POST /redirection/v1/bulk/redirect/disable
Request shape:      global=true, no items, no filterBy conditions
Mutation:           UPDATE only (status='disabled', unfiltered select-all)
Physical ceiling:   P = 2000 row events (trusted provisioning policy)
Admin logical budget: L, integer 0..2000
Runtime grants:     SELECT, UPDATE on the target table only
Execution:          descriptor -> config -> restricted shared wpdb -> Guard
                    -> Red_Item::set_status_all( 'disable', array() )
Unsupported/unknown Redirection version: fail closed, no stock fallback
```

### Code

| Class | Responsibility |
| --- | --- |
| `WriteLeash\Certified_Operation` | Immutable, reviewed facts. `find( $id )` returns only the exact known ID; unknown IDs return `null` and never derive classes/tables from input. |
| `WriteLeash\Operation_Config` | The only mutable state: `enabled` (strict boolean) and `logical_budget` (canonical integer) in one option, `writeleash_certified_operation_state`. |
| `WriteLeash\Certified_Operation_Status` | Live readiness: `READY`, `DISABLED`, `UNSUPPORTED`, `MISCONFIGURED`, `NOT_READY` with machine reasons; runs the #83 runtime Doctor plus physical-ceiling and target-grant checks on the restricted connection. |
| `WriteLeash\Redirection_Bulk_Disable_Rest` | Intercepts the exact REST candidate and executes only through the descriptor/config/readiness result. |
| `WriteLeash\Redirection_Bulk_Disable` | Unchanged narrow adapter: version-pinned `Red_Item::set_status_all()` inside a Guard-owned transaction. |

Security-sensitive values (table suffix, adapter class, REST route/shape,
plugin/version, required grants, physical ceiling, logical bounds) are never
stored in `wp_options` and are never Admin-configurable. The legacy #87 budget
option `writeleash_operation_budget_redirection_5_5_2_bulk_disable` is no longer
read.

### Mutable config

Exactly two fields, both validated strictly with no loose coercion:

- `enabled`: PHP boolean only. `1`, `'1'`, `'true'`, `null`, arrays and objects
  are rejected.
- `logical_budget`: PHP integer or canonical decimal string (`0` or
  `[1-9][0-9]*`), `0..2000`. Canonical strings normalize to `int`; leading
  zeros, signs, floats, exponents, whitespace, out-of-range values, `null`,
  arrays and objects are rejected.

Stored state is exactly `array( 'operation_id', 'enabled', 'logical_budget' )`.
Unknown, missing or foreign-operation fields make the state invalid; it is
never silently repaired. `Operation_Config::reset()` clears it and the safe
default (disabled) applies. No credentials, SQL, callbacks, table names or
ceilings are ever stored.

### Enable / disable semantics

`enabled=false` (or absent state) means WriteLeash does not expose the operation
as runnable **and does not silently restore the stock unbounded Disable**. The
dangerous global select-all route stays owned by WriteLeash while the plugin is
installed: the request fails closed with `503 writeleash_operation_disabled`.
This is deliberately different from "unsupported" (wrong Redirection version)
and from "not READY" (policy/runtime drift).

### Logical budget changes need zero DDL

Changing L (`5 -> 25 -> 0 -> 2000`) writes only the operation-state option. It
performs no trigger DDL, no `GRANT`/`REVOKE`, no credential work, and no SQL on
the restricted runtime connection; the trigger body, DEFINER, grants and the
physical ceiling stay byte-identical. The next guarded execution uses the new
L. Changing P is a different, trusted provisioning action: P is installed by
the operator with the reviewed plan and must equal the descriptor's 2000, or
readiness reports `NOT_READY` / `physical_ceiling_mismatch`.

`L` is a semantic pre-COMMIT limit and `P` is the server-side physical ceiling.
`L` does not prevent work up to `P`; when `P >> L`, the database can execute
many row events before logical refusal.

### Readiness

`Certified_Operation_Status::check()` evaluates live state every call; nothing
is cached and there is no `doctor_pass` shortcut:

```text
READY          ok
DISABLED       operation_disabled (absent or enabled=false)
MISCONFIGURED  config_invalid | logical_budget_missing | logical_budget_invalid
UNSUPPORTED    redirection_version_unsupported | adapter_unavailable
NOT_READY      runtime_unavailable | doctor_not_ready
               | physical_ceiling_mismatch | target_privileges_mismatch
```

READY requires the **effective** target privilege set - evaluated from
table-level, schema-wide and global grants - to contain every descriptor
privilege (`SELECT`, `UPDATE`) and to stay within the descriptor-reviewed
boundary. Extra effective target privileges such as `INSERT`, `DELETE`,
`REFERENCES`, `TRIGGER`, `GRANT OPTION`, `ALL` or any other unreviewed
privilege are `NOT_READY` / `target_privileges_mismatch`, even when the
presence-only generic Doctor check still sees `SELECT` + `UPDATE`. The
descriptor is the single authority for the allowed set; readiness never
restates the privilege list.

The REST integration maps these to explicit fail-closed errors:
`writeleash_operation_disabled`, `writeleash_operation_misconfigured`,
`writeleash_redirection_version_unsupported`, `writeleash_runtime_unavailable`,
`writeleash_physical_ceiling_mismatch`, `writeleash_operation_unavailable`. A
candidate request never falls back to the stock unbounded update.

### Boundary

This is the cooperative guarded-transaction model of
[GUARD.md](GUARD.md) / [ENGINE.md](ENGINE.md): a code-known adapter, a
restricted shared runtime identity, and a Guard-owned transaction. It is not
arbitrary SQL protection, hostile-DB-writer containment, or a general policy
engine. Enable/disable and the logical budget are the only Admin-tunable
values; #60 supplies the later settings/CLI surface for exactly those values.
