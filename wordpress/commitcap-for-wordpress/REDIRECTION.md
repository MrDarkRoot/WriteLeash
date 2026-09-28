# #87 Redirection 5.5.2 Bulk Disable adapter

`CommitCap\Redirection_Bulk_Disable` is the one certified production operation
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
CommitCap procedures, SELECT-only on `commitcap_v01_state`, `SELECT, UPDATE` on
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
(`logical` / `physical` / `null`), `rollback_verified` and `reason`.

`rollback_verified` is `true` only for a `DENIED` run whose post-rollback
fingerprint (total rows and disabled rows read on a separate connection)
equals the pre-run fingerprint. It is never claimed for `COMMITTED` or for
guard failures whose transaction state is unknown.

## Evidence

`bash wordpress/tests/adapter/run.sh` runs the real pinned plugin on MySQL
8.0.44 and MariaDB 10.11.15 (WordPress 6.8.3 / PHP 8.2). It covers: N=L
COMMIT, N+1 logical denial, physical ceiling denial, L=0, L>P before callback,
fresh-observer durability, exact-version/absence refusal, Doctor FAIL and
ROTATED_UNSAFE refusal, plugin exception, DB error, swallowed error, foreign
table/DDL/INSERT attempts, existing transaction and malformed budgets. The
database general log is used as the authoritative connection-isolation check:
on the restricted connection only reviewed CommitCap evidence SQL, the exact
Redirection target-table UPDATE and the trigger's own accounting SQL may
appear.

Two observed boundary behaviors are asserted rather than hidden:

- A callback-issued DDL statement triggers an implicit commit before the
  denied statement; the open accounting row is already durable, the guard fails
  closed, and the residue blocks the next run until trusted cleanup (the
  reviewed adapter never issues DDL; this is an adversarial subclass test).
- The item-scoped `items=[...]` path keeps its own normal-connection code path
  and is never intercepted, but once a #54 physical policy is installed on
  `wp_redirection_items`, its unguarded per-ID UPDATEs are denied by the
  trigger, like every other update to a protected table. This is the accepted
  #54/#67 enforcement model, not an adapter change; it needs a Maintainer
  decision before Admin enablement (see below).

## Limitations / non-goals

- Cooperative boundary only (see GUARD.md); not hostile-DB-writer containment.
- Enable, Reset, filtered `global=true` variants, generic Redirection support,
  arbitrary tables/methods and Admin-supplied SQL are out of scope.
- No Admin UI, packaging, WordPress.org or Pro work in this issue.
- The item-scoped `items=[...]` path is not intercepted and not protected.
- A protected table is updated only through Guard: other UPDATE writers on that
  table (single-redirect edits, item-scoped bulk, hit logging) are denied by the
  physical trigger unless they run through CommitCap. Protecting
  `wp_redirection_items` therefore changes the plugin's other write paths at the
  database level. This is inherent to the PR #86 physical-ceiling model and must
  be accepted or redesigned in #54/#61 before the Free product enables this
  operation; #87 does not weaken the trigger to work around it.
