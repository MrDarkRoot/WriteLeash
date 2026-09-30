# WriteLeash-owned disposable demo (#58)

This is a **WriteLeash-owned disposable demo** of the cooperative Guard/Doctor
contract, not a Redirection operation or a production integration. It does not
protect all WordPress writes, back up site data, or contain hostile DB clients.
The real Redirection 5.5.2 global Bulk Disable proof remains #87/#78.

## Reviewed contract

* Target: exactly `<active WordPress prefix>writeleash_demo_rows`, an InnoDB table
  with the reviewed two-column `id INT PRIMARY KEY, value INT NOT NULL` shape
  and `WriteLeash-owned disposable demo rows v1` comment. Table names are never
  accepted from Admin input. No application or third-party table is seeded,
  updated, reset or dropped by this demo.
* Six fixture rows, ids 1–6, seeded once by the **trusted operator**.
  The only runnable row states are `A=[0,0,0,0,0,0]` and
  `B=[1,1,1,1,1,0]`; neither is a user-configurable setting.
* `L=5`, `P=6`, `UPDATE`, restricted account target grants **exactly SELECT,
  UPDATE**. `P=6` is the *smallest* physical ceiling that accepts the entire
  six-row UPDATE so the Guard's *logical* pre-COMMIT check can observe six
  accounting events and deny. Choosing `P=5` would instead deny at the physical
  trigger and fail to demonstrate the distinction.
* `Disposable_Demo::run()` executes a fixed five-row UPDATE toggling A→B
  (`value=1`) or B→A (`value=0`), then the fixed six-row UPDATE (`value=2`).
  Guard owns both transactions. The safe leg COMMITs with consumed=5/affected=5;
  the denied leg completes six row events at the trigger, raises typed
  `Budget_Denied` at Guard's pre-COMMIT check and
  attempts ROLLBACK. `run()` returns `COMPLETE` only after both exact outcomes.
  Fresh independent CI observers see B after run #1, A after run #2 and B
  after run #3, with zero denied durable changes and no trusted reset between
  runs. `run_denied()` also works from either canonical state, leaving A or B
  unchanged according to a fresh observer.

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
$fixture = new \WriteLeash\Disposable_Demo_Setup( $installer, 'cc_writer', 'localhost' );
$fixture->plan()->render_sql(); // Review exact grant/trigger before applying.
$fixture->setup();
$fixture->reset();

// Ordinary runtime path, with the operator-configured restricted credential:
$demo = new \WriteLeash\Disposable_Demo();
$ready = $demo->status();
if ( 'READY' === $ready['status'] ) {
	$result = $demo->run();
	if ( 'COMPLETE' === $result['status'] ) {
		$result_again = $demo->run(); // The restricted runtime toggles B back to A.
	}
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
`READY` additionally requires the six exact rows to be in A or B, so READY
means a normal `run()` can start. A wrong id/count or a partial/unexpected value
returns `NOT_READY / trusted_reset_required`. After the safe COMMIT, `run()`
rechecks both Doctor and the opposite canonical state before its denied leg.

Results are labeled **WriteLeash-owned disposable demo**. They report separate
`transaction_rollback_attempted`, `guard_rollback_completed` (unknown), and
`durability_verified_by_fresh_observer` (false at service level) fields. A
service result is not itself independent evidence that ROLLBACK completed.
CI creates a new root observer connection **after** Guard execution to assert
durability; root is external test/operator tooling, never a runtime secret.

One trusted initial seed/reset establishes A. Successful ordinary runs then
toggle A↔B indefinitely using **only restricted SELECT and UPDATE**, without
operator involvement. If execution stops after the safe COMMIT and before the
denied leg, the opposite canonical state is already runnable by the next
normal `run()`. Trusted `reset()` is reserved for initial seeding and recovery
from noncanonical/corrupt owned data. If a callback/DB error occurs, Guard
fails closed and the verified table remains for inspection or repair. A partial
nontransactional DDL/DCL setup or cleanup remains actionable: rerun the same
operator step after resolving a conflict. Foreign shape/trigger/grant drift is
refused, not automatically destroyed. Cleanup never drops `writeleash_v01_state`,
the five routines, the shared runtime user or the Redirection policy/data.
Ordinary plugin **deactivation performs no privileged database cleanup**;
explicit trusted demo cleanup is a separate lifecycle action.

Run the exact pinned MySQL 8.0.44 / MariaDB 10.11.15 proof as part of
`bash wordpress/tests/adapter/run.sh`. The `#58` transcript tests fresh
observers, normal-vs-restricted SQL connections, logical-vs-physical denial,
three successive normal A→B→A→B runs with a single continuous general-log
window (zero demo INSERT/DELETE, DDL, grants or trusted reset between runs),
denied-only A/B preservation, failure and tamper refusal, recovery and exact
cleanup.
