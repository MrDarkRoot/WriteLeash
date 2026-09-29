# Free V0.1 certified operation (exactly one)

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

## Code

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

## Mutable config

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

## Enable / disable semantics

`enabled=false` (or absent state) means WriteLeash does not expose the operation
as runnable **and does not silently restore the stock unbounded Disable**. The
dangerous global select-all route stays owned by WriteLeash while the plugin is
installed: the request fails closed with `503 writeleash_operation_disabled`.
This is deliberately different from "unsupported" (wrong Redirection version)
and from "not READY" (policy/runtime drift).

## Logical budget changes need zero DDL

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

## Readiness

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

## Boundary

This is the cooperative guarded-transaction model of
[GUARD.md](GUARD.md) / [ENGINE.md](ENGINE.md): a code-known adapter, a
restricted shared runtime identity, and a Guard-owned transaction. It is not
arbitrary SQL protection, hostile-DB-writer containment, or a general policy
engine. Enable/disable and the logical budget are the only Admin-tunable
values; #60 supplies the later settings/CLI surface for exactly those values.
