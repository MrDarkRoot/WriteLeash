<?php
// #296 hermetic compare-and-set regression for Price_Apply_Journal::transition().
// Runs with plain PHP. No WordPress, no database, no network:
//   php wordpress/tests/durable/journal-cas-unit.php
// A scripted in-memory wpdb double executes the real UPDATE ... WHERE
// predicate produced by transition() with MySQL affected-rows semantics
// (matched AND changed). A stale expected state therefore changes zero rows
// and must raise JOURNAL_MISMATCH instead of demoting the row.
// True two-session concurrency is NOT covered here (single process); it is
// covered by the row-lock overlap case in durable/integration.php and by the
// MySQL+MariaDB container matrix in CI. This file covers the lock-free and
// stale-caller paths deterministically.

define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

// Minimal global wpdb stub: only what the journal unit under test touches.
class wpdb {
	public $prefix = 'wp_';
	public function prepare( $query, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[isd]/',
			static function ( $m ) use ( &$i, $args ) {
				$a = $args[ $i++ ];
				if ( '%i' === $m[0] ) { return '`' . $a . '`'; }
				if ( '%d' === $m[0] ) { return (string) (int) $a; }
				return "'" . str_replace( "'", "''", (string) $a ) . "'";
			},
			$query
		);
	}
}

require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-durable-charset.php';
require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-price-apply-connection.php';
require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-price-apply-journal.php';

use WriteLeash\Price_Apply_Journal as J;

// In-memory journal table honoring the UPDATE predicate and MySQL
// affected-rows semantics: 1 only when a row matches the full WHERE clause
// AND at least one written column actually changes.
class Fake_Journal_Db extends wpdb {
	public $last_sql = '';
	public $query_count = 0;
	private $rows = array();
	private $clock = 0;
	public function seed( $plan, $id, $state, $reason = '', $attempt = '', $evidence = '', $applied_at = null ) {
		$this->rows[ $plan . '|' . $id ] = array(
			'plan_id'    => $plan,
			'product_id' => (int) $id,
			'state'      => $state,
			'reason'     => $reason,
			'attempt_id' => $attempt,
			'evidence'   => $evidence,
			'applied_at' => $applied_at,
			'updated_at' => '2026-10-15 14:00:00',
		);
	}
	public function read_row( $plan, $id ) {
		return isset( $this->rows[ $plan . '|' . $id ] ) ? $this->rows[ $plan . '|' . $id ] : null;
	}
	private static function unquote( $s ) { return str_replace( "''", "'", $s ); }
	public function query( $sql ) {
		$this->last_sql = $sql;
		++$this->query_count;
		$q = "/\\AUPDATE `[^`]+` SET state='((?:[^']|'')*)',reason='((?:[^']|'')*)',attempt_id='((?:[^']|'')*)',evidence='((?:[^']|'')*)',updated_at=UTC_TIMESTAMP\\(\\),applied_at=IF\\('((?:[^']|'')*)'='APPLIED',UTC_TIMESTAMP\\(\\),NULL\\) WHERE plan_id='((?:[^']|'')*)' AND product_id=(\\d+)( AND state='((?:[^']|'')*)')?\\z/";
		if ( ! preg_match( $q, $sql, $m ) ) { throw new RuntimeException( 'fake cannot execute: ' . $sql ); }
		$key = self::unquote( $m[6] ) . '|' . (int) $m[7];
		if ( ! isset( $this->rows[ $key ] ) ) { return 0; }
		$row = $this->rows[ $key ];
		if ( isset( $m[9] ) && '' !== $m[9] ) {
			// Predicate form produced by transition(): expected prior state.
			if ( $row['state'] !== self::unquote( $m[9] ) ) { return 0; }
		}
		++$this->clock;
		$new = array(
			'state'      => self::unquote( $m[1] ),
			'reason'     => self::unquote( $m[2] ),
			'attempt_id' => self::unquote( $m[3] ),
			'evidence'   => self::unquote( $m[4] ),
			'applied_at' => 'APPLIED' === self::unquote( $m[5] ) ? sprintf( '2026-10-15 14:00:%02d', $this->clock ) : null,
			'updated_at' => sprintf( '2026-10-15 14:00:%02d', $this->clock ),
		);
		$changed = false;
		foreach ( $new as $k => $v ) {
			if ( $v !== $row[ $k ] ) { $changed = true; break; }
		}
		if ( ! $changed ) { return 0; }
		$this->rows[ $key ] = array_merge( $row, $new );
		return 1;
	}
	public function get_row( $sql, $output = null ) {
		$q = "/\\ASELECT \\* FROM `[^`]+` WHERE plan_id='((?:[^']|'')*)' AND product_id=(\\d+)( FOR UPDATE)?\\z/";
		if ( ! preg_match( $q, $sql, $m ) ) { throw new RuntimeException( 'fake cannot execute: ' . $sql ); }
		return $this->read_row( self::unquote( $m[1] ), (int) $m[2] );
	}
}

$assertions = 0;
function eq( $actual, $expected, string $label ): void {
	global $assertions;
	++$assertions;
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
}
function expect_mismatch( callable $fn, string $label ): void {
	global $assertions;
	++$assertions;
	try { $fn(); }
	catch ( \WriteLeash\Price_Apply_Error $error ) {
		if ( 'JOURNAL_MISMATCH' !== $error->getMessage() ) { throw new RuntimeException( $label . ': wrong code ' . $error->getMessage() ); }
		return;
	}
	throw new RuntimeException( $label . ': expected JOURNAL_MISMATCH, transition succeeded' );
}
function fresh_db(): Fake_Journal_Db { return new Fake_Journal_Db(); }

// A. Expected-state happy path: PENDING -> APPLYING -> APPLIED with evidence.
$db = fresh_db();
$db->seed( 'plan-A', 7, 'PENDING' );
J::transition( $db, 'plan-A', 7, 'PENDING', 'APPLYING', 'ITEM_LOCKED', 'attempt-1' );
eq( strpos( $db->last_sql, "AND state='PENDING'" ) !== false, true, 'A predicate carries expected state' );
eq( $db->read_row( 'plan-A', 7 )['state'], 'APPLYING', 'A claim applied' );
eq( $db->read_row( 'plan-A', 7 )['applied_at'], null, 'A non-APPLIED keeps applied_at NULL' );
J::transition( $db, 'plan-A', 7, 'APPLYING', 'APPLIED', 'WOO_CRUD_VERIFIED', 'attempt-1', '{"target":"80.00"}' );
$row = $db->read_row( 'plan-A', 7 );
eq( $row['state'], 'APPLIED', 'A success applied' );
eq( $row['evidence'], '{"target":"80.00"}', 'A evidence stored' );
eq( null !== $row['applied_at'], true, 'A applied_at stamped' );
echo "#296 A expected-state transition: PASS ($assertions assertions)\n";

// B. Stale callback against APPLIED: no demotion, failure reported, evidence intact.
$db = fresh_db();
$db->seed( 'plan-B', 9, 'APPLIED', 'WOO_CRUD_VERIFIED', 'attempt-1', '{"target":"80.00"}', '2026-10-15 14:00:01' );
$queries_before = $db->query_count;
expect_mismatch( static function () use ( $db ) { J::transition( $db, 'plan-B', 9, 'PENDING', 'FAILED', 'STALE', 'stale-1' ); }, 'B stale PENDING vs APPLIED' );
expect_mismatch( static function () use ( $db ) { J::transition( $db, 'plan-B', 9, 'APPLYING', 'APPLIED', 'WOO_CRUD_VERIFIED', 'stale-2', '{"target":"9.99"}' ); }, 'B stale APPLYING vs APPLIED' );
eq( strpos( $db->last_sql, "AND state='APPLYING'" ) !== false, true, 'B stale predicate still conditional' );
$row = $db->read_row( 'plan-B', 9 );
eq( $row['state'], 'APPLIED', 'B no demotion' );
eq( $row['evidence'], '{"target":"80.00"}', 'B evidence preserved' );
eq( $row['applied_at'], '2026-10-15 14:00:01', 'B applied_at preserved' );
eq( $row['attempt_id'], 'attempt-1', 'B attempt preserved' );
echo "#296 B stale callback fail-closed: PASS ($assertions assertions)\n";

// B2. The legacy unconditional UPDATE (pre-#296 shape) against the same fake
// demotes APPLIED and clears applied_at. This proves the harness detects the
// original defect: the tests above fail against the old behavior.
$db = fresh_db();
$db->seed( 'plan-B2', 9, 'APPLIED', 'WOO_CRUD_VERIFIED', 'attempt-1', '{"target":"80.00"}', '2026-10-15 14:00:01' );
$table = J::table( $db );
$legacy = $db->query( $db->prepare( "UPDATE %i SET state=%s,reason=%s,attempt_id=%s,evidence=%s,updated_at=UTC_TIMESTAMP(),applied_at=IF(%s='APPLIED',UTC_TIMESTAMP(),NULL) WHERE plan_id=%s AND product_id=%d", $table, 'FAILED', 'STALE', 'stale-1', '', 'FAILED', 'plan-B2', 9 ) );
eq( $legacy, 1, 'B2 legacy unconditional update succeeds (the defect)' );
$row = $db->read_row( 'plan-B2', 9 );
eq( $row['state'], 'FAILED', 'B2 legacy demotes APPLIED' );
eq( $row['applied_at'], null, 'B2 legacy clears applied_at' );
eq( $queries_before >= 0, true, 'B2 control executed' );
echo "#296 B2 legacy-shape control demotes (defect reproduced on old SQL): PASS ($assertions assertions)\n";

// B3. Wrong expected state against PENDING: zero rows, row stays retryable.
$db = fresh_db();
$db->seed( 'plan-B3', 3, 'PENDING', '', 'attempt-0' );
expect_mismatch( static function () use ( $db ) { J::transition( $db, 'plan-B3', 3, 'APPLYING', 'APPLIED', 'WOO_CRUD_VERIFIED', 'attempt-x', '{}' ); }, 'B3 expected APPLYING vs PENDING' );
eq( $db->read_row( 'plan-B3', 3 )['state'], 'PENDING', 'B3 row stays PENDING' );
echo "#296 B3 mismatched expected on PENDING: PASS ($assertions assertions)\n";

// C. Duplicate callbacks attempting the same logical transition.
$db = fresh_db();
$db->seed( 'plan-C', 5, 'PENDING' );
J::transition( $db, 'plan-C', 5, 'PENDING', 'APPLYING', 'ITEM_LOCKED', 'attempt-1' );
expect_mismatch( static function () use ( $db ) { J::transition( $db, 'plan-C', 5, 'PENDING', 'APPLYING', 'ITEM_LOCKED', 'attempt-2' ); }, 'C duplicate claim with stale expected' );
J::transition( $db, 'plan-C', 5, 'APPLYING', 'APPLIED', 'WOO_CRUD_VERIFIED', 'attempt-1', '{"target":"80.00"}' );
expect_mismatch( static function () use ( $db ) { J::transition( $db, 'plan-C', 5, 'APPLYING', 'APPLIED', 'WOO_CRUD_VERIFIED', 'attempt-1', '{"target":"80.00"}' ); }, 'C duplicate success with stale expected' );
$row = $db->read_row( 'plan-C', 5 );
eq( $row['state'], 'APPLIED', 'C final state APPLIED' );
eq( $row['evidence'], '{"target":"80.00"}', 'C no duplicate corruption' );
eq( $row['attempt_id'], 'attempt-1', 'C first-writer attempt wins' );
echo "#296 C duplicate callbacks: PASS ($assertions assertions)\n";

// D. Deterministic interleaving of two workers sharing one row. (Single
// process: models lock-free/stale ordering, not true DB concurrency. True
// concurrent sessions serialize on the existing row lock; the predicate is
// what makes the lock-free loser fail closed.)
$db = fresh_db();
$db->seed( 'plan-D', 11, 'PENDING' );
$worker_a_seen = J::read( $db, 'plan-D', 11, true );
$worker_b_seen = J::read( $db, 'plan-D', 11, true );
eq( $worker_a_seen['state'], 'PENDING', 'D both observe PENDING' );
eq( $worker_b_seen['state'], 'PENDING', 'D both observe PENDING' );
J::transition( $db, 'plan-D', 11, $worker_b_seen['state'], 'APPLYING', 'ITEM_LOCKED', 'attempt-B' );
J::transition( $db, 'plan-D', 11, 'APPLYING', 'APPLIED', 'WOO_CRUD_VERIFIED', 'attempt-B', '{"by":"B"}' );
expect_mismatch( static function () use ( $db, $worker_a_seen ) { J::transition( $db, 'plan-D', 11, $worker_a_seen['state'], 'APPLYING', 'ITEM_LOCKED', 'attempt-A' ); }, 'D loser with stale observation fails' );
$row = $db->read_row( 'plan-D', 11 );
eq( $row['state'], 'APPLIED', 'D winner state survives' );
eq( $row['evidence'], '{"by":"B"}', 'D winner evidence survives' );
echo "#296 D interleaved workers: PASS ($assertions assertions)\n";

// E. Invalid transitions rejected; graph not broadened.
$db = fresh_db();
$db->seed( 'plan-E', 13, 'PENDING' );
$db->seed( 'plan-E', 14, 'APPLYING' );
$db->seed( 'plan-E', 15, 'APPLIED', 'WOO_CRUD_VERIFIED', 'attempt-1', '{}', '2026-10-15 14:00:01' );
$qc = $db->query_count;
expect_mismatch( static function () use ( $db ) { J::transition( $db, 'plan-E', 13, 'PENDING', 'APPLIED', 'WOO_CRUD_VERIFIED', 'a', '{}' ); }, 'E skip PENDING->APPLIED' );
expect_mismatch( static function () use ( $db ) { J::transition( $db, 'plan-E', 13, 'PENDING', 'BOGUS', 'X', 'a' ); }, 'E unknown target' );
expect_mismatch( static function () use ( $db ) { J::transition( $db, 'plan-E', 14, 'APPLYING', 'PENDING', 'X', 'a' ); }, 'E demotion APPLYING->PENDING' );
expect_mismatch( static function () use ( $db ) { J::transition( $db, 'plan-E', 14, 'APPLYING', 'CONFLICT', 'X', 'a' ); }, 'E APPLYING->CONFLICT' );
expect_mismatch( static function () use ( $db ) { J::transition( $db, 'plan-E', 15, 'APPLIED', 'PENDING', 'X', 'a' ); }, 'E out of APPLIED to PENDING' );
expect_mismatch( static function () use ( $db ) { J::transition( $db, 'plan-E', 15, 'APPLIED', 'FAILED', 'X', 'a' ); }, 'E out of APPLIED to FAILED' );
expect_mismatch( static function () use ( $db ) { J::transition( $db, 'plan-E', 15, 'APPLIED', 'NEEDS_REVIEW', 'X', 'a' ); }, 'E out of APPLIED to NEEDS_REVIEW' );
expect_mismatch( static function () use ( $db ) { J::transition( $db, 'plan-E', 15, 'APPLIED', 'APPLIED', 'X', 'a' ); }, 'E APPLIED->APPLIED' );
expect_mismatch( static function () use ( $db ) { J::transition( $db, 'plan-E', 13, 'CONFLICT', 'PENDING', 'X', 'a' ); }, 'E unknown from-state' );
eq( $db->query_count, $qc, 'E pair violations rejected before any UPDATE' );
eq( $db->read_row( 'plan-E', 13 )['state'], 'PENDING', 'E PENDING intact' );
eq( $db->read_row( 'plan-E', 14 )['state'], 'APPLYING', 'E APPLYING intact' );
eq( $db->read_row( 'plan-E', 15 )['state'], 'APPLIED', 'E APPLIED intact' );
// Allowed refusal pairs still succeed.
foreach ( array( 'PENDING', 'CONFLICT', 'FAILED', 'NEEDS_REVIEW' ) as $target ) {
	$db2 = fresh_db();
	$db2->seed( 'plan-E2', 21, 'PENDING' );
	J::transition( $db2, 'plan-E2', 21, 'PENDING', $target, 'R', 'a' );
	eq( $db2->read_row( 'plan-E2', 21 )['state'], $target, 'E refusal PENDING->' . $target );
}
echo "#296 E invalid transitions rejected: PASS ($assertions assertions)\n";

// F. Failure contract: exact throwable type and code, never silent success.
$db = fresh_db();
$db->seed( 'plan-F', 31, 'PENDING' );
$thrown = null;
try { J::transition( $db, 'plan-F', 31, 'APPLYING', 'APPLIED', 'WOO_CRUD_VERIFIED', 'a', '{}' ); }
catch ( \Throwable $e ) { $thrown = $e; }
eq( $thrown instanceof \WriteLeash\Price_Apply_Error, true, 'F typed throwable' );
eq( $thrown ? $thrown->getMessage() : '', 'JOURNAL_MISMATCH', 'F typed code' );
eq( $db->read_row( 'plan-F', 31 )['state'], 'PENDING', 'F failed CAS wrote nothing' );
echo "#296 F caller failure contract: PASS ($assertions assertions)\n";

// G. #297 applied_at evidence: strict calendar/clock validity, not shape only.
// The shape-only regex accepted month 19, February 31 and hour 24. The shared
// helper backs both worker reconciliation and verifier observe(); downstream
// NEEDS_REVIEW fail-closed behavior for NULL/zero-date rows is already proven
// by the jobs integration cases, which need a real database.
function stamp_row( $applied_at ) {
	$row = array( 'applied_at' => $applied_at );
	if ( null === $applied_at ) { unset( $row['applied_at'] ); }
	return $row;
}
foreach ( array( '2026-10-16 17:29:30', '2024-02-29 12:00:00', '2026-01-31 23:59:59', '2026-04-30 00:00:00', '2026-10-15 14:00:01' ) as $valid ) {
	eq( J::has_valid_applied_at( stamp_row( $valid ) ), true, 'G accepts ' . $valid );
}
foreach ( array(
	'missing key' => null,
	'empty string' => '',
	'zero-date' => '0000-00-00 00:00:00',
	'month 13' => '2026-13-01 00:00:00',
	'month 00' => '2026-00-10 00:00:00',
	'month 19 day 39 hour 29' => '2026-19-39 29:59:59',
	'Feb 29 non-leap' => '2023-02-29 12:00:00',
	'Feb 31' => '2026-02-31 12:00:00',
	'day 00' => '2026-01-00 12:00:00',
	'day 32' => '2026-01-32 12:00:00',
	'hour 24' => '2026-04-15 24:00:00',
	'hour 29' => '2026-10-16 29:00:00',
	'minute 60' => '2026-10-16 12:60:00',
	'second 60' => '2026-10-16 12:00:60',
	'trailing space' => '2026-10-16 17:29:30 ',
	'trailing char' => '2026-10-16 17:29:30x',
	'leading space' => ' 2026-10-16 17:29:30',
	'date only' => '2026-10-16',
	'time only' => '17:29:30',
	'slashes' => '2026/10/16 17:29:30',
	'iso T' => '2026-10-16T17:29:30',
	'dmy' => '16-10-2026 17:29:30',
	'garbage' => 'not-a-timestamp',
) as $label => $invalid ) {
	eq( J::has_valid_applied_at( stamp_row( $invalid ) ), false, 'G rejects ' . $label );
}
eq( J::has_valid_applied_at( array( 'applied_at' => null ) ), false, 'G rejects explicit null' );
echo "#297 G applied_at calendar/clock validity: PASS ($assertions assertions)\n";

echo "#296 journal compare-and-set unit matrix: PASS ($assertions assertions total)\n";
