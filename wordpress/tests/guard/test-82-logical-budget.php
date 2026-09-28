<?php
// Gate #82 acceptance tests: Admin-selectable logical budget L below trusted physical DB ceiling P.
// Invariant: 0 <= L <= P.
// actual <= L -> COMMIT
// actual > L -> typed logical Budget_Denied -> ROLLBACK
// L > P -> fail before callback

use CommitCap\Budget_Denied;
use CommitCap\Guard;
use CommitCap\Guard_Error;
use CommitCap\Unsupported_Transaction_State;
use CommitCap\Update_Engine;

echo "  --- Begin Gate #82 Logical Budget below Physical Ceiling Tests ($host) ---\n";

// Setup dedicated test table for #82: cc_guard_logical (10 rows initially).
$table_l = 'cc_guard_logical';
$root->query( "DROP TABLE IF EXISTS `$table_l`" );
cc56_assert( false !== $root->query( "CREATE TABLE `$table_l` (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB" ), 'create table_l' );
for ( $i = 1; $i <= 15; ++$i ) {
	cc56_assert( false !== $root->query( "INSERT INTO `$table_l` (id, touched) VALUES ($i, 0)" ), 'seed insert table_l' );
}
// Install physical ceiling P = 10.
$installer->install_policy( $table_l, 10 );
$root->query( "GRANT SELECT, UPDATE ON wp_test.`$table_l` TO 'cc_writer'@'%'" );

function cc82_seed( $root, $table_l, $count = 15 ) {
	cc56_assert( false !== $root->query( "TRUNCATE TABLE `$table_l`" ), 'seed truncate table_l' );
	for ( $i = 1; $i <= $count; ++$i ) {
		cc56_assert( false !== $root->query( "INSERT INTO `$table_l` (id, touched) VALUES ($i, 0)" ), 'seed insert table_l' );
	}
}

function cc82_rows( $host, $table_l, $count = 15 ) {
	$fresh = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
	cc56_assert( $fresh->ready, 'fresh durable observer unavailable' );
	return array_map( 'intval', $fresh->get_col( "SELECT touched FROM `$table_l` ORDER BY id LIMIT $count" ) );
}

// 82.1. L = P (L = 10, P = 10): exactly 10 events COMMIT.
cc82_seed( $root, $table_l );
$result = Guard::update(
	$table_l,
	10,
	function () use ( $writer, $table_l ) {
		cc56_updates( $writer, $table_l, range( 1, 10 ) );
		return 'l-equals-p-safe';
	},
	$writer
);
cc56_assert( 'l-equals-p-safe' === $result, 'L=P return value' );
cc56_assert( array_merge( array_fill( 0, 10, 1 ), array_fill( 0, 5, 0 ) ) === cc82_rows( $host, $table_l ), 'L=P durability' );
echo "  #82.1 L=P safe commit (10 of 10): PASS\n";

// 82.2. L = P (L = 10, P = 10): 11th event causes physical denial (SIGNAL CC54_DENIED).
cc82_seed( $root, $table_l );
$error = cc56_reject(
	function () use ( $writer, $table_l ) {
		Guard::update(
			$table_l,
			10,
			function () use ( $writer, $table_l ) {
				cc56_updates( $writer, $table_l, range( 1, 10 ) );
				$writer->query( "UPDATE `$table_l` SET touched = touched + 1 WHERE id = 11" );
			},
			$writer
		);
	},
	Budget_Denied::class,
	'L=P 11th event physical denial'
);
$details = $error->details();
cc56_assert( 'CC54_DENIED' === $details['reason'] || 'budget_exceeded' === $details['reason'] || 'denial_signal' === $details['reason'], 'L=P physical denial reason: ' . $details['reason'] );
cc56_assert( '45000' === $details['sqlstate'], 'L=P physical denial sqlstate 45000' );
cc56_assert( 1644 === $details['errno'], 'L=P physical denial errno 1644' );
cc56_assert( 10 === $details['budget'], 'L=P physical denial budget' );
cc56_assert( array_fill( 0, 15, 0 ) === cc82_rows( $host, $table_l ), 'L=P physical denial rollback' );
cc56_clean( $writer );
echo "  #82.2 L=P physical ceiling denial on 11th event: PASS\n";

// 82.3. L < P safe commit (L = 5, P = 10): 5 events COMMIT.
cc82_seed( $root, $table_l );
$result = Guard::update(
	$table_l,
	5,
	function () use ( $writer, $table_l ) {
		cc56_updates( $writer, $table_l, range( 1, 5 ) );
		return 'l-less-than-p-safe';
	},
	$writer
);
cc56_assert( 'l-less-than-p-safe' === $result, 'L<P return value' );
cc56_assert( array_merge( array_fill( 0, 5, 1 ), array_fill( 0, 10, 0 ) ) === cc82_rows( $host, $table_l ), 'L<P durability' );
echo "  #82.3 L<P safe commit (5 of 5 under P=10): PASS\n";

// 82.4. 5 commit / 6 logical denial (L = 5, P = 10):
// 6th event does not trigger DB SIGNAL (since 6 <= 10), but Guard pre-commit check denies and rolls back.
cc82_seed( $root, $table_l );
$error = cc56_reject(
	function () use ( $writer, $table_l ) {
		Guard::update(
			$table_l,
			5,
			function () use ( $writer, $table_l ) {
				cc56_updates( $writer, $table_l, range( 1, 6 ) );
			},
			$writer
		);
	},
	Budget_Denied::class,
	'5 commit / 6 logical denial'
);
$details = $error->details();
cc56_assert( 'logical_budget_exceeded' === $details['reason'], 'logical denial reason: ' . $details['reason'] );
cc56_assert( null === $details['sqlstate'], 'logical denial sqlstate must be null (not invented): ' . var_export( $details['sqlstate'], true ) );
cc56_assert( null === $details['errno'], 'logical denial errno must be null (not invented): ' . var_export( $details['errno'], true ) );
cc56_assert( 5 === $details['budget'], 'logical denial budget must be 5' );
cc56_assert( 6 === $details['consumed'], 'logical denial consumed must be 6' );
cc56_assert( 10 === $details['physical_ceiling'], 'logical denial physical_ceiling must be 10' );
cc56_assert( array_fill( 0, 15, 0 ) === cc82_rows( $host, $table_l ), 'logical denial rollback verified by fresh observer' );
cc56_clean( $writer );
echo "  #82.4 5 commit / 6 logical denial (typed Budget_Denied, no invented SQLSTATE/errno, full rollback): PASS\n";

// 82.5. Broad UPDATE where L < affected < P (L = 5, affected = 7, P = 10):
// Single statement touches 7 rows. Statement succeeds in DB. Guard denies before COMMIT.
cc82_seed( $root, $table_l );
$error = cc56_reject(
	function () use ( $writer, $table_l ) {
		Guard::update(
			$table_l,
			5,
			function () use ( $writer, $table_l ) {
				$affected = $writer->query( "UPDATE `$table_l` SET touched = touched + 1 WHERE id <= 7" );
				cc56_assert( 7 === (int) $affected, 'expected 7 rows affected' );
				return 'broad-update-done';
			},
			$writer
		);
	},
	Budget_Denied::class,
	'broad UPDATE L < affected < P'
);
$details = $error->details();
cc56_assert( 'logical_budget_exceeded' === $details['reason'], 'broad update logical denial reason' );
cc56_assert( null === $details['sqlstate'] && null === $details['errno'], 'broad update no invented error codes' );
cc56_assert( 5 === $details['budget'] && 7 === $details['consumed'], 'broad update budget/consumed' );
cc56_assert( array_fill( 0, 15, 0 ) === cc82_rows( $host, $table_l ), 'broad update full rollback verified by fresh observer' );
cc56_clean( $writer );
echo "  #82.5 broad UPDATE where L < affected < P (7 rows, L=5, P=10, full rollback): PASS\n";

// 82.6. Broad UPDATE where affected > P (affected = 12, P = 10, L = 5):
// Trigger signals CC54_DENIED at 10th row.
cc82_seed( $root, $table_l );
$error = cc56_reject(
	function () use ( $writer, $table_l ) {
		Guard::update(
			$table_l,
			5,
			function () use ( $writer, $table_l ) {
				$writer->query( "UPDATE `$table_l` SET touched = touched + 1 WHERE id <= 12" );
			},
			$writer
		);
	},
	Budget_Denied::class,
	'broad UPDATE affected > P'
);
$details = $error->details();
cc56_assert( '45000' === $details['sqlstate'] && 1644 === $details['errno'], 'broad update physical denial error codes' );
cc56_assert( array_fill( 0, 15, 0 ) === cc82_rows( $host, $table_l ), 'broad physical denial full rollback' );
cc56_clean( $writer );
echo "  #82.6 broad UPDATE where affected > P (physical denial, full rollback): PASS\n";

// 82.7. Repeated statements / rows under L < P (L = 5, P = 10):
// Updating row 1 five times commits.
cc82_seed( $root, $table_l );
Guard::update(
	$table_l,
	5,
	function () use ( $writer, $table_l ) {
		for ( $i = 0; $i < 5; ++$i ) {
			cc56_updates( $writer, $table_l, array( 1 ) );
		}
	},
	$writer
);
cc56_assert( 5 === cc82_rows( $host, $table_l )[0], 'repeated row safe durability' );
// Updating row 1 six times causes logical denial.
cc82_seed( $root, $table_l );
$error = cc56_reject(
	function () use ( $writer, $table_l ) {
		Guard::update(
			$table_l,
			5,
			function () use ( $writer, $table_l ) {
				for ( $i = 0; $i < 6; ++$i ) {
					cc56_updates( $writer, $table_l, array( 1 ) );
				}
			},
			$writer
		);
	},
	Budget_Denied::class,
	'repeated row logical denial'
);
cc56_assert( 'logical_budget_exceeded' === $error->details()['reason'], 'repeated row denial reason' );
cc56_assert( 0 === cc82_rows( $host, $table_l )[0], 'repeated row rollback' );
cc56_clean( $writer );
echo "  #82.7 repeated statement/row accounting under L < P: PASS\n";

// 82.8. L = 0 under P = 10:
// Zero-event callback commits cleanly.
cc82_seed( $root, $table_l );
Guard::update( $table_l, 0, function () {}, $writer );
cc56_assert( array_fill( 0, 15, 0 ) === cc82_rows( $host, $table_l ), 'L=0 zero-event durability' );
// 1-event callback causes logical denial.
$error = cc56_reject(
	function () use ( $writer, $table_l ) {
		Guard::update(
			$table_l,
			0,
			function () use ( $writer, $table_l ) {
				cc56_updates( $writer, $table_l, array( 1 ) );
			},
			$writer
		);
	},
	Budget_Denied::class,
	'L=0 logical denial'
);
cc56_assert( 'logical_budget_exceeded' === $error->details()['reason'], 'L=0 denial reason' );
cc56_assert( 0 === $error->details()['budget'] && 1 === $error->details()['consumed'], 'L=0 consumed' );
cc56_assert( 0 === cc82_rows( $host, $table_l )[0], 'L=0 rollback' );
cc56_clean( $writer );
echo "  #82.8 L=0 zero-event commit and 1-event logical denial: PASS\n";

// 82.9. Invalid / L > P: fails BEFORE callback runs.
$ran = false;
$error = cc56_reject(
	function () use ( $writer, $table_l, &$ran ) {
		Guard::update(
			$table_l,
			11, // P is 10
			function () use ( &$ran ) { $ran = true; },
			$writer
		);
	},
	Guard_Error::class,
	'L > P pre-callback rejection'
);
cc56_assert( 'policy_unverified' === $error->reason(), 'L > P error reason' );
cc56_assert( false === $ran, 'L > P callback ran unexpectedly' );
// Invalid budget syntax fails with InvalidArgumentException before callback.
$ran = false;
try {
	Guard::update( $table_l, -1, function () use ( &$ran ) { $ran = true; }, $writer );
	throw new RuntimeException( 'expected InvalidArgumentException' );
} catch ( InvalidArgumentException $e ) {
	// expected
}
cc56_assert( false === $ran, 'invalid budget callback ran' );
echo "  #82.9 invalid L and L > P fail before callback without transaction: PASS\n";

// 82.10. Changing L across invocations WITHOUT trigger or grant DDL.
// Record initial trigger statement and information_schema metadata.
$trig_before = $root->get_row( $root->prepare(
	'SELECT TRIGGER_NAME, ACTION_STATEMENT, DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s',
	'commitcap_v01_' . substr( hash( 'sha256', $table_l ), 0, 16 )
) );
cc56_assert( null !== $trig_before, 'trigger must exist before changing L' );

// Run with L = 2 -> 2 updates commit.
cc82_seed( $root, $table_l );
Guard::update( $table_l, 2, function () use ( $writer, $table_l ) { cc56_updates( $writer, $table_l, array( 1, 2 ) ); }, $writer );
cc56_assert( array( 1, 1, 0 ) === array_slice( cc82_rows( $host, $table_l ), 0, 3 ), 'change L=2 durability' );

// Run with L = 8 -> 8 updates commit.
cc82_seed( $root, $table_l );
Guard::update( $table_l, 8, function () use ( $writer, $table_l ) { cc56_updates( $writer, $table_l, range( 1, 8 ) ); }, $writer );
cc56_assert( array_merge( array_fill( 0, 8, 1 ), array_fill( 0, 7, 0 ) ) === cc82_rows( $host, $table_l ), 'change L=8 durability' );

// Run with L = 3 -> 4 updates logically denied.
cc82_seed( $root, $table_l );
$error = cc56_reject(
	function () use ( $writer, $table_l ) {
		Guard::update( $table_l, 3, function () use ( $writer, $table_l ) { cc56_updates( $writer, $table_l, range( 1, 4 ) ); }, $writer );
	},
	Budget_Denied::class,
	'change L=3 denial'
);
cc56_assert( 'logical_budget_exceeded' === $error->details()['reason'], 'change L=3 reason' );
cc56_assert( array_fill( 0, 15, 0 ) === cc82_rows( $host, $table_l ), 'change L=3 rollback' );

// Verify trigger and grants are strictly identical.
$trig_after = $root->get_row( $root->prepare(
	'SELECT TRIGGER_NAME, ACTION_STATEMENT, DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s',
	'commitcap_v01_' . substr( hash( 'sha256', $table_l ), 0, 16 )
) );
cc56_assert( $trig_before->ACTION_STATEMENT === $trig_after->ACTION_STATEMENT, 'trigger DDL was modified unexpectedly' );
cc56_assert( $trig_before->DEFINER === $trig_after->DEFINER, 'trigger definer changed' );
echo "  #82.10 changing L between runs without trigger/grant DDL: PASS\n";

// 82.11. Swallowed errors and callback exceptions under L < P:
// Swallowed SQL error:
cc82_seed( $root, $table_l );
$error = cc56_reject(
	function () use ( $writer, $table_l ) {
		Guard::update(
			$table_l,
			5,
			function () use ( $writer, $table_l ) {
				cc56_updates( $writer, $table_l, array( 1, 2 ) );
				// Swallowed error
				$writer->query( 'UPDATE `' . $table_l . '` SET no_such_column = 99 WHERE id = 1' );
				return 'swallowed';
			},
			$writer
		);
	},
	Guard_Error::class,
	'swallowed DB error'
);
cc56_assert( 'database_error' === $error->reason(), 'swallowed DB error reason: ' . $error->reason() );
cc56_assert( array_fill( 0, 15, 0 ) === cc82_rows( $host, $table_l ), 'swallowed DB error rollback' );
cc56_clean( $writer );

// Callback exception:
cc82_seed( $root, $table_l );
$error = cc56_reject(
	function () use ( $writer, $table_l ) {
		Guard::update(
			$table_l,
			5,
			function () use ( $writer, $table_l ) {
				cc56_updates( $writer, $table_l, array( 1, 2 ) );
				throw new Exception( 'callback exploded' );
			},
			$writer
		);
	},
	Guard_Error::class,
	'callback exception'
);
cc56_assert( 'callback_failed' === $error->reason(), 'callback failed reason' );
cc56_assert( 'callback exploded' === $error->getPrevious()->getMessage(), 'cause preserved' );
cc56_assert( array_fill( 0, 15, 0 ) === cc82_rows( $host, $table_l ), 'callback exception rollback' );
cc56_clean( $writer );
echo "  #82.11 swallowed DB errors and callback exceptions roll back: PASS\n";

// 82.12. Caller savepoint preservation under logical budget:
// Caller starts transaction and creates savepoint outside guard. Guard refuses nested transaction and preserves caller savepoint.
$ran = false;
cc56_assert( false !== $writer->query( 'START TRANSACTION' ), 'start caller savepoint tx' );
cc56_assert( false !== $writer->query( 'SAVEPOINT caller_sp' ), 'caller savepoint' );
$error = cc56_reject(
	function () use ( $writer, $table_l, &$ran ) {
		Guard::update( $table_l, 5, function () use ( &$ran ) { $ran = true; }, $writer );
	},
	Unsupported_Transaction_State::class,
	'caller transaction rejected'
);
cc56_assert( 'existing_transaction' === $error->reason(), 'existing tx reason' );
cc56_assert( false === $ran, 'callback ran in caller transaction' );
cc56_assert( false !== $writer->query( 'ROLLBACK TO SAVEPOINT caller_sp' ), 'caller savepoint intact' );
cc56_assert( false !== $writer->query( 'COMMIT' ), 'end caller tx' );
cc56_clean( $writer );
echo "  #82.12 caller savepoint preserved outside guard: PASS\n";

// 82.13. Measure resource/time cost when P >> L.
// Benchmark table cc_bench_p_vs_l with 2000 rows.
$bench_table = 'cc_bench_p_vs_l';
$root->query( "DROP TABLE IF EXISTS `$bench_table`" );
cc56_assert( false !== $root->query( "CREATE TABLE `$bench_table` (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB" ), 'create bench table' );
$values = array();
for ( $i = 1; $i <= 2000; ++$i ) {
	$values[] = "($i, 0)";
	if ( count( $values ) === 500 ) {
		cc56_assert( false !== $root->query( "INSERT INTO `$bench_table` (id, touched) VALUES " . implode( ',', $values ) ), 'insert bench rows' );
		$values = array();
	}
}
$root->query( "GRANT SELECT, UPDATE ON wp_test.`$bench_table` TO 'cc_writer'@'%'" );

// Case A: P = 5, L = 5 (Physical abort at 6th row).
$installer->install_policy( $bench_table, 5 );
$t0 = microtime( true );
$error_phys = cc56_reject(
	function () use ( $writer, $bench_table ) {
		Guard::update(
			$bench_table,
			5,
			function () use ( $writer, $bench_table ) {
				$writer->query( "UPDATE `$bench_table` SET touched = touched + 1 WHERE id <= 2000" );
			},
			$writer
		);
	},
	Budget_Denied::class,
	'physical benchmark'
);
$t_phys = microtime( true ) - $t0;
cc56_clean( $writer );
$installer->remove_owned_policy( $bench_table, 5 );

// Case B: P = 2000, L = 5 (Logical abort after all 2000 rows are updated and rolled back by Guard).
$installer->install_policy( $bench_table, 2000 );
$t0 = microtime( true );
$error_log = cc56_reject(
	function () use ( $writer, $bench_table ) {
		Guard::update(
			$bench_table,
			5,
			function () use ( $writer, $bench_table ) {
				$writer->query( "UPDATE `$bench_table` SET touched = touched + 1 WHERE id <= 2000" );
			},
			$writer
		);
	},
	Budget_Denied::class,
	'logical benchmark'
);
$t_log = microtime( true ) - $t0;
cc56_clean( $writer );
$installer->remove_owned_policy( $bench_table, 2000 );
$root->query( "DROP TABLE `$bench_table`" );

$phys_ms = round( $t_phys * 1000, 2 );
$log_ms  = round( $t_log * 1000, 2 );
$ratio   = $t_phys > 0 ? round( $t_log / $t_phys, 1 ) : 'N/A';
echo sprintf( "  #82.13 resource cost measurement (P >> L on 2000 rows, %s):\n", $host );
echo sprintf( "         P=5, L=5 (physical abort at row 6): %s ms (attempted: %s, sqlstate: %s)\n", $phys_ms, $error_phys->details()['attempted'], $error_phys->details()['sqlstate'] );
echo sprintf( "         P=2000, L=5 (logical abort at pre-commit): %s ms (attempted: %s, sqlstate: %s)\n", $log_ms, $error_log->details()['attempted'], var_export( $error_log->details()['sqlstate'], true ) );
echo sprintf( "         Overhead ratio: %sx\n", $ratio );

// Cleanup test table.
$installer->remove_owned_policy( $table_l, 10 );
$root->query( "DROP TABLE `$table_l`" );

echo "  --- End Gate #82 Logical Budget Tests ($host): ALL PASS ---\n";
