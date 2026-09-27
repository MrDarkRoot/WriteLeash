<?php
// #56 guarded transaction API acceptance tests.
// Runs through WP-CLI on the pinned MySQL 8.0.44 / MariaDB 10.11.15 fixtures
// after the #54 engine cases. Fresh trusted connections verify durability.
require_once WP_PLUGIN_DIR . '/commitcap/commitcap.php';

use CommitCap\Budget_Denied;
use CommitCap\Guard;
use CommitCap\Guard_Error;
use CommitCap\Guard_Transaction;
use CommitCap\Unsupported_Transaction_State;
use CommitCap\Update_Engine;

function cc56_assert( $value, $label ) {
	if ( ! $value ) {
		throw new RuntimeException( $label );
	}
}

function cc56_reject( $callback, $class, $label ) {
	try {
		$callback();
	} catch ( Throwable $error ) {
		if ( $error instanceof $class ) {
			return $error;
		}
		throw new RuntimeException(
			$label . ': expected ' . $class . ', caught ' . get_class( $error ) . ': ' . $error->getMessage()
		);
	}
	throw new RuntimeException( $label . ': expected ' . $class . ', nothing was thrown' );
}

function cc56_seed( $root, $table ) {
	cc56_assert( false !== $root->query( "TRUNCATE TABLE `$table`" ), 'seed truncate: ' . $root->last_error );
	for ( $i = 1; $i <= 10; ++$i ) {
		cc56_assert( false !== $root->query( "INSERT INTO `$table` (id, touched) VALUES ($i, 0)" ), 'seed insert: ' . $root->last_error );
	}
}

function cc56_rows( $host, $table ) {
	$fresh = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
	cc56_assert( $fresh->ready, 'fresh durable observer unavailable' );
	return array_map( 'intval', $fresh->get_col( "SELECT touched FROM `$table` ORDER BY id" ) );
}

function cc56_clean( $db ) {
	$db->query( 'SELECT 1' );
	cc56_assert( '' === (string) $db->last_error, 'connection error state not clean: ' . $db->last_error );
}

function cc56_updates( $db, $table, $ids ) {
	foreach ( $ids as $id ) {
		cc56_assert( 1 === (int) $db->query( "UPDATE `$table` SET touched = touched + 1 WHERE id = " . (int) $id ), 'guarded update failed: ' . $db->last_error );
	}
}

function cc56_discard_accounting( $root, $db, $table ) {
	$connection_id = (int) $db->get_var( 'SELECT CONNECTION_ID()' );
	$policy_id     = hash( 'sha256', $table );
	cc56_assert(
		false !== $root->query( $root->prepare(
			'DELETE FROM commitcap_v01_state WHERE connection_id = %d AND policy_id = %s',
			$connection_id,
			$policy_id
		) ),
		'trusted accounting cleanup failed: ' . $root->last_error
	);
}

$host = getenv( 'CC_ENGINE_HOST' );
cc56_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), 'unknown server fixture' );

$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
$installer = new Update_Engine( $root );
$installer->install_infrastructure();

$policies = array(
	'cc_guard_a'    => 5,
	'cc_guard_b'    => 3,
	'cc_guard_zero' => 0,
);
foreach ( $policies as $table => $budget ) {
	$root->query( "DROP TABLE IF EXISTS `$table`" );
	cc56_assert( false !== $root->query( "CREATE TABLE `$table` (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB" ), 'create table' );
	cc56_seed( $root, $table );
	$installer->install_policy( $table, $budget );
	$root->query( "GRANT SELECT, UPDATE ON wp_test.`$table` TO 'cc_writer'@'%'" );
}
$root->query( 'DROP TABLE IF EXISTS cc_guard_plain' );
cc56_assert( false !== $root->query( 'CREATE TABLE cc_guard_plain (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB' ), 'create plain' );
cc56_seed( $root, 'cc_guard_plain' );
$root->query( "GRANT SELECT, UPDATE ON wp_test.`cc_guard_plain` TO 'cc_writer'@'%'" );

$writer = new wpdb( 'cc_writer', 'disposable_writer_password', 'wp_test', $host );
$writer->suppress_errors( true );
cc56_assert( $writer->ready, 'guard writer connection unavailable' );
$writer_two = new wpdb( 'cc_writer', 'disposable_writer_password', 'wp_test', $host );
$writer_two->suppress_errors( true );

$version = $root->get_var( 'SELECT VERSION()' );
cc56_assert( 0 === strpos( $version, 'mysql' === $host ? '8.0.44' : '10.11.15' ), 'unpinned server: ' . $version );
echo "#56 $host $version\n";

// 1. Transaction-state probe itself.
$probe = new Guard_Transaction( $writer );
cc56_assert( false === $probe->active(), 'probe reported transaction outside one' );
cc56_assert( false !== $writer->query( 'START TRANSACTION' ), 'start probe transaction' );
cc56_assert( true === $probe->active(), 'probe missed an explicit transaction' );
cc56_assert( false !== $writer->query( 'ROLLBACK' ), 'rollback probe transaction' );
cc56_assert( false === $probe->active(), 'probe reported transaction after rollback' );
echo "  transaction-state savepoint probe: PASS\n";

// 2. Safe five-event callback commits and is durable.
cc56_seed( $root, 'cc_guard_a' );
$writer->suppress_errors( false );
$result = Guard::update(
	'cc_guard_a',
	5,
	function () use ( $writer ) {
		cc56_updates( $writer, 'cc_guard_a', array( 1, 2, 3, 4, 5 ) );
		return 'safe-result';
	},
	$writer
);
$writer->suppress_errors( true );
cc56_assert( 'safe-result' === $result, 'callback return value was not returned' );
cc56_assert( array( 1, 1, 1, 1, 1, 0, 0, 0, 0, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'safe durability' );
echo "  safe five-event callback commits exactly five: PASS\n";

// 3. Multiple UPDATE statements share one budget.
cc56_seed( $root, 'cc_guard_a' );
$result = Guard::update(
	'cc_guard_a',
	5,
	function () use ( $writer ) {
		cc56_updates( $writer, 'cc_guard_a', array( 1, 2 ) );
		cc56_updates( $writer, 'cc_guard_a', array( 3, 4, 5 ) );
		return 'two-statements';
	},
	$writer
);
cc56_assert( 'two-statements' === $result, 'multi-statement return value' );
cc56_assert( array( 1, 1, 1, 1, 1, 0, 0, 0, 0, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'multi-statement durability' );
echo "  multiple UPDATE statements share one budget: PASS\n";

// 4. Same row repeatedly consumes repeated events; a zero-row UPDATE consumes zero.
cc56_seed( $root, 'cc_guard_b' );
$result = Guard::update(
	'cc_guard_b',
	3,
	function () use ( $writer ) {
		for ( $i = 0; $i < 3; ++$i ) {
			cc56_updates( $writer, 'cc_guard_b', array( 1 ) );
		}
		cc56_assert( 0 === (int) $writer->query( 'UPDATE cc_guard_b SET touched = touched + 1 WHERE id = -999' ), 'zero-row update' );
		return 'repeat-three';
	},
	$writer
);
cc56_assert( 'repeat-three' === $result, 'repeated row return value' );
$rows = cc56_rows( $host, 'cc_guard_b' );
cc56_assert( 3 === $rows[0] && array_sum( $rows ) === 3, 'repeated row / zero-row durability' );
cc56_clean( $writer );
echo "  same row repeatedly counts; zero-row UPDATE consumes zero: PASS\n";

// 5. Zero-event callback commits its accounting cleanly.
cc56_seed( $root, 'cc_guard_b' );
$result = Guard::update( 'cc_guard_b', 3, function () { return array( 'no' => 'work' ); }, $writer );
cc56_assert( array( 'no' => 'work' ) === $result, 'zero-event return value' );
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_b' ), 'zero-event durability' );
echo "  zero-event callback commits cleanly: PASS\n";

// 6. Event-six denial detects, rolls back the whole guard and is never durable.
cc56_seed( $root, 'cc_guard_a' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update(
			'cc_guard_a',
			5,
			function () use ( $writer ) {
				cc56_updates( $writer, 'cc_guard_a', array( 1, 2, 3, 4, 5 ) );
				// Event six fails and is deliberately ignored by the callback.
				$writer->query( 'UPDATE cc_guard_a SET touched = touched + 1 WHERE id = 6' );
			},
			$writer
		);
	},
	Budget_Denied::class,
	'event-six denial'
);
$details = $error->details();
cc56_assert( 'cc_guard_a' === $details['table'], 'denial table' );
cc56_assert( 5 === $details['budget'], 'denial budget' );
cc56_assert( 5 === $details['consumed'], 'denial consumed' );
cc56_assert( 6 === $details['attempted'], 'denial attempted' );
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'denial durable excess' );
cc56_clean( $writer );
echo "  event-six denial rolls back the entire guarded transaction: PASS\n";

// 7. Broad single-statement denial also rolls back everything.
cc56_seed( $root, 'cc_guard_a' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update(
			'cc_guard_a',
			5,
			function () use ( $writer ) {
				$writer->query( 'UPDATE cc_guard_a SET touched = touched + 1 WHERE id <= 6' );
			},
			$writer
		);
	},
	Budget_Denied::class,
	'broad denial'
);
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'broad denial durability' );
cc56_clean( $writer );
echo "  broad single-statement denial rolls back everything: PASS\n";

// 8. Budget zero denies the first row event and rolls back.
cc56_seed( $root, 'cc_guard_zero' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update(
			'cc_guard_zero',
			0,
			function () use ( $writer ) {
				$writer->query( 'UPDATE cc_guard_zero SET touched = touched + 1 WHERE id = 1' );
			},
			$writer
		);
	},
	Budget_Denied::class,
	'budget zero'
);
$details = $error->details();
cc56_assert( 0 === $details['budget'] && 1 === $details['attempted'], 'budget zero details' );
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_zero' ), 'budget zero durability' );
cc56_clean( $writer );
echo "  budget zero denies the first event and rolls back: PASS\n";

// 9. Callback exceptions roll back and are preserved as the previous error.
cc56_seed( $root, 'cc_guard_a' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update(
			'cc_guard_a',
			5,
			function () use ( $writer ) {
				cc56_updates( $writer, 'cc_guard_a', array( 1, 2, 3 ) );
				throw new RuntimeException( 'callback boom' );
			},
			$writer
		);
	},
	Guard_Error::class,
	'callback exception'
);
cc56_assert( 'callback_failed' === $error->reason(), 'callback exception reason' );
cc56_assert( $error->getPrevious() instanceof RuntimeException, 'callback exception previous' );
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'callback exception durability' );
cc56_clean( $writer );

$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update(
			'cc_guard_a',
			5,
			function () use ( $writer ) {
				cc56_updates( $writer, 'cc_guard_a', array( 1 ) );
				throw new Error( 'callback php error' );
			},
			$writer
		);
	},
	Guard_Error::class,
	'callback error'
);
cc56_assert( $error->getPrevious() instanceof Error, 'callback Error previous' );
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'callback Error durability' );
cc56_clean( $writer );
echo "  callback exception and Error roll back with the original cause: PASS\n";

// 10. A generic DB error on the callback's last statement rolls back.
cc56_seed( $root, 'cc_guard_a' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update(
			'cc_guard_a',
			5,
			function () use ( $writer ) {
				cc56_updates( $writer, 'cc_guard_a', array( 1, 2 ) );
				$writer->query( 'UPDATE cc_guard_a SET no_such_column = 1' );
			},
			$writer
		);
	},
	Guard_Error::class,
	'generic db error'
);
cc56_assert( 'database_error' === $error->reason(), 'generic error reason' );
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'generic error durability' );
cc56_clean( $writer );
echo "  generic database error rolls back: PASS\n";

// 11. A swallowed wpdb failure followed by a successful query still rolls back.
cc56_seed( $root, 'cc_guard_a' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update(
			'cc_guard_a',
			5,
			function () use ( $writer ) {
				cc56_updates( $writer, 'cc_guard_a', array( 1 ) );
				$writer->query( 'UPDATE cc_guard_a SET no_such_column = 1' ); // ignored failure
				cc56_updates( $writer, 'cc_guard_a', array( 2 ) ); // later success would clear last_error
			},
			$writer
		);
	},
	Guard_Error::class,
	'swallowed wpdb failure'
);
cc56_assert( 'database_error' === $error->reason(), 'swallowed failure reason' );
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'swallowed failure durability' );
cc56_clean( $writer );

// 11b. Same detection while wpdb prints/logs errors instead of suppressing them.
$writer->show_errors    = false;
$writer->suppress_errors = false;
$error                  = cc56_reject(
	function () use ( $writer ) {
		Guard::update(
			'cc_guard_a',
			5,
			function () use ( $writer ) {
				$writer->query( 'UPDATE cc_guard_a SET no_such_column = 1' );
				$writer->query( 'SELECT 1' );
			},
			$writer
		);
	},
	Guard_Error::class,
	'swallowed failure with suppress_errors(false)'
);
$writer->suppress_errors = true;
cc56_assert( 'database_error' === $error->reason(), 'suppress_errors(false) reason' );
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'suppress_errors(false) durability' );
cc56_clean( $writer );
echo "  swallowed wpdb false/error is detected (both error modes): PASS\n";

// 12. Nested guard is rejected before any nested mutation.
cc56_seed( $root, 'cc_guard_a' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update(
			'cc_guard_a',
			5,
			function () use ( $writer ) {
				cc56_updates( $writer, 'cc_guard_a', array( 1 ) );
				Guard::update( 'cc_guard_b', 3, function () { return null; }, $writer );
			},
			$writer
		);
	},
	Unsupported_Transaction_State::class,
	'nested guard'
);
cc56_assert( 'nested_guard' === $error->reason(), 'nested guard reason' );
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'nested guard durability' );
cc56_clean( $writer );
echo "  nested guard rejected and outer guard rolls back: PASS\n";

// 13. An existing transaction is rejected before the protected callback runs.
cc56_seed( $root, 'cc_guard_a' );
$ran = false;
cc56_assert( false !== $writer->query( 'START TRANSACTION' ), 'start existing tx' );
cc56_updates( $writer, 'cc_guard_plain', array( 1 ) );
$error = cc56_reject(
	function () use ( $writer, &$ran ) {
		Guard::update(
			'cc_guard_a',
			5,
			function () use ( &$ran ) {
				$ran = true;
			},
			$writer
		);
	},
	Unsupported_Transaction_State::class,
	'existing transaction'
);
cc56_assert( 'existing_transaction' === $error->reason(), 'existing transaction reason' );
cc56_assert( false === $ran, 'callback ran inside existing transaction' );
cc56_assert( false !== $writer->query( 'ROLLBACK' ), 'rollback caller transaction' );
cc56_clean( $writer );
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'existing transaction durability' );

// 13b. A bare START TRANSACTION is also detected (the savepoint probe sees it).
cc56_assert( false !== $writer->query( 'START TRANSACTION' ), 'start bare tx' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update( 'cc_guard_a', 5, function () { return null; }, $writer );
	},
	Unsupported_Transaction_State::class,
	'bare existing transaction'
);
cc56_assert( 'existing_transaction' === $error->reason(), 'bare transaction reason' );
cc56_assert( false !== $writer->query( 'ROLLBACK' ), 'rollback bare tx' );
cc56_clean( $writer );
echo "  existing transaction (including bare START TRANSACTION) rejected before callback: PASS\n";

// 14. autocommit=0 is refused; the guard never silently changes session state.
cc56_assert( false !== $writer->query( 'SET autocommit = 0' ), 'disable autocommit' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update( 'cc_guard_a', 5, function () { return null; }, $writer );
	},
	Unsupported_Transaction_State::class,
	'autocommit off'
);
cc56_assert( 'autocommit_off' === $error->reason(), 'autocommit reason' );
cc56_assert( false !== $writer->query( 'COMMIT' ), 'commit autocommit-0 work' );
cc56_assert( false !== $writer->query( 'SET autocommit = 1' ), 'restore autocommit' );
cc56_clean( $writer );
cc56_assert( '1' === (string) $writer->get_var( 'SELECT @@autocommit' ), 'autocommit not restored' );
echo "  autocommit=0 refused without changing session state: PASS\n";

// 15. A dirty connection error state is refused.
$writer->query( 'SELECT * FROM cc56_definitely_missing_table' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update( 'cc_guard_a', 5, function () { return null; }, $writer );
	},
	Unsupported_Transaction_State::class,
	'dirty error state'
);
cc56_assert( 'dirty_error_state' === $error->reason(), 'dirty error reason' );
cc56_clean( $writer );
echo "  dirty connection error state refused: PASS\n";

// 16. Invalid table and budget are rejected before any transaction or callback.
$ran = false;
cc56_reject(
	function () use ( $writer, &$ran ) {
		Guard::update( 'cc_guard_a', '01', function () use ( &$ran ) { $ran = true; }, $writer );
	},
	InvalidArgumentException::class,
	'invalid budget'
);
cc56_reject(
	function () use ( $writer, &$ran ) {
		Guard::update( 'cc guard a', 5, function () use ( &$ran ) { $ran = true; }, $writer );
	},
	InvalidArgumentException::class,
	'invalid table'
);
cc56_assert( false === $ran, 'callback ran for invalid input' );
$probe = new Guard_Transaction( $writer );
cc56_assert( false === $probe->active(), 'invalid input left a transaction open' );
echo "  invalid table/budget rejected before transaction and callback: PASS\n";

// 17. Missing or mismatched policy fails before the callback mutates anything.
$ran = false;
$error = cc56_reject(
	function () use ( $writer, &$ran ) {
		Guard::update(
			'cc_guard_plain',
			5,
			function () use ( &$ran ) { $ran = true; },
			$writer
		);
	},
	Guard_Error::class,
	'missing policy'
);
cc56_assert( 'policy_unverified' === $error->reason(), 'missing policy reason' );
$error = cc56_reject(
	function () use ( $writer, &$ran ) {
		Guard::update(
			'cc_guard_b',
			4,
			function () use ( &$ran ) { $ran = true; },
			$writer
		);
	},
	Guard_Error::class,
	'wrong budget policy'
);
cc56_assert( 'policy_unverified' === $error->reason(), 'wrong policy reason' );
cc56_assert( false === $ran, 'callback ran without verified policy' );
echo "  missing/mismatched policy fails before callback mutation: PASS\n";

// 18. A direct wpdb COMMIT in the callback is a detected contract violation.
cc56_seed( $root, 'cc_guard_a' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update(
			'cc_guard_a',
			5,
			function () use ( $writer ) {
				cc56_updates( $writer, 'cc_guard_a', array( 1, 2 ) );
				$writer->query( 'COMMIT' );
			},
			$writer
		);
	},
	Guard_Error::class,
	'callback wpdb COMMIT'
);
cc56_assert( 'callback_issued_reserved_sql' === $error->reason(), 'callback COMMIT reason' );
cc56_assert( array( 1, 1, 0, 0, 0, 0, 0, 0, 0, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'callback COMMIT durability' );
// The callback's COMMIT also committed the accounting row; the guard refuses
// stale state on this connection until trusted maintenance clears it.
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update( 'cc_guard_a', 5, function () { return null; }, $writer );
	},
	Unsupported_Transaction_State::class,
	'stale accounting after direct COMMIT'
);
cc56_assert( 'stale_accounting_state' === $error->reason(), 'stale accounting reason: ' . $error->reason() );
cc56_discard_accounting( $root, $writer, 'cc_guard_a' );
cc56_clean( $writer );
echo "  direct callback COMMIT: DETECTED CONTRACT VIOLATION (durability not prevented; stale accounting fails closed)\n";

// 19. A direct wpdb ROLLBACK in the callback is detected and nothing is durable.
cc56_seed( $root, 'cc_guard_a' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update(
			'cc_guard_a',
			5,
			function () use ( $writer ) {
				cc56_updates( $writer, 'cc_guard_a', array( 1, 2 ) );
				$writer->query( 'ROLLBACK' );
			},
			$writer
		);
	},
	Guard_Error::class,
	'callback wpdb ROLLBACK'
);
cc56_assert(
	'callback_issued_reserved_sql' === $error->reason(),
	'callback ROLLBACK reason: ' . $error->reason() . ' prev: ' . ( $error->getPrevious() ? get_class( $error->getPrevious() ) . ': ' . $error->getPrevious()->getMessage() : 'none' )
);
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'callback ROLLBACK durability' );
cc56_clean( $writer );
echo "  direct callback ROLLBACK: DETECTED CONTRACT VIOLATION, nothing durable\n";

// 20. A direct mysqli COMMIT that bypasses the monitor is detected by ownership loss.
cc56_seed( $root, 'cc_guard_b' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update(
			'cc_guard_b',
			3,
			function () use ( $writer ) {
				cc56_updates( $writer, 'cc_guard_b', array( 1 ) );
				$writer->dbh->query( 'COMMIT' );
			},
			$writer
		);
	},
	Guard_Error::class,
	'callback mysqli COMMIT'
);
cc56_assert( 'transaction_lost' === $error->reason(), 'mysqli COMMIT reason: ' . $error->reason() );
cc56_assert( array( 1, 0, 0, 0, 0, 0, 0, 0, 0, 0 ) === cc56_rows( $host, 'cc_guard_b' ), 'mysqli COMMIT durability' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update( 'cc_guard_b', 3, function () { return null; }, $writer );
	},
	Unsupported_Transaction_State::class,
	'stale accounting after mysqli COMMIT'
);
cc56_assert( 'stale_accounting_state' === $error->reason(), 'stale mysqli accounting reason: ' . $error->reason() );
cc56_discard_accounting( $root, $writer, 'cc_guard_b' );
cc56_clean( $writer );
echo "  direct mysqli COMMIT: DETECTED CONTRACT VIOLATION via ownership loss (durability not prevented)\n";

// 21. A callback that CLOSE/OPEN resets authority via the #54 routines is detected.
cc56_seed( $root, 'cc_guard_a' );
$policy = hash( 'sha256', 'cc_guard_a' );
$error  = cc56_reject(
	function () use ( $writer, $policy ) {
		Guard::update(
			'cc_guard_a',
			5,
			function () use ( $writer, $policy ) {
				cc56_updates( $writer, 'cc_guard_a', array( 1, 2, 3, 4, 5 ) );
				$writer->query( "CALL commitcap_v01_close('$policy')" );
				$writer->query( "CALL commitcap_v01_open('$policy')" );
				cc56_updates( $writer, 'cc_guard_a', array( 6, 7, 8, 9, 10 ) );
			},
			$writer
		);
	},
	Guard_Error::class,
	'callback CLOSE-OPEN reset'
);
cc56_assert( 'callback_issued_reserved_sql' === $error->reason(), 'CLOSE-OPEN reason' );
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'CLOSE-OPEN durability' );
cc56_clean( $writer );
echo "  direct #54 CLOSE→OPEN in callback: DETECTED CONTRACT VIOLATION, full rollback\n";

// 21b. A callback that directly closes accounting is detected as well.
cc56_seed( $root, 'cc_guard_a' );
$error = cc56_reject(
	function () use ( $writer, $policy ) {
		Guard::update(
			'cc_guard_a',
			5,
			function () use ( $writer, $policy ) {
				cc56_updates( $writer, 'cc_guard_a', array( 1, 2 ) );
				$writer->query( "CALL commitcap_v01_close('$policy')" );
			},
			$writer
		);
	},
	Guard_Error::class,
	'callback direct CLOSE'
);
cc56_assert( 'callback_issued_reserved_sql' === $error->reason(), 'direct CLOSE reason: ' . $error->reason() );
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'direct CLOSE durability' );
cc56_clean( $writer );
echo "  direct #54 CLOSE in callback: DETECTED CONTRACT VIOLATION, full rollback\n";

// 22. A callback that resets the session denial signal is detected.
cc56_seed( $root, 'cc_guard_a' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update(
			'cc_guard_a',
			5,
			function () use ( $writer ) {
				cc56_updates( $writer, 'cc_guard_a', array( 1, 2, 3, 4, 5 ) );
				$writer->query( 'UPDATE cc_guard_a SET touched = touched + 1 WHERE id = 6' ); // denied, ignored
				$writer->query( 'SET @commitcap_v01_denied = 0' );
			},
			$writer
		);
	},
	Guard_Error::class,
	'callback denial-signal reset'
);
cc56_assert( 'callback_issued_reserved_sql' === $error->reason(), 'denial-signal reset reason' );
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'denial-signal reset durability' );
cc56_clean( $writer );
echo "  direct denial-signal reset in callback: DETECTED CONTRACT VIOLATION, full rollback\n";

// 23. A replaced connection mid-guard fails closed.
cc56_seed( $root, 'cc_guard_b' );
$error = cc56_reject(
	function () use ( $writer ) {
		Guard::update(
			'cc_guard_b',
			3,
			function () use ( $writer ) {
				cc56_updates( $writer, 'cc_guard_b', array( 1 ) );
				$writer->dbh = null; // simulate a lost handle; wpdb reconnects on next query.
				$writer->query( 'SELECT 1' );
			},
			$writer
		);
	},
	Guard_Error::class,
	'connection replacement'
);
cc56_assert( 'connection_changed' === $error->reason(), 'connection replacement reason: ' . $error->reason() );
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_b' ), 'connection replacement durability' );
cc56_clean( $writer );
echo "  connection identity change fails closed with nothing durable: PASS\n";

// 24. A new successful guard receives fresh authority after failures.
cc56_seed( $root, 'cc_guard_b' );
$result = Guard::update( 'cc_guard_b', 3, function () use ( $writer ) { cc56_updates( $writer, 'cc_guard_b', array( 1, 2, 3 ) ); return 'first'; }, $writer );
cc56_assert( 'first' === $result, 'first guard result' );
$result = Guard::update( 'cc_guard_b', 3, function () use ( $writer ) { cc56_updates( $writer, 'cc_guard_b', array( 1, 2, 3 ) ); return 'second'; }, $writer );
cc56_assert( 'second' === $result, 'second guard result' );
cc56_assert( array( 2, 2, 2, 0, 0, 0, 0, 0, 0, 0 ) === cc56_rows( $host, 'cc_guard_b' ), 'fresh authority durability' );
echo "  each new guard invocation receives fresh authority: PASS\n";

// 25. Separate connections are independent.
cc56_seed( $root, 'cc_guard_a' );
Guard::update( 'cc_guard_a', 5, function () use ( $writer ) { cc56_updates( $writer, 'cc_guard_a', array( 1, 2, 3, 4, 5 ) ); }, $writer );
Guard::update( 'cc_guard_a', 5, function () use ( $writer_two ) { cc56_updates( $writer_two, 'cc_guard_a', array( 6, 7, 8, 9, 10 ) ); }, $writer_two );
cc56_assert( array_fill( 0, 10, 1 ) === cc56_rows( $host, 'cc_guard_a' ), 'two-connection durability' );
echo "  separate guarded connections are independent: PASS\n";

// 26. The default connection ($GLOBALS['wpdb']) is supported.
cc56_seed( $root, 'cc_guard_a' );
$result = Guard::update( 'cc_guard_a', 5, function () { cc56_updates( $GLOBALS['wpdb'], 'cc_guard_a', array( 1, 2 ) ); return 'default-db'; } );
cc56_assert( 'default-db' === $result, 'default connection result' );
cc56_assert( array( 1, 1, 0, 0, 0, 0, 0, 0, 0, 0 ) === cc56_rows( $host, 'cc_guard_a' ), 'default connection durability' );
echo "  default \$wpdb connection path: PASS\n";

// 27. Direct SQL outside the guard remains outside protection (still denied by the trigger).
cc56_seed( $root, 'cc_guard_b' );
cc56_assert( false === $writer->query( 'UPDATE cc_guard_b SET touched = touched + 1 WHERE id = 1' ), 'unguarded direct write' );
cc56_assert( array_fill( 0, 10, 0 ) === cc56_rows( $host, 'cc_guard_b' ), 'unguarded direct write durability' );
cc56_clean( $writer );
echo "  direct unguarded SQL stays outside the guard (trigger-refused): PASS\n";

echo "#56 $host: ALL EXPECTED GUARD ASSERTIONS PASS (cooperative boundary only)\n";
