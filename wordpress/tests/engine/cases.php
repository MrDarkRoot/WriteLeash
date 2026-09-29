<?php
// Real WordPress context, two actual wpdb connections with distinct grants.
require_once WP_PLUGIN_DIR . '/writeleash/writeleash.php';
use WriteLeash\Update_Engine as Engine;

function cc54_assert( $value, $label ) {
	if ( ! $value ) {
		throw new RuntimeException( $label );
	}
}
function cc54_reject( $callback, $label ) {
	try {
		$callback();
	} catch ( InvalidArgumentException $error ) {
		return;
	} catch ( RuntimeException $error ) {
		return;
	}
	throw new RuntimeException( 'Expected refusal: ' . $label );
}
function cc54_query( $db, $sql ) {
	$result = $db->query( $sql );
	cc54_assert( false !== $result, $sql . ': ' . $db->last_error );
	return $result;
}
function cc54_bad_routine( $db, $engine, $name, $declaration, $body, $label ) {
	// Only fixed fixture names/declarations are passed from the test below.
	cc54_query( $db, "CREATE PROCEDURE `$name` $declaration SQL SECURITY DEFINER $body" );
	try {
		$engine->install_infrastructure();
		throw new RuntimeException( 'Signature verifier accepted ' . $label );
	} catch ( RuntimeException $error ) {
		cc54_assert( false !== strpos( $error->getMessage(), 'routine signature: ' . $name ),
			'Wrong rejection reason for ' . $label . ': ' . $error->getMessage() );
	}
	cc54_assert( 1 === (int) $db->get_var( $db->prepare(
		'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = %s', $name
	) ), 'malformed routine overwritten or deleted: ' . $label );
	cc54_query( $db, "DROP PROCEDURE `$name`" ); // Only this exact test-owned fake.
}
function cc54_seed( $db, $name ) {
	cc54_query( $db, "TRUNCATE TABLE `$name`" );
	for ( $i = 1; $i <= 10; ++$i ) {
		cc54_query( $db, "INSERT INTO `$name` (id, touched) VALUES ($i, 0)" );
	}
}
function cc54_rows( $db, $name ) {
	// Observe durable effects from a NEW trusted connection, not the writer or
	// a long-lived installer connection with its own transactional snapshot.
	$fresh = new wpdb( 'root', 'disposable_root_password', $db->dbname, $db->dbhost );
	cc54_assert( $fresh->ready, 'fresh durable observer unavailable' );
	return array_map( 'intval', $fresh->get_col( "SELECT touched FROM `$name` ORDER BY id" ) );
}
function cc54_row( $db, $name, $id ) {
	$fresh = new wpdb( 'root', 'disposable_root_password', $db->dbname, $db->dbhost );
	cc54_assert( $fresh->ready, 'fresh durable observer unavailable' );
	return (int) $fresh->get_var( "SELECT touched FROM `$name` WHERE id = $id" );
}
function cc54_update( $db, $name, $id ) {
	return $db->query( "UPDATE `$name` SET touched = touched + 1 WHERE id = $id" );
}
function cc54_open( $db, $engine, $table, $budget ) {
	cc54_query( $db, 'START TRANSACTION' );
	$engine->begin_guard_state();
	$engine->begin_accounting( $table, $budget );
}
function cc54_commit( $db, $engine, $table ) {
	$engine->end_accounting( $table );
	cc54_query( $db, 'COMMIT' );
}

$host = getenv( 'CC_ENGINE_HOST' );
cc54_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), 'Unknown server fixture' );
$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
$installer = new Engine( $root );
$version = $root->get_var( 'SELECT VERSION()' );
cc54_assert( 0 === strpos( $version, 'mysql' === $host ? '8.0.44' : '10.11.15' ), 'Unpinned server: ' . $version );
cc54_assert( ( 'mysql' === $host ? 'MySQL' : 'MariaDB' ) === Engine::target_family( $version ), 'wrong server family' );
foreach ( array( '5.7.42', '10.10.4-MariaDB', '8.0.44-TiDB', '', 'unknown' ) as $unsupported ) {
	cc54_reject( static function () use ( $unsupported ) { Engine::target_family( $unsupported ); }, 'unsupported family/version' );
}
echo "#54 $host $version\n";

// Canonical grammar: refuse, never rewrite a malformed input into valid SQL.
foreach ( array( '', 'wp posts', '`wp_posts`', 'wp_posts;DROP', 'a.b', 'a--b', "a\nb", 'a/*b*/', 'a,b', str_repeat( 'a', 65 ) ) as $invalid ) {
	cc54_reject( static function () use ( $invalid ) { Engine::table( $invalid ); }, 'table grammar' );
}
foreach ( array( '', '01', '+1', '-1', '1.0', '1e2', ' 1', '1 ', '2147483648', '99999999999999999999999', 1.0, null ) as $invalid ) {
	cc54_reject( static function () use ( $invalid ) { Engine::budget( $invalid ); }, 'budget grammar' );
}
cc54_assert( 0 === Engine::budget( '0' ) && 1 === Engine::budget( 1 ) &&
	2147483647 === Engine::budget( '2147483647' ), 'canonical budgets' );

// Unknown helper collision must not be overwritten or dropped.
cc54_query( $root, 'CREATE TABLE commitcap_v01_state (id INT) ENGINE=InnoDB' );
cc54_reject( static function () use ( $installer ) { $installer->install_infrastructure(); }, 'unknown helper' );
cc54_assert( (int) $root->get_var( 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "commitcap_v01_state"' ) === 1, 'helper collision overwritten' );
cc54_query( $root, 'DROP TABLE commitcap_v01_state' ); // This exact fixture-created collision only.

foreach ( array( 'cc_alpha', 'cc_beta', 'cc_zero', 'cc_one', 'cc_max', 'cc_conflict', 'cc_malformed' ) as $table ) {
	cc54_query( $root, "CREATE TABLE `$table` (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB" );
	cc54_seed( $root, $table );
	cc54_query( $root, "GRANT SELECT, UPDATE ON wp_test.`$table` TO 'cc_writer'@'%'" );
}
cc54_query( $root, 'CREATE TABLE cc_myisam (id INT PRIMARY KEY) ENGINE=MyISAM' ); // Negative fixture only.
cc54_query( $root, 'CREATE VIEW cc_view AS SELECT * FROM cc_alpha' );
cc54_query( $root, 'CREATE TABLE cc_partitioned (id INT NOT NULL, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB PARTITION BY HASH(id) PARTITIONS 2' ); // Unsupported fixture.
cc54_query( $root, 'CREATE TABLE cc_child (id INT PRIMARY KEY, alpha_id INT, CONSTRAINT cc_fixture_fk FOREIGN KEY (alpha_id) REFERENCES cc_alpha(id) ON UPDATE CASCADE) ENGINE=InnoDB' ); // Unsupported FK fixture.
cc54_reject( static function () use ( $installer ) { $installer->inspect_table( 'cc_absent' ); }, 'missing table' );
cc54_reject( static function () use ( $installer ) { $installer->inspect_table( 'cc_myisam' ); }, 'non-InnoDB' );
cc54_reject( static function () use ( $installer ) { $installer->inspect_table( 'cc_view' ); }, 'view' );
cc54_reject( static function () use ( $installer ) { $installer->inspect_table( 'cc_partitioned' ); }, 'partitioned table' );
cc54_reject( static function () use ( $installer ) { $installer->inspect_table( 'cc_alpha' ); }, 'parent FK' );
cc54_reject( static function () use ( $installer ) { $installer->inspect_table( 'cc_child' ); }, 'child FK' );
cc54_query( $root, 'DROP TABLE cc_child' ); // Exact test-owned unsupported fixture.
cc54_query( $root, 'CREATE PROCEDURE commitcap_v01_open(IN p_policy CHAR(64)) SELECT 1' );
cc54_reject( static function () use ( $installer ) { $installer->install_infrastructure(); }, 'unknown routine' );
cc54_query( $root, 'DROP PROCEDURE commitcap_v01_open' ); // Only this test-created conflicting routine.
$open_body = 'BEGIN INSERT INTO commitcap_v01_state (connection_id, policy_id, consumed) VALUES (CONNECTION_ID(), p_policy, 0); END';
$count_body = 'BEGIN SELECT consumed INTO p_count FROM commitcap_v01_state WHERE connection_id = CONNECTION_ID() AND policy_id = p_policy; END';
$ascii_policy = 'IN p_policy CHAR(64) CHARACTER SET ascii COLLATE ascii_bin';
cc54_bad_routine( $root, $installer, 'commitcap_v01_open',
	'(IN p_policy CHAR(63) CHARACTER SET ascii COLLATE ascii_bin)', $open_body, 'wrong lifecycle parameter length' );
cc54_bad_routine( $root, $installer, 'commitcap_v01_open',
	'(IN p_Policy CHAR(64) CHARACTER SET ascii COLLATE ascii_bin)', $open_body, 'wrong lifecycle parameter name' );
cc54_bad_routine( $root, $installer, 'commitcap_v01_open',
	'(' . $ascii_policy . ', IN p_extra INT)', $open_body, 'extra lifecycle parameter' );
cc54_bad_routine( $root, $installer, 'commitcap_v01_count',
	'(' . $ascii_policy . ', IN p_count BIGINT UNSIGNED)', $count_body, 'wrong IN instead of OUT' );
cc54_bad_routine( $root, $installer, 'commitcap_v01_count',
	'(' . $ascii_policy . ', OUT p_count BIGINT)', $count_body, 'wrong signedness' );
cc54_bad_routine( $root, $installer, 'commitcap_v01_count',
	'(OUT p_count BIGINT UNSIGNED, ' . $ascii_policy . ')', $count_body, 'wrong parameter order' );
$installer->install_infrastructure();
$installer->install_infrastructure(); // Idempotent verified infrastructure, no silent replacement.
foreach ( array( 'open', 'close', 'count', 'policy', 'attest' ) as $routine ) {
	cc54_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test.commitcap_v01_$routine TO 'cc_writer'@'%'" );
}
// Reviewed unmediated helper-state read for the runtime evidence probes (#83).
cc54_query( $root, "GRANT SELECT ON wp_test.commitcap_v01_state TO 'cc_writer'@'%'" );

cc54_query( $root, 'CREATE TRIGGER cc_unrelated BEFORE UPDATE ON cc_conflict FOR EACH ROW SET @cc_fixture=1' );
cc54_reject( static function () use ( $installer ) { $installer->install_policy( 'cc_conflict', 5, 'cc_writer' ); }, 'existing user trigger' );
cc54_reject( static function () use ( $installer ) { $installer->remove_owned_policy( 'cc_conflict', 5, 'cc_writer' ); }, 'unrelated trigger removal' );
cc54_assert( (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_NAME='cc_unrelated'" ) === 1, 'unrelated trigger lost' );

foreach ( array( 'cc_alpha' => 5, 'cc_beta' => 1, 'cc_zero' => 0, 'cc_one' => 1, 'cc_max' => 2147483647, 'cc_malformed' => 5 ) as $table => $budget ) {
	$installer->install_policy( $table, $budget, 'cc_writer' );
	$installer->verify_policy( $table, $budget, 'cc_writer' );
	cc54_reject( static function () use ( $installer, $table, $budget ) { $installer->install_policy( $table, $budget, 'cc_writer' ); }, 'duplicate policy' );
}
cc54_reject( static function () use ( $installer ) { $installer->verify_policy( 'cc_alpha', 4, 'cc_writer' ); }, 'wrong budget' );
echo "  installation, verification, identity grammar and object conflicts: PASS\n";

// #96 DB-boundary regression: the low-level attestation identity is #97-deferred
// and must still be the exact pre-#96 canonical graph. No compatibility path is
// involved: nothing renamed the helper TABLE_COMMENT, routine family or trigger
// prefix, so the unchanged WriteLeash runtime verifies the unchanged objects.
cc54_assert( 'commitcap_v01_state' === Engine::STATE, 'canonical helper name changed before #97' );
cc54_assert( 'CommitCap V0.1 cooperative UPDATE state' === Engine::COMMENT, 'canonical helper TABLE_COMMENT changed before #97' );
$canonical_routines = Engine::routine_names();
sort( $canonical_routines );
cc54_assert( array(
	'commitcap_v01_attest', 'commitcap_v01_close', 'commitcap_v01_count',
	'commitcap_v01_open', 'commitcap_v01_policy',
) === $canonical_routines, 'canonical routine family changed before #97' );
$installed_comment = (string) $root->get_var( $root->prepare(
	'SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', Engine::STATE
) );
cc54_assert( 'CommitCap V0.1 cooperative UPDATE state' === $installed_comment,
	'installed helper TABLE_COMMENT is not the pre-#96 canonical value: ' . $installed_comment );
$installer->verify_infrastructure_objects(); // Helper shape + cross-attested routine bodies.
$installed_routines = array_values( (array) $root->get_col( $root->prepare(
	'SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME LIKE %s ORDER BY ROUTINE_NAME', 'commitcap_v01\_%'
) ) );
cc54_assert( $canonical_routines === $installed_routines, 'canonical routines are missing or renamed: ' . json_encode( $installed_routines ) );
$premature = (int) $root->get_var(
	"SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'writeleash_v01\\_%')" .
	" + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME LIKE 'writeleash_v01\\_%')" .
	" + (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'writeleash_v01\\_%')"
);
cc54_assert( 0 === $premature, 'premature writeleash_v01_* database objects introduced: ' . $premature );
echo "  canonical pre-#96 DB identity preserved; helper shape, attestation and zero premature writeleash_v01_* objects: PASS\n";

$writer = new wpdb( 'cc_writer', 'disposable_writer_password', 'wp_test', $host );
$other = new wpdb( 'cc_writer', 'disposable_writer_password', 'wp_test', $host );
$writer->suppress_errors( true );
$other->suppress_errors( true );
cc54_assert( $writer->ready && $other->ready, 'writer connection unavailable' );
$engine = new Engine( $writer );
$second = new Engine( $other );
cc54_reject( static function () use ( $engine ) { $engine->verify_runtime_policy( 'cc_alpha', 4 ); }, 'runtime wrong budget' );
$engine->verify_runtime_policy( 'cc_alpha', 5 );
cc54_reject( static function () use ( $engine ) { $engine->begin_accounting( 'cc_conflict', 5 ); }, 'runtime conflicting trigger' );

// Reviewed #83 change: the runtime holds a read-only SELECT grant on the helper
// state table so the pre-commit safety decision is an unmediated read, never a
// routine body. The writer must still be unable to modify it.
cc54_assert( false !== $writer->query( 'SELECT * FROM commitcap_v01_state' ), 'reviewed helper SELECT must work' );
foreach ( array(
	'INSERT INTO commitcap_v01_state (connection_id, policy_id, consumed) VALUES (1, "bad", 0)',
	'UPDATE commitcap_v01_state SET consumed=0',
	'DELETE FROM commitcap_v01_state',
	'DROP TABLE commitcap_v01_state',
	'DROP TRIGGER commitcap_v01_' . substr( hash( 'sha256', 'cc_alpha' ), 0, 16 ),
	'ALTER TABLE cc_alpha DISABLE KEYS',
) as $attack ) {
	cc54_assert( false === $writer->query( $attack ), 'writer changed enforcement: ' . $attack );
}
cc54_assert( cc54_rows( $root, 'cc_alpha' ) === array_fill( 0, 10, 0 ), 'privilege test touched user rows' );
// This signal is writable by the SQL caller; it is cooperative evidence only.
cc54_query( $writer, 'SET @commitcap_v01_denied = 1' );
cc54_assert( $engine->denial_seen(), 'direct session signal SET not observed' );
cc54_query( $writer, 'SET @commitcap_v01_denied = 0' );
cc54_assert( ! $engine->denial_seen(), 'direct session signal RESET not observed' );
echo "  direct SQL can SET/RESET session denial signal: CONFIRMED ($host)\n";
// Direct UPDATE with no open guard fails; no stale row after successful COMMIT.
cc54_assert( false === cc54_update( $writer, 'cc_alpha', 1 ), 'unguarded write allowed' );
cc54_reject( static function () use ( $engine ) { $engine->end_accounting( 'cc_alpha' ); }, 'missing state must not close' );
echo "  restricted writer and unguarded-update refusal: PASS\n";

// Budget zero, one, max, repeated row, zero-row and no-op row events.
cc54_open( $writer, $engine, 'cc_zero', 0 );
cc54_assert( false === cc54_update( $writer, 'cc_zero', 1 ), 'budget zero' );
cc54_query( $writer, 'ROLLBACK' );
cc54_assert( 0 === cc54_row( $root, 'cc_zero', 1 ), 'budget zero durable' );
cc54_open( $writer, $engine, 'cc_one', 1 );
cc54_assert( 1 === cc54_update( $writer, 'cc_one', 1 ), 'budget one first' );
cc54_reject( static function () use ( $engine ) { $engine->begin_accounting( 'cc_one', 1 ); }, 'open must not reset authority' );
cc54_assert( false === cc54_update( $writer, 'cc_one', 2 ), 'budget one second' );
cc54_query( $writer, 'ROLLBACK' );
cc54_assert( cc54_rows( $root, 'cc_one' ) === array_fill( 0, 10, 0 ), 'budget one rolled back' );
cc54_open( $writer, $engine, 'cc_max', 2147483647 );
cc54_update( $writer, 'cc_max', 1 );
cc54_assert( 1 === $engine->consumed( 'cc_max' ), 'max budget counter' );
cc54_commit( $writer, $engine, 'cc_max' );
cc54_assert( 1 === cc54_row( $root, 'cc_max', 1 ), 'max budget durable' );

cc54_open( $writer, $engine, 'cc_alpha', 5 );
cc54_query( $writer, 'UPDATE cc_alpha SET touched = touched WHERE id = 1' );
cc54_query( $writer, 'UPDATE cc_alpha SET touched = touched + 1 WHERE id = -999' );
for ( $i = 0; $i < 4; ++$i ) {
	cc54_update( $writer, 'cc_alpha', 1 );
}
cc54_assert( 5 === $engine->consumed( 'cc_alpha' ), 'no-op/repeated row count or zero-row consumption' );
cc54_assert( false === cc54_update( $writer, 'cc_alpha', 2 ), 'repeated row sixth not denied' );
cc54_query( $writer, 'ROLLBACK' );
cc54_assert( cc54_rows( $root, 'cc_alpha' ) === array_fill( 0, 10, 0 ), 'rolled-back repeated row durable' );
echo "  budgets 0/1/5/max, no-op, zero-row and repeated-row events: PASS\n";

// Broad single statement must not make any over-budget row durable.
cc54_open( $writer, $engine, 'cc_alpha', 5 );
cc54_assert( false === $writer->query( 'UPDATE cc_alpha SET touched = touched + 1 WHERE id <= 6' ), 'broad UPDATE' );
$broad_details = $engine->denial_details( 'cc_alpha', 5 );
cc54_assert( 5 === $broad_details['consumed'] && 6 === $broad_details['attempted'] &&
	0 === $broad_details['returned_count'], 'broad statement denial details must account for statement rollback' );
cc54_query( $writer, 'ROLLBACK' );
cc54_assert( cc54_rows( $root, 'cc_alpha' ) === array_fill( 0, 10, 0 ), 'broad UPDATE durable' );

// Allowed accounting rolls back to a savepoint; two tables stay independent.
cc54_open( $writer, $engine, 'cc_alpha', 5 );
$engine->begin_accounting( 'cc_beta', 1 );
for ( $i = 1; $i <= 3; ++$i ) { cc54_update( $writer, 'cc_alpha', $i ); }
cc54_query( $writer, 'SAVEPOINT cc_s' );
cc54_update( $writer, 'cc_alpha', 4 );
cc54_update( $writer, 'cc_alpha', 5 );
cc54_query( $writer, 'ROLLBACK TO SAVEPOINT cc_s' );
cc54_update( $writer, 'cc_alpha', 4 );
cc54_update( $writer, 'cc_alpha', 5 );
cc54_update( $writer, 'cc_beta', 1 );
cc54_assert( 5 === $engine->consumed( 'cc_alpha' ) && 1 === $engine->consumed( 'cc_beta' ), 'independent counters' );
$engine->end_accounting( 'cc_alpha' );
$engine->end_accounting( 'cc_beta' );
cc54_query( $writer, 'COMMIT' );
cc54_assert( array_slice( cc54_rows( $root, 'cc_alpha' ), 0, 6 ) === array( 1, 1, 1, 1, 1, 0 ), 'savepoint replacement durable' );
cc54_assert( 1 === cc54_row( $root, 'cc_beta', 1 ), 'independent beta durable' );
cc54_assert( false === cc54_update( $writer, 'cc_alpha', 6 ), 'stale helper allowed unguarded UPDATE after COMMIT' );
echo "  broad UPDATE, savepoint unwind, two-table independence, stale state cleanup: PASS\n";

// Independent simultaneous sessions: open both before either one COMMITs.
cc54_seed( $root, 'cc_alpha' );
cc54_open( $writer, $engine, 'cc_alpha', 5 );
cc54_open( $other, $second, 'cc_alpha', 5 );
for ( $i = 1; $i <= 5; ++$i ) { cc54_update( $writer, 'cc_alpha', $i ); }
for ( $i = 6; $i <= 10; ++$i ) { cc54_update( $other, 'cc_alpha', $i ); }
cc54_assert( 5 === $engine->consumed( 'cc_alpha' ) && 5 === $second->consumed( 'cc_alpha' ), 'session isolation' );
cc54_commit( $writer, $engine, 'cc_alpha' );
cc54_commit( $other, $second, 'cc_alpha' );
cc54_assert( cc54_rows( $root, 'cc_alpha' ) === array_fill( 0, 10, 1 ), 'two-session durability' );

// Adversarial direct lifecycle calls: this is deliberately outside the
// future #56-owned transaction boundary, but must remain a visible result.
cc54_seed( $root, 'cc_alpha' );
cc54_open( $writer, $engine, 'cc_alpha', 5 );
for ( $i = 1; $i <= 5; ++$i ) { cc54_update( $writer, 'cc_alpha', $i ); }
$engine->end_accounting( 'cc_alpha' );
$engine->begin_accounting( 'cc_alpha', 5 );
for ( $i = 6; $i <= 10; ++$i ) { cc54_update( $writer, 'cc_alpha', $i ); }
$engine->end_accounting( 'cc_alpha' );
cc54_query( $writer, 'COMMIT' );
cc54_assert( cc54_rows( $root, 'cc_alpha' ) === array_fill( 0, 10, 1 ), 'CLOSE→OPEN direct-call reset did not commit ten events' );
echo "CLOSE→OPEN SAME-TRANSACTION AUTHORITY RESET: CONFIRMED ($host)\n";

// Even with this candidate engine, SIGNAL is nonsticky: unsupported manual
// catch/savepoint/COMMIT is deliberately reproduced as a counterexample.
cc54_seed( $root, 'cc_alpha' );
cc54_open( $writer, $engine, 'cc_alpha', 5 );
for ( $i = 1; $i <= 5; ++$i ) { cc54_update( $writer, 'cc_alpha', $i ); }
cc54_query( $writer, 'SAVEPOINT cc_denial' );
cc54_assert( false === cc54_update( $writer, 'cc_alpha', 6 ), 'sixth event not denied' );
cc54_assert( $engine->is_budget_denial(), 'structured denial recognition' );
$denial_error = $writer->last_error;
$denial_errno = $writer->dbh->errno;
$denial_state = $writer->dbh->sqlstate;
$details = $engine->denial_details( 'cc_alpha', 5 );
cc54_assert( $details === array(
	'table' => 'cc_alpha', 'budget' => 5, 'consumed' => 5, 'attempted' => 6,
	'returned_count' => 5,
	'reason' => 'budget_exceeded', 'sqlstate' => '45000', 'errno' => 1644,
), 'denial details' );
cc54_assert( ! $engine->is_budget_denial() && null === $engine->denial_details( 'cc_alpha', 5 ),
	'consumed error state was reused after count query' );
cc54_assert( false !== strpos( $denial_error, 'CC54_DENIED' ), 'missing structured denial marker' );
cc54_assert( 1644 === $denial_errno && '45000' === $denial_state, 'denial code/SQLSTATE' );
cc54_query( $writer, 'ROLLBACK TO SAVEPOINT cc_denial' );
cc54_assert( $engine->denial_seen(), 'savepoint recovery erased denial signal' );
cc54_query( $writer, 'COMMIT' );
cc54_assert( array_slice( cc54_rows( $root, 'cc_alpha' ), 0, 6 ) === array( 1, 1, 1, 1, 1, 0 ), 'nonsticky counterexample lost' );
// This deliberate unsupported manual COMMIT leaves a helper row: a trusted
// maintenance reset, and a fresh guarded transaction must FAIL, not inherit it.
cc54_query( $writer, 'START TRANSACTION' );
cc54_reject( static function () use ( $engine ) { $engine->begin_accounting( 'cc_alpha', 5 ); }, 'stale helper must block new guard' );
cc54_query( $writer, 'ROLLBACK' );
cc54_query( $root, 'DELETE FROM commitcap_v01_state' );
cc54_seed( $root, 'cc_alpha' );
cc54_open( $writer, $engine, 'cc_alpha', 5 );
for ( $i = 1; $i <= 5; ++$i ) { cc54_update( $writer, 'cc_alpha', $i ); }
cc54_assert( false === cc54_update( $writer, 'cc_alpha', 6 ), 'guard denial not detected' );
cc54_assert( 1644 === $writer->dbh->errno && '45000' === $writer->dbh->sqlstate, 'expected structured denial' );
cc54_query( $writer, 'SELECT 1' ); // Callback swallowed the SQL error.
cc54_assert( $engine->denial_seen(), 'swallowed error lost cooperative denial signal' );
cc54_reject( static function () use ( $engine ) { $engine->end_accounting( 'cc_alpha' ); }, 'denied guard close' );
cc54_assert( ! $engine->is_budget_denial(), 'close error misclassified as row-budget denial' );
cc54_query( $writer, 'ROLLBACK' ); // #56 must do this IMMEDIATELY on any error.
cc54_assert( cc54_rows( $root, 'cc_alpha' ) === array_fill( 0, 10, 0 ), 'immediate rollback not atomic' );
cc54_open( $writer, $engine, 'cc_alpha', 5 );
cc54_update( $writer, 'cc_alpha', 1 );
cc54_commit( $writer, $engine, 'cc_alpha' );
cc54_assert( 1 === cc54_row( $root, 'cc_alpha', 1 ), 'new transaction not fresh' );
echo "  two sessions, fresh authority, structured denial, nonsticky DB counterexample: PASS\n";

// A structurally wrong same-name trigger must never be removed or overwritten.
$fake = 'commitcap_v01_' . substr( hash( 'sha256', 'cc_malformed' ), 0, 16 );
$original_body = $root->get_var( $root->prepare(
	'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s', $fake
) );
cc54_assert( is_string( $original_body ), 'original policy body missing' );
$installer->remove_owned_policy( 'cc_malformed', 5, 'cc_writer' );
$policy_id = hash( 'sha256', 'cc_malformed' );
$wrong_id = ( '0' === $policy_id[0] ? '1' : '0' ) . substr( $policy_id, 1 );
$identity_condition = "LOWER(SUBSTRING_INDEX(USER(), '@', 1)) = LOWER('cc_writer')";
$malformed_bodies = array(
	'marker case' => str_replace( "MESSAGE_TEXT = 'CC54_DENIED'", "MESSAGE_TEXT = 'cc54_denied'", $original_body ),
	'policy ID literal' => str_replace( "policy_id = '$policy_id'", "policy_id = '$wrong_id'", $original_body ),
	'budget operator' => str_replace( 'consumed < 5', 'consumed <= 5', $original_body ),
	'wrong runtime identity' => str_replace( $identity_condition, "LOWER(SUBSTRING_INDEX(USER(), '@', 1)) = LOWER('cc_other')", $original_body ),
	'identity check removed' => str_replace( $identity_condition, '1 = 1', $original_body ),
	'foreign principal accepted' => str_replace( $identity_condition, "LOWER(SUBSTRING_INDEX(USER(), '@', 1)) IN (LOWER('cc_writer'), LOWER('cc_foreign'))", $original_body ),
);
cc54_assert( false === strpos( $original_body, 'cc_other' ) && false === strpos( $original_body, 'cc_foreign' ), 'unexpected identity fixture text' );
foreach ( $malformed_bodies as $label => $body ) {
	cc54_assert( $original_body !== $body, 'fixture mutation did not occur: ' . $label );
	cc54_query( $root, "CREATE TRIGGER `$fake` BEFORE UPDATE ON cc_malformed FOR EACH ROW $body" );
	cc54_reject( static function () use ( $installer ) { $installer->verify_policy( 'cc_malformed', 5, 'cc_writer' ); }, $label . ' trusted verification' );
	cc54_reject( static function () use ( $engine ) { $engine->verify_runtime_policy( 'cc_malformed', 5 ); }, $label . ' runtime verification' );
	cc54_reject( static function () use ( $installer ) { $installer->remove_owned_policy( 'cc_malformed', 5, 'cc_writer' ); }, $label . ' unsafe removal' );
	cc54_assert( 1 === (int) $root->get_var( $root->prepare( 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_NAME = %s', $fake ) ), $label . ' unknown trigger removed' );
	cc54_query( $root, "DROP TRIGGER `$fake`" ); // Exactly this test-created fake.
}
// Whitespace outside literals may vary without changing the exact literals.
$formatted = str_replace( "MESSAGE_TEXT = 'CC54_DENIED'", "MESSAGE_TEXT   =   'CC54_DENIED'", $original_body );
cc54_assert( $formatted !== $original_body, 'format-only fixture mutation missing' );
cc54_query( $root, "CREATE TRIGGER `$fake` BEFORE UPDATE ON cc_malformed FOR EACH ROW $formatted" );
$installer->verify_policy( 'cc_malformed', 5, 'cc_writer' );
$engine->verify_runtime_policy( 'cc_malformed', 5 );
$installer->remove_owned_policy( 'cc_malformed', 5, 'cc_writer' );
echo "  marker-case, policy-ID, operator and runtime-identity mutations rejected; external whitespace tolerated: PASS\n";
$installer->remove_owned_policy( 'cc_alpha', 5, 'cc_writer' );
cc54_assert( 0 === count( $installer->inspect_table( 'cc_alpha' )['triggers'] ), 'owned trigger not removed' );
cc54_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cc_alpha'" ), 'user table removed' );
cc54_reject( static function () use ( $engine ) { $engine->begin_accounting( 'cc_alpha', 5 ); }, 'removed runtime policy' );
cc54_query( $root, 'CREATE TABLE cc_late_fk (id INT PRIMARY KEY, beta_id INT, CONSTRAINT cc_late_fk_constraint FOREIGN KEY (beta_id) REFERENCES cc_beta(id) ON UPDATE CASCADE) ENGINE=InnoDB' );
cc54_reject( static function () use ( $engine ) { $engine->verify_runtime_policy( 'cc_beta', 1 ); }, 'late FK conflicts with live policy' );
cc54_query( $root, 'DROP TABLE cc_late_fk' ); // Only the exact test-owned FK fixture.
echo "  ownership-safe removal and unknown-trigger preservation: PASS\n";
echo "#54 $host: ALL EXPECTED ENGINE ASSERTIONS PASS (cooperative boundary only)\n";
