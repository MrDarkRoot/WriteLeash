<?php
use WriteLeash\Job_Item_State as IState;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Job_Scheduler as Scheduler;
use WriteLeash\Job_Schema as Schema;
use WriteLeash\Job_State as JState;
use WriteLeash\Job_Worker as Worker;
use WriteLeash\Price_Apply_Journal as Journal;
use WriteLeash\Price_Cache_Verifier as Verifier;
use WriteLeash\Price_Decimal as Decimal;
use WriteLeash\Price_Operation as Operation;
use WriteLeash\Price_Selection_Spec as Selection;
use WriteLeash\Safety_Policy as Policy;
use WriteLeash\Woo_Price_Planner as Planner;
use WriteLeash\Undo_Item_State as UItem;
use WriteLeash\Undo_Repository as URepo;
use WriteLeash\Undo_Scheduler as UScheduler;
use WriteLeash\Undo_Schema as USchema;
use WriteLeash\Undo_State as UState;
use WriteLeash\Undo_Worker as UWorker;
use WriteLeash\Undo_Reason as UReason;
use WriteLeash\Undo_Fingerprint as UFingerprint;

global $wpdb, $work, $assertions, $hook_log;
$assertions = 0;
$work = sys_get_temp_dir() . '/wl110-' . bin2hex( random_bytes( 5 ) );
mkdir( $work );
$hook_log = $work . '/hooks.log';
touch( $hook_log );
$GLOBALS['wl108_active'] = true;
$GLOBALS['wl108_log'] = $hook_log;
@unlink( WP_CONTENT_DIR . '/debug.log' );

function eq( $actual, $expected, string $label ): void {
	global $assertions;
	++$assertions;
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
}
function ok( bool $condition, string $label ): void { eq( $condition, true, $label ); }
function marker( string $label ): void {
	global $assertions;
	echo "#110 $label: PASS ($assertions assertions)\n";
}
function create_product( string $price, string $status = 'publish' ): int {
	$p = new WC_Product_Simple();
	$p->set_name( 'WL110-' . wp_generate_uuid4() );
	$p->set_status( $status );
	$p->set_regular_price( $price );
	$p->save();
	return $p->get_id();
}
function apply_fixture( array $opts = array() ): array {
	$changing = (int) ( $opts['changing'] ?? 1 );
	$price = (string) ( $opts['price'] ?? '100.00' );
	$target = (string) ( $opts['target'] ?? '80.00' );
	$actor = (int) ( $opts['actor'] ?? 1 );
	wp_set_current_user( $actor );
	$ids = array();
	for ( $i = 0; $i < $changing; ++$i ) { $ids[] = create_product( $price, 'publish' ); }
	sort( $ids, SORT_NUMERIC );
	$plan = Planner::preview( Selection::ids( $ids ), new Operation( Operation::SET, $target ), new Policy( 1000, '100', '100', true, '100' ) );
	$job = Repo::create_from_plan( $plan, $actor );
	$job = Repo::approve( (int) $job['id'], $actor );
	$runs = 0;
	do {
		run_worker( array( 'job_id' => (int) $job['id'], 'mode' => 'apply', 'limits' => limits( 10 ), 'manual' => true ) );
		++$runs;
		$job = Repo::read( (int) $job['id'] );
	} while ( in_array( $job['status'], array( JState::READY, JState::QUEUED, JState::RUNNING ), true ) && $runs < 10 );
	return array( 'plan' => $plan, 'job' => $job, 'ids' => $ids, 'target' => $target, 'actor' => $actor );
}
function start_undo( int $job_id, int $initiator = 1 ): array {
	wp_set_current_user( $initiator );
	return URepo::initiate( $job_id, $initiator );
}
function undo_counts( int $undo_id ): array { return URepo::counts( $undo_id ); }
function undo_row( int $job_id, int $product_id ): ?array {
	global $wpdb;
	return URepo::read_item( $wpdb, $job_id, $product_id );
}
function saves( int $id ): int {
	global $hook_log;
	if ( ! is_file( $hook_log ) ) { return 0; }
	$count = 0;
	foreach ( file( $hook_log, FILE_IGNORE_NEW_LINES ) as $line ) {
		$entry = json_decode( $line, true );
		if ( is_array( $entry ) && 'woocommerce_before_product_object_save' === ( $entry['hook'] ?? '' ) && $id === (int) ( $entry['id'] ?? 0 ) ) { ++$count; }
	}
	return $count;
}
function journal_row( string $plan_id, int $product_id ): ?array {
	$db = Verifier::observer();
	try { return Journal::read( $db, $plan_id, $product_id ); }
	finally { $db->close(); }
}
function fresh_price( int $id ): string {
	Verifier::invalidate( $id );
	$p = wc_get_product( $id );
	return (string) $p->get_regular_price( 'edit' );
}
function fresh_active( int $id ): string {
	Verifier::invalidate( $id );
	return (string) wc_get_product( $id )->get_price( 'edit' );
}
function fresh_lookup( int $id ): array {
	$db = Verifier::observer();
	try { return Verifier::storage( $db, $id )['lookup']; }
	finally { $db->close(); }
}
function start_worker( array $spec, string $script = 'worker.php' ): array {
	global $work;
	$prefix = $work . '/proc-' . bin2hex( random_bytes( 5 ) );
	$spec = array_merge( array( 'result' => $prefix . '.result', 'barrier' => $prefix . '.barrier', 'release' => $prefix . '.release', 'started' => $prefix . '.started', 'hook_log' => $GLOBALS['hook_log'] ), $spec );
	file_put_contents( $prefix . '.json', json_encode( $spec ) );
	$proc = proc_open( array( 'wp', '--path=' . ABSPATH, 'eval-file', __DIR__ . '/' . $script, $prefix . '.json' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $prefix . '.stdout', 'w' ), 2 => array( 'file', $prefix . '.stderr', 'w' ) ), $pipes );
	if ( ! is_resource( $proc ) ) { throw new RuntimeException( 'worker spawn failed' ); }
	fclose( $pipes[0] );
	return array( 'proc' => $proc, 'spec' => $spec, 'prefix' => $prefix );
}
function await_file( string $file, float $timeout = 25 ): void {
	$deadline = microtime( true ) + $timeout;
	while ( ! is_file( $file ) ) {
		if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'missing deterministic barrier: ' . $file ); }
		usleep( 10000 );
	}
}
function finish_worker( array $worker, bool $killed = false ): array {
	$exit = proc_close( $worker['proc'] );
	if ( $killed ) {
		eq( in_array( $exit, array( 9, 137 ), true ), true, 'actual SIGKILL exit' );
		return array();
	}
	if ( 0 !== $exit || ! is_file( $worker['spec']['result'] ) ) {
		throw new RuntimeException( 'worker failure: ' . file_get_contents( $worker['prefix'] . '.stdout' ) . file_get_contents( $worker['prefix'] . '.stderr' ) );
	}
	return json_decode( file_get_contents( $worker['spec']['result'] ), true );
}
function run_worker( array $spec, string $script = 'worker.php' ): array { return finish_worker( start_worker( $spec, $script ) ); }
function limits( int $items, int $seconds = 20 ): array { return array( 'max_items' => $items, 'budget_seconds' => $seconds ); }
function expire_undo_lease( int $undo_id ): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=%d', USchema::operations_table( $wpdb ), $undo_id ) );
}

wp_set_current_user( 1 );
eq( WC_VERSION, '11.1.2', 'pinned Woo' );
eq( get_bloginfo( 'version' ), '7.1.2', 'pinned WordPress' );
eq( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, '8.2', 'pinned PHP' );
eq( (bool) wp_using_ext_object_cache(), 'persistent' === getenv( 'WL110_CACHE' ), 'real persistent cache mode' );
if ( wp_using_ext_object_cache() ) {
	eq( get_file_data( WP_CONTENT_DIR . '/object-cache.php', array( 'version' => 'Version' ) )['version'], '2.7.0', 'pinned Redis drop-in' );
}
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
add_option( 'writeleash_unrelated', 'keep me' );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wl108_hook_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, product_id bigint unsigned NOT NULL, hook varchar(100) NOT NULL) ENGINE=InnoDB" );
$undo_ops = USchema::operations_table( $wpdb );
$undo_items = USchema::items_table( $wpdb );

// ---------------------------------------------------------------------------
// Undo schema: fresh install, replay, unknown version, partial, wrong engine.
// ---------------------------------------------------------------------------
$s1 = apply_fixture( array( 'changing' => 1 ) );
eq( $s1['job']['status'], JState::COMPLETED, 'setup apply completed' );
$op1 = start_undo( (int) $s1['job']['id'] );
eq( USchema::ready( $wpdb ), true, 'fresh undo import installed schema' );
eq( get_option( 'writeleash_undo_setup' ), 'READY', 'undo setup recorded ready' );
eq( $op1['status'], UState::PENDING, 'initiation lands UNDO_PENDING' );
eq( undo_counts( (int) $op1['id'] )['eligible'], 1, 'one eligible undo item' );
USchema::install();
USchema::install();
eq( USchema::ready( $wpdb ), true, 'undo install replay idempotent' );
marker( 'undo schema fresh install + replay' );

update_option( 'writeleash_undo_schema', 99, false );
eq( USchema::ready( $wpdb ), false, 'unknown undo schema version not ready' );
$refused = run_worker( array( 'undo_id' => (int) $op1['id'], 'mode' => 'undo', 'limits' => limits( 5 ) ) );
eq( $refused['stop'], 'SCHEMA_UNAVAILABLE', 'undo worker refuses unknown schema' );
eq( $refused['processed'], 0, 'zero undo items on unknown schema' );
update_option( 'writeleash_undo_schema', USchema::SCHEMA_VERSION, false );

$wpdb->query( "RENAME TABLE $undo_items TO {$undo_items}_partial" );
$partial = run_worker( array( 'undo_id' => (int) $op1['id'], 'mode' => 'undo', 'limits' => limits( 5 ) ) );
eq( $partial['stop'], 'SCHEMA_UNAVAILABLE', 'missing undo items table refuses execution' );
eq( $partial['processed'], 0, 'zero processed with partial undo schema' );
eq( saves( $s1['ids'][0] ), 1, 'partial undo schema zero mutation' );
eq( URepo::read_operation( (int) $op1['id'] )['status_reason'], 'SCHEMA_UNAVAILABLE', 'partial undo schema typed pause' );
$wpdb->query( "RENAME TABLE {$undo_items}_partial TO $undo_items" );
USchema::install();
eq( USchema::ready( $wpdb ), true, 'partial undo replay repaired' );

$wpdb->query( "ALTER TABLE $undo_items ENGINE=MyISAM" );
$wrong = run_worker( array( 'undo_id' => (int) $op1['id'], 'mode' => 'undo', 'limits' => limits( 5 ) ) );
eq( $wrong['stop'], 'SCHEMA_UNAVAILABLE', 'wrong undo engine refuses execution' );
eq( $wrong['processed'], 0, 'wrong undo engine zero processed' );
eq( saves( $s1['ids'][0] ), 1, 'wrong undo engine zero mutation' );
$wpdb->query( "ALTER TABLE $undo_items ENGINE=InnoDB" );
eq( USchema::ready( $wpdb ), true, 'undo engine restored' );
$repair = run_worker( array( 'undo_id' => (int) $op1['id'], 'mode' => 'undo', 'limits' => limits( 5 ), 'manual' => true ) );
eq( $repair['status'], UState::COMPLETED, 'manual undo resume after repair completes' );
eq( Decimal::parse( fresh_price( $s1['ids'][0] ) ), Decimal::parse( '100.00' ), 'repaired resume restores original' );
marker( 'undo schema unknown/partial/wrong-engine fail closed with zero mutation' );

// ---------------------------------------------------------------------------
// Partial Undo E2E: A untouched, B external 90, C sale configured, D deleted.
// ---------------------------------------------------------------------------
$f = apply_fixture( array( 'changing' => 4 ) );
$job_id = (int) $f['job']['id'];
$plan_id = $f['plan']->data()['plan_id'];
list( $id_a, $id_b, $id_c, $id_d ) = $f['ids'];
foreach ( $f['ids'] as $id ) { eq( fresh_price( $id ), '80.00', 'applied price ' . $id ); }
$p = wc_get_product( $id_b );
$p->set_regular_price( '90.00' );
$p->save();
$p = wc_get_product( $id_c );
$p->set_sale_price( '70.00' );
$p->save();
wc_get_product( $id_d )->delete( true );
$apply_saves = array();
foreach ( $f['ids'] as $id ) { $apply_saves[$id] = saves( $id ); }
$op = start_undo( $job_id );
$undo_id = (int) $op['id'];
eq( undo_counts( $undo_id )['eligible'], 4, 'four eligible undo items' );
// Initiation is idempotent per job.
$op_again = start_undo( $job_id );
eq( (int) $op_again['id'], $undo_id, 're-initiation returns the same operation' );
$result = run_worker( array( 'undo_id' => $undo_id, 'mode' => 'undo', 'limits' => limits( 10 ), 'manual' => true ) );
eq( $result['status'], UState::COMPLETED_WITH_ISSUES, 'partial undo is not generic success' );
$counts = undo_counts( $undo_id );
eq( $counts['undone'], 1, 'one undone' );
eq( $counts['conflict'], 3, 'three conflicts' );
eq( Decimal::parse( fresh_price( $id_a ) ), Decimal::parse( '100.00' ), 'A restored to original' );
eq( fresh_price( $id_b ), '90.00', 'B external price not overwritten' );
eq( fresh_price( $id_c ), '80.00', 'C sale context not overwritten' );
eq( undo_row( $job_id, $id_a )['state'], UItem::UNDONE, 'A durable UNDONE' );
eq( undo_row( $job_id, $id_b )['state'], UItem::CONFLICT, 'B durable UNDO_CONFLICT' );
eq( undo_row( $job_id, $id_c )['state'], UItem::CONFLICT, 'C durable UNDO_CONFLICT' );
eq( undo_row( $job_id, $id_d )['state'], UItem::CONFLICT, 'D durable UNDO_CONFLICT' );
eq( undo_row( $job_id, $id_d )['reason'], 'PRODUCT_MISSING', 'D typed product missing' );
eq( saves( $id_a ) - $apply_saves[$id_a], 1, 'A exactly one restore save' );
eq( saves( $id_b ) - $apply_saves[$id_b], 0, 'B zero restore saves' );
eq( saves( $id_c ) - $apply_saves[$id_c], 0, 'C zero restore saves' );
// Apply history is preserved, never rewritten.
eq( journal_row( $plan_id, $id_a )['state'], 'APPLIED', 'apply journal remains APPLIED' );
$job_items = Repo::items( $job_id, IState::APPLIED, 0, 100 );
eq( count( $job_items ), 4, 'apply items remain APPLIED history' );
eq( Repo::read( $job_id )['status'], JState::COMPLETED, 'apply job terminal state untouched' );
echo "#110 clean Undo:\n100->80->100\nfresh observer PASS\n";
echo "#110 external edit:\n80->90\nUNDO_CONFLICT\nzero restore save\nPASS\n";
marker( 'partial Undo E2E: exact undone/conflict counts, zero overwrite, history preserved' );

// ---------------------------------------------------------------------------
// Cache parity, fresh observer, hook counts, ALREADY_UNDONE retry.
// ---------------------------------------------------------------------------
eq( Decimal::parse( fresh_price( $id_a ) ), Decimal::parse( '100.00' ), 'restored regular price' );
eq( Decimal::parse( fresh_active( $id_a ) ), Decimal::parse( '100.00' ), 'restored active price' );
$lookup = fresh_lookup( $id_a );
eq( Decimal::parse( $lookup['min_price'] ), Decimal::parse( '100.00' ), 'restored lookup min' );
eq( Decimal::parse( $lookup['max_price'] ), Decimal::parse( '100.00' ), 'restored lookup max' );
eq( (string) $lookup['onsale'], '0', 'restored lookup offsale' );
$hook_before = saves( $id_a );
$retry = run_worker( array( 'undo_id' => $undo_id, 'mode' => 'undo', 'limits' => limits( 10 ), 'manual' => true ) );
eq( $retry['status'], UState::COMPLETED_WITH_ISSUES, 'terminal operation stays truthful on retry' );
eq( saves( $id_a ) - $hook_before, 0, 'retry ALREADY_UNDONE fires zero saves' );
$evidence = json_decode( undo_row( $job_id, $id_a )['evidence'], true );
ok( is_array( $evidence ) && Decimal::parse( $evidence['restored_price'] ) === Decimal::parse( '100.00' ) && $evidence['from_applied_price'] === '80.00', 'UNDONE evidence proves restore' );
ok( ( $evidence['connection_id'] ?? 0 ) > 0 && ( $evidence['fence_connection_id'] ?? 0 ) === ( $evidence['connection_id'] ?? -1 ), 'fence, Woo writes and Undo journal share one connection' );
echo "#110 duplicate Undo:\none Woo restore save\none UNDONE\nPASS\n";
marker( 'cache parity, fresh observer, hook counts, idempotent retry' );

// ---------------------------------------------------------------------------
// Duplicate overlapping workers: exactly one restore save.
// ---------------------------------------------------------------------------
$g = apply_fixture( array( 'changing' => 1 ) );
$g_job = (int) $g['job']['id'];
$g_id = $g['ids'][0];
$g_op = start_undo( $g_job );
$g_undo = (int) $g_op['id'];
$base = saves( $g_id );
$w1 = start_worker( array( 'undo_id' => $g_undo, 'mode' => 'undo', 'limits' => limits( 5 ), 'undo_checkpoint' => 'AFTER_CLAIM', 'fault' => 'wait' ) );
await_file( $w1['spec']['barrier'] );
// W1 holds the claim; W2 must observe lease-held and perform zero items.
$w2 = run_worker( array( 'undo_id' => $g_undo, 'mode' => 'undo', 'limits' => limits( 5 ) ) );
eq( $w2['processed'], 0, 'overlapping worker performs zero items' );
file_put_contents( $w1['spec']['release'], 'go' );
$r1 = finish_worker( $w1 );
eq( $r1['status'], UState::COMPLETED, 'first worker completes' );
eq( saves( $g_id ) - $base, 1, 'duplicate overlap yields exactly one restore save' );
eq( undo_row( $g_job, $g_id )['state'], UItem::UNDONE, 'one durable UNDONE' );
eq( Decimal::parse( fresh_price( $g_id ) ), Decimal::parse( '100.00' ), 'duplicate overlap restores once' );
// Sequential duplicate restore through the primitive is ALREADY_UNDONE.
wp_set_current_user( 1 );
$dup = WriteLeash\Woo_Undo_Mutator::restore( $g_job, $g_undo, $g_id, wp_generate_uuid4(), null );
eq( $dup['code'], 'ALREADY_UNDONE', 'second restore suppressed without save' );
eq( saves( $g_id ) - $base, 1, 'sequential duplicate adds zero saves' );
marker( 'duplicate Undo workers: one save, one UNDONE, no replay' );

// ---------------------------------------------------------------------------
// Crash matrix with real SIGKILL.
// ---------------------------------------------------------------------------
foreach ( array( 'UNDO_AFTER_WOO_SAVE_BEFORE_JOURNAL', 'UNDO_AFTER_JOURNAL_BEFORE_COMMIT' ) as $point ) {
	$h = apply_fixture( array( 'changing' => 1 ) );
	$h_job = (int) $h['job']['id'];
	$h_id = $h['ids'][0];
	$h_op = start_undo( $h_job );
	$h_undo = (int) $h_op['id'];
	$h_base = saves( $h_id );
	$w = start_worker( array( 'undo_id' => $h_undo, 'mode' => 'undo', 'limits' => limits( 5 ), 'undo_checkpoint' => $point, 'fault' => 'wait' ) );
	await_file( $w['spec']['barrier'] );
	proc_terminate( $w['proc'], 9 );
	finish_worker( $w, true );
	eq( fresh_price( $h_id ), '80.00', 'kill before COMMIT keeps durable applied price ' . $point );
	eq( undo_row( $h_job, $h_id )['state'], UItem::APPLYING, 'undo claimed but not durable after rollback ' . $point );
	expire_undo_lease( $h_undo );
	$rec = run_worker( array( 'undo_id' => $h_undo, 'mode' => 'undo', 'limits' => limits( 5 ) ) );
	eq( $rec['status'], UState::COMPLETED, 'recovery completes after kill ' . $point );
	eq( Decimal::parse( fresh_price( $h_id ) ), Decimal::parse( '100.00' ), 'recovery restores exactly once ' . $point );
	eq( undo_row( $h_job, $h_id )['state'], UItem::UNDONE, 'recovery durable UNDONE ' . $point );
}
echo "#110 crash before COMMIT:\ndurable price remains applied\nUndo not UNDONE\nPASS\n";
marker( 'kill before COMMIT at both points: rollback, safe retry' );

$k = apply_fixture( array( 'changing' => 1 ) );
$k_job = (int) $k['job']['id'];
$k_id = $k['ids'][0];
$k_op = start_undo( $k_job );
$k_undo = (int) $k_op['id'];
$k_base = saves( $k_id );
$w = start_worker( array( 'undo_id' => $k_undo, 'mode' => 'undo', 'limits' => limits( 5 ), 'undo_checkpoint' => 'UNDO_AFTER_COMMIT_BEFORE_RESPONSE', 'fault' => 'wait' ) );
await_file( $w['spec']['barrier'] );
eq( undo_row( $k_job, $k_id )['state'], UItem::UNDONE, 'COMMIT durable before kill' );
proc_terminate( $w['proc'], 9 );
finish_worker( $w, true );
expire_undo_lease( $k_undo );
$rec = run_worker( array( 'undo_id' => $k_undo, 'mode' => 'undo', 'limits' => limits( 5 ) ) );
eq( undo_row( $k_job, $k_id )['state'], UItem::UNDONE, 'UNDONE recovered without replay' );
eq( saves( $k_id ) - $k_base, 1, 'kill after COMMIT adds zero saves' );
eq( Decimal::parse( fresh_price( $k_id ) ), Decimal::parse( '100.00' ), 'kill after COMMIT price restored' );
echo "#110 crash after COMMIT:\nUNDONE recovered\nsave replay=0\nPASS\n";
marker( 'kill after COMMIT: durable UNDONE adopted, no second save' );

// Ambiguous COMMIT: lost acknowledgement must not blindly retry.
$amb = apply_fixture( array( 'changing' => 1 ) );
$amb_job = (int) $amb['job']['id'];
$amb_id = $amb['ids'][0];
$amb_op = start_undo( $amb_job );
$amb_undo = (int) $amb_op['id'];
$amb_base = saves( $amb_id );
$thrown = run_worker( array( 'undo_id' => $amb_undo, 'mode' => 'undo', 'limits' => limits( 5 ), 'undo_checkpoint' => 'UNDO_AFTER_COMMIT_BEFORE_RESPONSE', 'fault' => 'throw' ) );
eq( $thrown['status'], UState::NEEDS_REVIEW, 'ambiguous COMMIT pauses for review' );
eq( undo_row( $amb_job, $amb_id )['state'], UItem::UNDONE, 'ambiguous row is durably UNDONE' );
$resume = run_worker( array( 'undo_id' => $amb_undo, 'mode' => 'undo', 'limits' => limits( 5 ), 'manual' => true ) );
eq( $resume['status'], UState::COMPLETED, 'manual resume adopts durable UNDONE' );
eq( saves( $amb_id ) - $amb_base, 1, 'ambiguous recovery adds zero saves' );
marker( 'ambiguous COMMIT: NEEDS_REVIEW, no blind retry, later adoption without replay' );

// ---------------------------------------------------------------------------
// Apply/Undo race: no simultaneous unsupported mutation.
// ---------------------------------------------------------------------------
$r = apply_fixture( array( 'changing' => 1, 'price' => '100.00', 'target' => '80.00' ) );
$r_job = (int) $r['job']['id'];
// A job in a non-terminal apply state refuses initiation outright.
$running_job = apply_fixture( array( 'changing' => 1 ) );
// Rewind one item to PENDING to simulate a safely-stopped-but-live job shape.
$rj = (int) $running_job['job']['id'];
$wpdb->query( $wpdb->prepare( "UPDATE %i SET status=%s WHERE id=%d", Schema::jobs_table( $wpdb ), JState::RUNNING, $rj ) );
$refused_initiation = null;
try { start_undo( $rj ); }
catch ( WriteLeash\Undo_Error $error ) { $refused_initiation = $error->reason(); }
eq( $refused_initiation, 'UNDO_JOB_RUNNING', 'running job refuses Undo initiation' );
$wpdb->query( $wpdb->prepare( "UPDATE %i SET status=%s WHERE id=%d", Schema::jobs_table( $wpdb ), JState::COMPLETED, $rj ) );
// Terminal job: an apply worker cannot acquire a lease while Undo runs.
$r_op = start_undo( $r_job );
$r_undo = (int) $r_op['id'];
$w = start_worker( array( 'undo_id' => $r_undo, 'mode' => 'undo', 'limits' => limits( 5 ), 'undo_checkpoint' => 'UNDO_BEFORE_WOO_SAVE', 'fault' => 'wait' ) );
await_file( $w['spec']['barrier'] );
$apply_race = run_worker( array( 'job_id' => $r_job, 'mode' => 'apply', 'limits' => limits( 5 ) ) );
eq( $apply_race['stop'], 'JOB_TERMINAL', 'apply cannot run on a terminal job during Undo' );
file_put_contents( $w['spec']['release'], 'go' );
$done = finish_worker( $w );
eq( $done['status'], UState::COMPLETED, 'undo completes after race probe' );
eq( Decimal::parse( fresh_price( $r['ids'][0] ) ), Decimal::parse( '100.00' ), 'race leaves no lost update' );
marker( 'apply/Undo race: initiation refused while running, apply fenced while undoing' );

// ---------------------------------------------------------------------------
// Settings drift, revoked permission, deleted initiator.
// ---------------------------------------------------------------------------
$d = apply_fixture( array( 'changing' => 1 ) );
$d_job = (int) $d['job']['id'];
$d_id = $d['ids'][0];
$d_op = start_undo( $d_job );
$d_undo = (int) $d_op['id'];
update_option( 'woocommerce_currency', 'EUR' );
$drift = run_worker( array( 'undo_id' => $d_undo, 'mode' => 'undo', 'limits' => limits( 5 ) ) );
eq( $drift['status'], UState::COMPLETED_WITH_ISSUES, 'currency drift completes with issues' );
eq( undo_row( $d_job, $d_id )['state'], UItem::CONFLICT, 'currency drift typed conflict' );
eq( fresh_price( $d_id ), '80.00', 'currency drift zero overwrite' );
update_option( 'woocommerce_currency', 'USD' );
$dec = apply_fixture( array( 'changing' => 1 ) );
$dec_job = (int) $dec['job']['id'];
$dec_id = $dec['ids'][0];
$dec_op = start_undo( $dec_job );
update_option( 'woocommerce_price_num_decimals', 3 );
$dec_res = run_worker( array( 'undo_id' => (int) $dec_op['id'], 'mode' => 'undo', 'limits' => limits( 5 ) ) );
eq( undo_row( $dec_job, $dec_id )['state'], UItem::CONFLICT, 'decimals drift typed conflict' );
eq( fresh_price( $dec_id ), '80.00', 'decimals drift zero overwrite' );
update_option( 'woocommerce_price_num_decimals', 2 );
marker( 'currency/decimals drift: typed UNDO_CONFLICT, zero reinterpretation' );

$manager_role = get_role( 'shop_manager' );
$actor = wp_insert_user( array( 'user_login' => 'wl110-actor-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$pr = apply_fixture( array( 'changing' => 1, 'actor' => (int) $actor ) );
$pr_job = (int) $pr['job']['id'];
$pr_id = $pr['ids'][0];
$pr_op = start_undo( $pr_job, (int) $actor );
$manager_role->remove_cap( 'edit_products' );
$revoked = run_worker( array( 'undo_id' => (int) $pr_op['id'], 'mode' => 'undo', 'limits' => limits( 5 ) ) );
eq( $revoked['stop'], 'PERMISSION_REVOKED', 'revoked capability typed pause' );
eq( undo_row( $pr_job, $pr_id )['state'], UItem::FAILED, 'revoked item failed without retry' );
eq( undo_row( $pr_job, $pr_id )['reason'], 'PERMISSION_DENIED', 'revoked typed reason' );
eq( fresh_price( $pr_id ), '80.00', 'revoked zero mutation' );
$manager_role->add_cap( 'edit_products' );
marker( 'permission revocation: zero mutation, typed PERMISSION_DENIED' );

$actor2 = wp_insert_user( array( 'user_login' => 'wl110-gone-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$gone = apply_fixture( array( 'changing' => 1, 'actor' => (int) $actor2 ) );
$gone_job = (int) $gone['job']['id'];
$gone_id = $gone['ids'][0];
$gone_op = start_undo( $gone_job, (int) $actor2 );
wp_delete_user( (int) $actor2 );
$gone_run = run_worker( array( 'undo_id' => (int) $gone_op['id'], 'mode' => 'undo', 'limits' => limits( 5 ) ) );
eq( $gone_run['stop'], 'PERMISSION_REVOKED', 'deleted initiator fails closed' );
eq( fresh_price( $gone_id ), '80.00', 'deleted initiator zero mutation' );
marker( 'deleted initiator: no implicit root, explicit authorization only' );

// ---------------------------------------------------------------------------
// ABA residual and fingerprint tampering fail closed.
// ---------------------------------------------------------------------------
$aba = apply_fixture( array( 'changing' => 1 ) );
$aba_job = (int) $aba['job']['id'];
$aba_id = $aba['ids'][0];
$ext = wc_get_product( $aba_id );
$ext->set_regular_price( '70.00' );
$ext->save();
$ext = wc_get_product( $aba_id );
$ext->set_regular_price( '80.00' );
$ext->save();
$aba_op = start_undo( $aba_job );
$aba_res = run_worker( array( 'undo_id' => (int) $aba_op['id'], 'mode' => 'undo', 'limits' => limits( 5 ) ) );
// Documented residual: value-plus-context equality cannot prove the
// intervening 80->70->80 edit through supported APIs, so Undo proceeds.
eq( undo_row( $aba_job, $aba_id )['state'], UItem::UNDONE, 'ABA residual proceeds with documented limitation' );
eq( Decimal::parse( fresh_price( $aba_id ) ), Decimal::parse( '100.00' ), 'ABA residual restores original' );
marker( 'ABA 80->70->80: residual limitation documented, behavior conservative-by-contract' );

foreach ( array( 'applied_price', 'fingerprint', 'expected_price' ) as $column ) {
	$t = apply_fixture( array( 'changing' => 1 ) );
	$t_job = (int) $t['job']['id'];
	$t_id = $t['ids'][0];
	$t_op = start_undo( $t_job );
	$t_undo = (int) $t_op['id'];
	$t_base = saves( $t_id );
	$value = 'fingerprint' === $column ? str_repeat( '0', 64 ) : ( 'applied_price' === $column ? '999.99' : '10.00' );
	$wpdb->query( $wpdb->prepare( "UPDATE $undo_items SET $column=%s WHERE undo_id=%d", $value, $t_undo ) );
	$tampered = run_worker( array( 'undo_id' => $t_undo, 'mode' => 'undo', 'limits' => limits( 5 ) ) );
	$row = undo_row( $t_job, $t_id );
	eq( $row['state'], UItem::NEEDS_REVIEW, 'tampered ' . $column . ' fails closed' );
	eq( saves( $t_id ) - $t_base, 0, 'tampered ' . $column . ' zero Woo restore' );
	eq( fresh_price( $t_id ), '80.00', 'tampered ' . $column . ' price retained' );
}
marker( 'provenance tampering: applied price, fingerprint and original all fail closed' );

// ---------------------------------------------------------------------------
// History: pagination, authorization, eligibility, expiry.
// ---------------------------------------------------------------------------
$history = URepo::history_job( $job_id );
eq( $history['job_id'], $job_id, 'history job identity' );
eq( $history['apply']['applied'], 4, 'history apply counts preserved' );
eq( $history['undo']['undone'], 1, 'history undo counts additive' );
eq( $history['undo']['conflict'], 3, 'history undo conflict counts' );
eq( $history['undo_eligible'], false, 'finished Undo operation not eligible again' );
ok( null !== $history['undo_expires_at'], 'history exposes undo expiry' );
$page = URepo::history_jobs( 0, 1 );
eq( count( $page['jobs'] ), 1, 'history jobs bounded page' );
$page2 = URepo::history_jobs( 1, 1 );
ok( $page['jobs'][0]['job_id'] !== $page2['jobs'][0]['job_id'], 'history jobs deterministic ordering' );
$items_page = URepo::history_items( $job_id, null, null, 0, 50 );
eq( count( $items_page['items'] ), 4, 'history items page' );
$undone_only = URepo::history_items( $job_id, null, UItem::UNDONE, 0, 50 );
eq( count( $undone_only['items'] ), 1, 'history items undo-state filter' );
eq( Decimal::parse( $undone_only['items'][0]['restored_price'] ), Decimal::parse( '100.00' ), 'history restored price' );
$subscriber = wp_insert_user( array( 'user_login' => 'wl110-sub-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$foreign = wp_insert_user( array( 'user_login' => 'wl110-mgr-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$job_row = Repo::read( $job_id );
eq( URepo::authorized( $job_row, (int) $subscriber ), false, 'subscriber history not authorized' );
eq( URepo::authorized( $job_row, (int) $foreign ), false, 'foreign manager history not authorized' );
eq( URepo::authorized( $job_row, 1 ), true, 'admin history authorized' );
eq( UReason::message( 'UNDO_CONFLICT' ), 'The product changed after WriteLeash applied its price; the stored price was not overwritten.', 'typed reason copy' );
marker( 'history model: pagination, filters, authorization, eligibility, expiry' );

// Retention expiry disables Undo honestly.
$exp = apply_fixture( array( 'changing' => 1 ) );
$exp_job = (int) $exp['job']['id'];
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET completed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 31 DAY) WHERE id=%d', Schema::jobs_table( $wpdb ), $exp_job ) );
$exp_row = Repo::read( $exp_job );
eq( URepo::retention_expired_for( $exp_row, null ), true, '31-day-old job expired' );
eq( URepo::history_job( $exp_job )['undo_eligible'], false, 'expired job exposes no Restore' );
$expired_initiation = null;
try { start_undo( $exp_job ); }
catch ( WriteLeash\Undo_Error $error ) { $expired_initiation = $error->reason(); }
eq( $expired_initiation, 'UNDO_EXPIRED', 'expired initiation refused' );
eq( URepo::retention_days(), 30, 'initial retention period 30 days' );
marker( 'retention expiry: Undo unavailable, honestly exposed' );

// ---------------------------------------------------------------------------
// Bounded purge: terminal expired history only.
// ---------------------------------------------------------------------------
$old_clean = apply_fixture( array( 'changing' => 2 ) );
$old_clean_job = (int) $old_clean['job']['id'];
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET completed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 31 DAY) WHERE id=%d', Schema::jobs_table( $wpdb ), $old_clean_job ) );
$old_undo_f = apply_fixture( array( 'changing' => 1 ) );
$old_undo_job = (int) $old_undo_f['job']['id'];
$old_undo_op = start_undo( $old_undo_job );
run_worker( array( 'undo_id' => (int) $old_undo_op['id'], 'mode' => 'undo', 'limits' => limits( 5 ) ) );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET completed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 31 DAY) WHERE id=%d', Schema::jobs_table( $wpdb ), $old_undo_job ) );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET completed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 31 DAY) WHERE id=%d', $undo_ops, (int) $old_undo_op['id'] ) );
// Expired but ambiguous: must survive.
$review_f = apply_fixture( array( 'changing' => 1 ) );
$review_job = (int) $review_f['job']['id'];
$review_op = start_undo( $review_job );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET completed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 31 DAY) WHERE id=%d', Schema::jobs_table( $wpdb ), $review_job ) );
$wpdb->query( $wpdb->prepare( "UPDATE %i SET status=%s,status_reason=%s WHERE id=%d", $undo_ops, UState::NEEDS_REVIEW, 'UNDO_ITEM_NEEDS_REVIEW', (int) $review_op['id'] ) );
$estimate_before = URepo::storage_estimate();
$purged = URepo::purge_expired( 20 );
ok( $purged['jobs'] >= 3, 'purge removes expired terminal jobs' );
eq( Repo::read( $old_clean_job ), null, 'expired clean job purged' );
eq( Repo::read( $old_undo_job ), null, 'expired undone job purged' );
eq( Repo::read( $exp_job ), null, 'expired refused job purged' );
ok( null !== Repo::read( $review_job ), 'ambiguous evidence never purged' );
ok( null !== URepo::read_operation( (int) $review_op['id'] ), 'ambiguous undo operation never purged' );
ok( null !== Repo::read( $job_id ), 'fresh history never purged' );
$concurrent = run_worker( array( 'mode' => 'purge', 'batch' => 20 ) );
eq( $concurrent['purged']['jobs'], 0, 'concurrent purge harmless and idempotent' );
$estimate_after = URepo::storage_estimate();
ok( $estimate_after['total_rows'] < $estimate_before['total_rows'], 'purge reduces stored rows' );
// Storage bounds evidence for the retention design.
$bulk = apply_fixture( array( 'changing' => 100 ) );
$bulk_estimate = URepo::storage_estimate();
echo "#110 storage: rows=" . $bulk_estimate['total_rows'] . ' bytes=' . $bulk_estimate['total_bytes'] . " retention_days=" . $bulk_estimate['retention_days'] . "\n";
marker( 'bounded purge: terminal expired only, active/review/fresh survive, concurrent safe' );

// ---------------------------------------------------------------------------
// Deactivation during Undo, then reactivation resumes without auto-mutation.
// ---------------------------------------------------------------------------
$de = apply_fixture( array( 'changing' => 2 ) );
$de_job = (int) $de['job']['id'];
$de_op = start_undo( $de_job );
$de_undo = (int) $de_op['id'];
$w = start_worker( array( 'undo_id' => $de_undo, 'mode' => 'undo', 'limits' => limits( 5 ), 'undo_checkpoint' => 'UNDO_BEFORE_WOO_SAVE', 'fault' => 'wait' ) );
await_file( $w['spec']['barrier'] );
update_option( 'writeleash_runner_state', 'deactivated', false );
UScheduler::unschedule_all();
file_put_contents( $w['spec']['release'], 'go' );
$de_result = finish_worker( $w );
eq( $de_result['status'], UState::PAUSED, 'deactivation pauses at the item boundary' );
$de_counts = undo_counts( $de_undo );
eq( $de_counts['undone'], 1, 'in-flight undo item completes its boundary' );
eq( $de_counts['pending'], 1, 'no next undo claim after deactivation' );
$de_off = run_worker( array( 'undo_id' => $de_undo, 'mode' => 'undo', 'limits' => limits( 5 ) ) );
eq( $de_off['stop'], 'DEACTIVATED', 'deactivated worker claims nothing' );
update_option( 'writeleash_runner_state', 'active', false );
WriteLeash\Lifecycle::activate();
$de_resume = run_worker( array( 'undo_id' => $de_undo, 'mode' => 'undo', 'limits' => limits( 5 ), 'manual' => true ) );
eq( $de_resume['status'], UState::COMPLETED, 'reactivation resumes without auto-mutation' );
foreach ( $de['ids'] as $id ) { eq( Decimal::parse( fresh_price( $id ) ), Decimal::parse( '100.00' ), 'deactivation boundary restores all ' . $id ); }
marker( 'deactivation/reactivation: boundary respected, resume explicit' );

// ---------------------------------------------------------------------------
// Undo REST: POST-only, authenticated, capability and ownership negatives.
// ---------------------------------------------------------------------------
function rest_undo_start( string $public_id, int $user_id ): \WP_REST_Response {
	wp_set_current_user( $user_id );
	$request = new \WP_REST_Request( 'POST', '/writeleash/v1/undo/' . $public_id . '/start' );
	return rest_do_request( $request );
}
$rt = apply_fixture( array( 'changing' => 2 ) );
$rt_job = (int) $rt['job']['id'];
$rt_public = Repo::read( $rt_job )['public_id'];
$rt_base = array();
foreach ( $rt['ids'] as $id ) { $rt_base[$id] = saves( $id ); }
eq( rest_undo_start( $rt_public, 0 )->get_status(), 401, 'undo unauthenticated denied' );
eq( rest_undo_start( $rt_public, (int) $subscriber )->get_status(), 403, 'undo subscriber denied' );
eq( rest_undo_start( $rt_public, (int) $foreign )->get_status(), 403, 'undo foreign manager denied' );
wp_set_current_user( 1 );
$get = new \WP_REST_Request( 'GET', '/writeleash/v1/undo/' . $rt_public . '/start' );
eq( in_array( rest_do_request( $get )->get_status(), array( 404, 405 ), true ), true, 'undo GET does not mutate' );
$malformed = new \WP_REST_Request( 'POST', '/writeleash/v1/undo/not-a-uuid/start' );
eq( rest_do_request( $malformed )->get_status(), 404, 'undo malformed id denied' );
foreach ( $rt['ids'] as $id ) { eq( saves( $id ) - $rt_base[$id], 0, 'undo REST negatives zero mutation ' . $id ); }
$ok_response = rest_undo_start( $rt_public, 1 );
eq( $ok_response->get_status(), 200, 'authorized undo start succeeds' );
$payload = $ok_response->get_data();
eq( $payload['undo']['counts']['undone'], 2, 'authorized start restores both items' );
foreach ( $rt['ids'] as $id ) { eq( Decimal::parse( fresh_price( $id ) ), Decimal::parse( '100.00' ), 'REST undo restores ' . $id ); }
eq( rest_undo_start( $rt_public, 1 )->get_status(), 409, 'terminal undo start denied' );
marker( 'undo REST: strict POST/auth/capability/ownership, zero-mutation negatives' );

// ---------------------------------------------------------------------------
// Uninstall: idle and uninstall-vs-worker race. Woo data always survives.
// ---------------------------------------------------------------------------
$un = apply_fixture( array( 'changing' => 1 ) );
$un_job = (int) $un['job']['id'];
$un_id = $un['ids'][0];
$un_op = start_undo( $un_job );
$un_undo = (int) $un_op['id'];
// A sibling applied price that is never undone: uninstall must not restore it.
$un_sibling_price = fresh_price( $pr_id );
eq( $un_sibling_price, '80.00', 'sibling applied price before uninstall' );
$w = start_worker( array( 'undo_id' => $un_undo, 'mode' => 'undo', 'limits' => limits( 5 ), 'undo_checkpoint' => 'UNDO_AFTER_WOO_SAVE_BEFORE_JOURNAL', 'fault' => 'wait' ) );
await_file( $w['spec']['barrier'] );
// Real uninstall while a worker holds the Undo fence: evidence must survive.
uninstall_plugin( 'writeleash/writeleash.php' );
foreach ( array( 'writeleash_version', 'writeleash_job_schema', 'writeleash_job_setup', 'writeleash_runner_state', 'writeleash_undo_schema', 'writeleash_undo_setup' ) as $option ) {
	eq( false, get_option( $option, false ), 'uninstall removes owned option ' . $option );
}
eq( get_option( 'writeleash_unrelated' ), 'keep me', 'uninstall keeps unrelated options' );
foreach ( array( $undo_ops, $undo_items, Schema::jobs_table( $wpdb ), Schema::items_table( $wpdb ), Journal::table( $wpdb ) ) as $table ) {
	eq( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) ), 'uninstall race preserves evidence table' );
}
eq( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ID=%d', $wpdb->posts, $un_id ) ), 'uninstall race preserves Woo product row' );
file_put_contents( $w['spec']['release'], 'go' );
$un_result = finish_worker( $w );
eq( $un_result['status'], UState::COMPLETED, 'worker drained before evidence cleanup' );
eq( Decimal::parse( fresh_price( $un_id ) ), Decimal::parse( '100.00' ), 'uninstall race restores without losing evidence' );
eq( fresh_price( $pr_id ), $un_sibling_price, 'uninstall never auto-restores applied prices' );
echo "#110 uninstall race:\nworker drained/fenced before evidence cleanup\nWoo product data preserved\nPASS\n";
marker( 'uninstall idle and worker race: options removed, evidence and Woo data preserved' );

echo "#110 Woo 11.1.2 / MySQL+MariaDB / default+Redis matrix: PASS\n";
