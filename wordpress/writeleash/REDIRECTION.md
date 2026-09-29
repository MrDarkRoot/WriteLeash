# #87 Redirection 5.5.2 Bulk Disable adapter

`WriteLeash\Redirection_Bulk_Disable` is the one certified production operation
for Free V0.1. Scope is exact: **Redirection 5.5.2, Redirects → select all
matching → Bulk Actions → Disable only.** Enable and Reset are not certified
and not implemented here.

## Reviewed call path

```text
Admin REST POST /wp-json/redirection/v1/bulk/redirect/disable  (global=true)
→ Red_Item::set_status_all( 'disable', array() )
→ UPDATE wp_redirection_items SET status='disabled'   (no WHERE, no filter)
```

Pinned source evidence (Redirection 5.5.2, sha256
`2c562a256797828ec3a0ddfd19425f0c1bc126554bb4baaa95434b35f361a648`):

- `api/api-redirect.php` registers `/bulk/redirect/(delete|enable|disable|reset)`;
  `route_bulk()` dispatches `global` + `enable|disable` to
  `Red_Item::set_status_all( $action, $params )`.
- `models/redirect/redirect.php` `set_status_all()` builds the filter with
  `Red_Item_Filters` and issues one `$wpdb->query( $wpdb->prepare( ... ) )`.
- `redirection.js` sets `global = true` only for the "select all matching"
  checkbox (`selectAll&&(d.global=!0`).
- #85 runtime evidence on the same ZIP: exactly one unbounded UPDATE on the
  plugin-owned InnoDB table, no plugin transaction, `MAIL: 0`, `HTTP: []`.
  Filesystem activity was not instrumented and is not claimed.

## Supported contract

```text
known operation id:  redirection-5.5.2-bulk-disable-global
exact version:       REDIRECTION_VERSION === '5.5.2'
restricted runtime:  explicit secondary wpdb (shared account)
Guard:               Guard::update( 'wp_redirection_items', L, callback, runtime_db )
actual row events:   counted by the installed physical trigger
logical budget:      0 <= L <= trusted physical ceiling P
```

The adapter refuses (outcome `UNKNOWN`) when Redirection is absent or not
exactly 5.5.2, when the reviewed `Red_Item::set_status_all()` boundary is
missing, when the runtime prefix does not match the normal WordPress prefix, or
when `Compatibility_Doctor::runtime()` is not PASS for the target policy. In
all refusal cases no plugin code runs.

Runtime grants stay the accepted PR #86 envelope: EXECUTE on the five reviewed
WriteLeash procedures, SELECT-only on `commitcap_v01_state`, `SELECT, UPDATE` on
`wp_redirection_items`, and nothing else. No helper DML, no TRIGGER/DDL/GRANT,
no unrelated plugin-table access. The adapter adds no grant of its own.

## `$wpdb` substitution

For the reviewed static call only:

```text
save original global $wpdb
→ install restricted runtime wpdb
→ invoke Red_Item::set_status_all( 'disable', array() )
→ always restore the original in finally
```

The surrounding WordPress request, the REST route, the item-scoped
`items=[...]` path and every other plugin keep the normal WordPress connection.
Substitution is not a hook and does not intercept Redirection requests.

## Result contract

`run()` returns a small factual array for #78/#60: `operation_id`, `plugin`,
`plugin_version`, `supported_version`, `table`, `logical_budget`,
`physical_ceiling`, `consumed`, `attempted`, `affected_rows`, `outcome`
(`COMMITTED` / `DENIED` / `ERROR` / `UNKNOWN`), `denial_kind`
(`logical` / `physical` / `null`), `reason`, and three separate rollback facts:

```text
transaction_rollback_attempted           true when Guard threw its typed Budget_Denied
guard_rollback_completed                 always null here: Guard's ROLLBACK return value
                                         is not checked, so completion is unknown
durability_verified_by_fresh_observer    always false at this layer
```

The adapter never manufactures fresh-observer certainty: it cannot prove
durable state from its own connection, it cannot prove the best-effort
`ROLLBACK` statement succeeded, and it never uses the normal WordPress `$wpdb`
as a verification shortcut. Only an independent observer connection (outside
the adapter) can promote durability evidence; the CI suite does that with a
`root` observer for every safe and denied case.

## REST integration (the actual Admin operation)

`WriteLeash\Redirection_Bulk_Disable_Rest` hooks WordPress's
`rest_dispatch_request` filter, which runs **after** the matched route's
permission callback has succeeded. Returning a non-null result skips
Redirection's `route_bulk()` entirely, so the stock unbounded UPDATE on the
normal connection cannot run for a certified request.

Matching is deliberately split into three steps:

1. candidate dangerous route/shape: concrete path
   `/redirection/v1/bulk/redirect/disable`, `POST`, `bulk=disable`, truthy
   `global`, no non-empty `items`, no `filterBy` conditions;
2. version certification: only Redirection `5.5.2` proceeds. Any other or
   unavailable version returns `503 writeleash_redirection_version_unsupported`
   **without calling the stock handler**; callback identity is not required for
   this refusal, so a handler refactor in a later build cannot turn the
   protection back into an unbounded stock update;
3. handler certification: the matched handler must be
   `Redirection_Api_Redirect::route_bulk`; a mismatch also fails closed.

Everything that is not a candidate is untouched stock Redirection:
item-scoped `items=[...]`, `global=false`, Enable, Reset, Delete, filtered
`global=true` variants, single-item edits and hit/stat writers.

- budget and enablement source: the #78 immutable descriptor
  (`Certified_Operation`) plus the single mutable `Operation_Config` state
  (`enabled`, `logical_budget`). The legacy #87 option
  `writeleash_operation_budget_redirection_5_5_2_bulk_disable` is no longer
  read; there is exactly one budget authority. No Admin SQL/table/method input.
  See [OPERATION.md](OPERATION.md).
- runtime connection: `WRITELEASH_DB_USER` / `WRITELEASH_DB_PASSWORD`
  (`WRITELEASH_DB_NAME` optional) on the normal connection's DB server, with the
  normal table prefix. `WRITELEASH_DB_HOST` is the account-host restriction in the
  provisioning snippet, not a connect host.
- responses: `COMMITTED` returns Redirection's own read-only list body plus a
  `writeleash` evidence key; `DENIED` returns `writeleash_budget_denied` (409);
  unsupported/unknown Redirection versions return 503
  `writeleash_redirection_version_unsupported`; a disabled operation returns 503
  `writeleash_operation_disabled`; invalid stored config returns 503
  `writeleash_operation_misconfigured`; an unavailable runtime returns 503
  `writeleash_runtime_unavailable`; a physical-ceiling mismatch returns 503
  `writeleash_physical_ceiling_mismatch`; a target-privilege drift beyond the
  descriptor-reviewed `SELECT`/`UPDATE` boundary (extra `INSERT`, `DELETE`,
  `REFERENCES`, ...) and other non-READY readiness return 503
  `writeleash_operation_unavailable`; an execution error returns 500. None of
  these fall back to the unguarded stock mutation.
- permissions: unchanged. WordPress runs Redirection's own
  `permission_callback_bulk` before the filter; nonce/cookie authentication is
  inherited from WordPress REST authentication before dispatch, so an
  unauthorized caller never reaches WriteLeash and never mutates. The
  executable unauthorized-user suite case proves the adapter is never reached.

## Evidence

`bash wordpress/tests/adapter/run.sh` runs the real pinned plugin on MySQL
8.0.44 and MariaDB 10.11.15 (WordPress 6.8.3 / PHP 8.2). It covers: N=L
COMMIT, N+1 logical denial, physical ceiling denial, L=0, L>P before callback,
fresh-observer durability, exact-version/absence refusal, Doctor FAIL and
ROTATED_UNSAFE refusal, plugin exception, DB error, swallowed error, foreign
table/DDL/INSERT/DELETE/REPLACE attempts, existing transaction and malformed
budgets. The database general log is used as the authoritative
connection-isolation check: on the restricted connection only reviewed
WriteLeash evidence SQL, the exact Redirection target-table UPDATE and the
trigger's own accounting SQL may appear.

Real REST end-to-end coverage dispatches the actual
`POST /redirection/v1/bulk/redirect/disable` request through `WP_REST_Server`
(not the adapter directly) for safe COMMIT, logical denial, physical denial,
missing/malformed budget, `L>P`, Doctor-not-ready and version drift (newer
`5.5.3`, older `5.4.0`, unavailable `null` via a narrow test-only subclass of
the version detector); in every fail-closed case the general log proves zero
plugin UPDATEs on any connection and zero durable state changes, and for a
certified request exactly one plugin UPDATE on a non-normal connection with
zero stock global UPDATEs on the normal connection. Non-certified
routes (item-scoped `items=[...]`, Enable, Reset, Delete) stay stock and send
zero SQL to the restricted connection, and an unauthorized user is denied by
Redirection's own permission callback before WriteLeash runs.

The pre-scoping design broke these cases; the scoped-enforcement redesign made
them pass, and they remain executable regressions:

- normal WordPress-identity `UPDATE` on `wp_redirection_items` succeeds under
  the installed policy;
- the item-scoped `items=[...]` REST path is unchanged: stock Redirection
  behavior, normal connection, zero restricted-connection SQL;
- the single-item `Red_Item::disable()/enable()` path works;
- the hit/stat writer `Red_Item::visit()` (`UPDATE ... SET
  last_count=last_count+1, last_access=NOW() WHERE id=...`) works;
- the restricted runtime outside Guard is still denied, including with forged
  session state (`SET @commitcap_v01_denied = 0`);
- a direct lifecycle call (`CALL commitcap_v01_open` + unaudited UPDATE) remains
  outside the cooperative contract but is still bounded by physical P;
- an explicitly granted foreign DB principal is outside cooperative
  enforcement and classified as such.

One observed boundary behavior is asserted rather than hidden: a callback-issued
DDL statement triggers an implicit commit before the denied statement, so the
open accounting row is already durable; the guard fails closed and the residue
blocks the next run until trusted cleanup (the reviewed adapter never issues
DDL; this is an adversarial subclass test).

## Limitations / non-goals

- Cooperative boundary only (see GUARD.md); not hostile-DB-writer containment.
- Enable, Reset, filtered `global=true` variants, generic Redirection support,
  arbitrary tables/methods and Admin-supplied SQL are out of scope.
- No packaging, WordPress.org or Pro work in this issue. The Admin/CLI
  surfaces were added later by #60 ([ADMIN-CLI.md](ADMIN-CLI.md)); the final
  cooperative threat model is [THREAT-MODEL.md](THREAT-MODEL.md).
- The item-scoped `items=[...]` path is not intercepted, not routed through
  WriteLeash and not budget-protected; it keeps stock behavior by design.
- The physical policy bounds only the certified runtime identity. An explicitly
  granted foreign DB principal with UPDATE on the table is outside cooperative
  enforcement; grants to such principals are an operator decision.
