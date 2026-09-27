# CommitCap for WordPress — V0.1 development foundation

Canonical plugin directory slug: `commitcap-for-wordpress`; WordPress plugin basename: `commitcap-for-wordpress/commitcap-for-wordpress.php`.

This subtree is a WordPress plugin foundation, not a released security control.
#55 adds a loadable plugin, baseline activation checks, conservative deactivation,
and uninstall of one plugin-owned option (`commitcap_version`). Loading the plugin
registers hooks only; activation reads WordPress/PHP/database version facts and
stores the version option if the basic runtime checks pass. It does not change
user data, database objects, or storage engines. Deactivation retains that option
and makes no database changes: **deactivation is not uninstall**. Explicit
uninstall removes only `commitcap_version` for the current site; an unrelated
option with a similar name is never removed. Network activation and multisite
cleanup have not been validated; network activation is refused without writing
plugin metadata. The version header is developmental; no plugin license decision
has been made (#64).

**MySQL/MariaDB does not currently provide the PostgreSQL-equivalent sticky
transaction boundary demonstrated by CommitCap's PostgreSQL research path.**
The planned WordPress V0.1 contract is a **cooperative guarded-transaction
model**. **The guard/engine itself is NOT implemented by #55.** See the [#53
feasibility experiment](../../experiments/mysql_tx_budget/README.md): event six
was denied on the pinned test servers, but COMMIT still succeeded after savepoint
recovery. Autocommit issues separate transaction authority for each statement.
This plugin therefore does not claim to protect WordPress writes.

The intended investigation targets are MySQL 8.0+, MariaDB 10.11+, and InnoDB.
#57 adds a read-only, fail-closed [compatibility doctor](DOCTOR.md) for the
accepted cooperative path; its PASS requires separate restricted runtime and
trusted installer evidence. It does not approve generic hosting compatibility.
#54 adds a lower-level engine candidate and its deliberately narrow contract in
[`ENGINE.md`](ENGINE.md); #56 adds the cooperative guarded UPDATE transaction
API in [`GUARD.md`](GUARD.md).
The #54 routines remain callable by a DB writer: CLOSE→OPEN in one transaction
can reset its own budget, and the session denial signal is writable. The
split-privilege fixture is only a cooperative mechanism, not an adversarial
database-writer security boundary. Application code must not invoke these
lifecycle routines directly; only `CommitCap\Guard::update()` owns them for a
supported job.
Later issues cover demos and user-facing integrations. No admin UI, WP-CLI
integration, WooCommerce integration, telemetry, external network requests,
or cloud feature is present in this foundation.

## Guarded UPDATE example

```php
$result = \CommitCap\Guard::update(
	$wpdb->prefix . 'repair_items',
	50,
	function () use ( $wpdb ) {
		// Ordinary bounded UPDATE statements on the protected table.
		foreach ( $ids as $id ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . 'repair_items SET repaired = 1 WHERE id = %d', $id ) );
		}
		return count( $ids );
	}
);
```

`Guard` starts and finishes its own transaction, counts row events with the #54
policy, rolls back on failures observed while its owned transaction is still
intact before its COMMIT call, and returns the callback result only after COMMIT.
Failures at or after a commit attempt (a callback-issued COMMIT, an
unconfirmable Guard COMMIT, or a post-commit state anomaly) are reported
without claiming an undo, because durability may already exist.
SQL outside `Guard::update()`, manual transaction control, direct #54 routine
calls, side-effecting stored functions with extra EXECUTE privilege, and direct
mysqli/`$wpdb->dbh` SQL are outside the supported cooperative contract. See
[`GUARD.md`](GUARD.md) for the exact contract, detection behavior
and limitations. This is not a hostile-writer database security boundary and it
does not protect all WordPress writes.

## Development checks

From the repository root with Docker Engine and Compose:

```sh
bash wordpress/tests/run.sh mysql
bash wordpress/tests/run.sh mariadb
bash wordpress/tests/engine/run.sh   # #54 engine + #56 Guard + #57 doctor on both pinned DBs
```

Each foundation run boots WordPress 6.8.3 under PHP 8.2 against a fresh,
disposable MySQL 8.0.44 or MariaDB 10.11.15 fixture. It performs real
activation/deactivation/uninstall calls, checks failure paths and database
objects, runs PHP lint/direct-access checks, and destroys the fixture on exit.
This is a foundation lifecycle test, not a compatibility certification or the
full WordPress matrix (#62).
