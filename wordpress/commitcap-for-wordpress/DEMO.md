# CommitCap-owned disposable demo (#58)

This is a **CommitCap-owned disposable demo** of the cooperative Guard/Doctor
contract, not a Redirection operation or a production integration. It does not
protect all WordPress writes, back up site data, or contain hostile DB clients.
The real Redirection 5.5.2 global Bulk Disable proof remains #87/#78.

## Reviewed contract

* Target: exactly `<active WordPress prefix>commitcap_demo_rows`, an InnoDB table
  with the reviewed two-column `id INT PRIMARY KEY, value INT NOT NULL` shape
  and `CommitCap-owned disposable demo rows v1` comment. Table names are never
  accepted from Admin input. No application or third-party table is seeded,
  updated, reset or dropped by this demo.
* Six fixture rows: ids 1–6, value 0, seeded/reset by the **trusted operator**.
* `L=5`, `P=6`, `UPDATE`, restricted account target grants **exactly SELECT,
  UPDATE**. `P=6` is the *smallest* physical ceiling that accepts the entire
  six-row UPDATE so the Guard's *logical* pre-COMMIT check can observe six
  accounting events and deny. Choosing `P=5` would instead deny at the physical
  trigger and fail to demonstrate the distinction.
* `Disposable_Demo::run()` executes the fixed five-row UPDATE (`value=1`), then
  the fixed six-row UPDATE (`value=2`). Guard owns both transactions. The first
  COMMITs with consumed=5/affected=5; the second completes six row events at
  the trigger, raises typed `Budget_Denied` at Guard's pre-COMMIT check and
  attempts ROLLBACK. `run()` returns `COMPLETE` only after both exact outcomes.
  A fresh independent observer in CI sees values `[1,1,1,1,1,0]` afterward:
  five safe durable changes, zero denied durable changes.
  `run_denied()` is a fixed standalone six-row denial after a trusted reset:
  a fresh observer then sees all six original zero-valued rows unchanged.

## Operator setup and reuse

The shared restricted runtime account and five DEFINER routines must already
exist under [PROVISIONING.md](PROVISIONING.md). The operator supplies a distinct
**temporary trusted installer `wpdb`** to `Disposable_Demo_Setup`; no installer
credentials enter normal web PHP or `wp_options`. The class never creates or
drops users/shared infrastructure. `plan()` exposes the exact #84 demo policy
plan for offline review; `setup()` creates only the owned InnoDB table and
applies that plan, `reset()` writes only the verified owned rows, and
`cleanup_status()` / `cleanup()` inspect and remove only this table, its
canonical policy trigger and its target grants. Provision, reset and cleanup
are operator/test-tool actions, **not** normal WordPress request actions.

```php
// Trusted offline operator/test process only; $installer is supplied and
// destroyed externally. Account/DEFINER infrastructure was provisioned by #84.
$fixture = new \CommitCap\Disposable_Demo_Setup( $installer, 'cc_writer', 'localhost' );
$fixture->plan()->render_sql(); // Review exact grant/trigger before applying.
$fixture->setup();
$fixture->reset();

// Ordinary runtime path, with the operator-configured restricted credential:
$demo = new \CommitCap\Disposable_Demo();
$ready = $demo->status();
if ( 'READY' === $ready['status'] ) {
	$result = $demo->run();
}

// Later, from the trusted operator context only:
$fixture->cleanup_status();
$fixture->cleanup();
```

`status()` uses the explicit secondary restricted `wpdb` (or the #78 operator
config connection), verifies normal/runtime endpoint and identity separation,
exact target privileges, the owned table shape/comment, and a **live** runtime
Doctor PASS. Doctor checks canonical trigger/DEFINER, physical ceiling, helper
and routine evidence, transaction preconditions and shared-runtime sibling
reachability. When Redirection is reachable on the same runtime, its existing
certified descriptor supplies the known sibling table and expected P=2000;
the demo never registers a second production operation or changes that
descriptor. Doctor behaviorally probes **only the disposable demo target**;
the Redirection sibling is verified structurally without an UPDATE (including
no-op UPDATE) against its application table. Each callback is gated by a new
Doctor result. Missing policy,
unavailable runtime, unexpected grants/ceiling or UNKNOWN evidence means
`NOT_READY`, without executing that callback.

Results are labeled **CommitCap-owned disposable demo**. They report separate
`transaction_rollback_attempted`, `guard_rollback_completed` (unknown), and
`durability_verified_by_fresh_observer` (false at service level) fields. A
service result is not itself independent evidence that ROLLBACK completed.
CI creates a new root observer connection **after** Guard execution to assert
durability; root is external test/operator tooling, never a runtime secret.

Repeated use requires a trusted `reset()` (six rows set back to zero), followed
by another live `status()` and `run()`. If a callback/DB error occurs, Guard
fails closed and the verified table remains for inspection/reset. A partial
nontransactional DDL/DCL setup or cleanup remains actionable: rerun the same
operator step after resolving a conflict. Foreign shape/trigger/grant drift is
refused, not automatically destroyed. Cleanup never drops `commitcap_v01_state`,
the five routines, the shared runtime user or the Redirection policy/data.
Ordinary plugin **deactivation performs no privileged database cleanup**;
explicit trusted demo cleanup is a separate lifecycle action.

Run the exact pinned MySQL 8.0.44 / MariaDB 10.11.15 proof as part of
`bash wordpress/tests/adapter/run.sh`. The `#58` transcript tests fresh
observers, normal-vs-restricted SQL connections, logical-vs-physical denial,
failure and tamper refusal, reset/repeat, interruption and exact cleanup.
