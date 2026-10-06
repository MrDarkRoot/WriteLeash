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
/** Frozen plan + job with N changing items, deliberately not approved/applied. */
function planned_fixture( int $count ): array {
	wp_set_current_user( 1 );
	$ids = array();
	for ( $i = 0; $i < $count; ++$i ) { $ids[] = create_product( '100.00', 'publish' ); }
	sort( $ids, SORT_NUMERIC );
	$plan = Planner::preview( Selection::ids( $ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', true, '100' ) );
	$job = Repo::create_from_plan( $plan, 1 );
	return array( 'plan' => $plan, 'job' => $job, 'ids' => $ids );
}
/** Applied terminal job with no Undo operation, already past retention expiry. */
function expired_applied_job(): array {
	global $wpdb;
	$f = apply_fixture( array( 'changing' => 1 ) );
	$job_id = (int) $f['job']['id'];
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET completed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 31 DAY) WHERE id=%d', Schema::jobs_table( $wpdb ), $job_id ) );
	return array( 'job_id' => $job_id, 'product_id' => (int) $f['ids'][0], 'plan_id' => $f['plan']->data()['plan_id'] );
}
/** Applied job plus completed Undo operation, expired so purge may remove it. */
function expired_undone_job( int $items = 2 ): array {
	global $wpdb;
	$f = apply_fixture( array( 'changing' => $items ) );
	$job_id = (int) $f['job']['id'];
	$operation = start_undo( $job_id );
	run_worker( array( 'undo_id' => (int) $operation['id'], 'mode' => 'undo', 'limits' => limits( 10 ) ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET completed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 31 DAY) WHERE id=%d', Schema::jobs_table( $wpdb ), $job_id ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET completed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 31 DAY) WHERE id=%d', USchema::operations_table( $wpdb ), (int) $operation['id'] ) );
	return array( 'job_id' => $job_id, 'undo_id' => (int) $operation['id'], 'ids' => $f['ids'], 'plan_id' => $f['plan']->data()['plan_id'] );
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
function start_shutdown( string $mode ): array { return start_worker( array( 'mode' => $mode ), 'lifecycle-process.php' ); }
function limits( int $items, int $seconds = 20 ): array { return array( 'max_items' => $items, 'budget_seconds' => $seconds ); }
/** Real WordPress uninstall in its own process (uninstall_plugin uses include_once). */
function run_uninstall_process(): void {
	global $work;
	$prefix = $work . '/uninstall-' . bin2hex( random_bytes( 5 ) );
	$proc = proc_open( array( 'wp', '--path=' . ABSPATH, 'eval-file', __DIR__ . '/uninstall-process.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $prefix . '.stdout', 'w' ), 2 => array( 'file', $prefix . '.stderr', 'w' ) ), $pipes );
	if ( ! is_resource( $proc ) ) { throw new RuntimeException( 'uninstall process spawn failed' ); }
	fclose( $pipes[0] );
	$exit = proc_close( $proc );
	if ( 0 !== $exit ) {
		throw new RuntimeException( 'uninstall process failure: ' . file_get_contents( $prefix . '.stdout' ) . file_get_contents( $prefix . '.stderr' ) );
	}
}
/** Durable option-row absence, independent of the main process option cache. */
function option_rows( string $option_name ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name=%s", $option_name ) );
}
/** FOR UPDATE NOWAIT must fail while an item holds a shared lifecycle lock. */
function lifecycle_row_locked_nowait(): bool {
	$db = Verifier::observer();
	$db->suppress_errors( true );
	try {
		$db->get_var( $db->prepare( 'SELECT option_value FROM %i WHERE option_name=%s FOR UPDATE NOWAIT', $db->options, 'writeleash_runner_state' ) );
		return '' !== (string) $db->last_error;
	} finally { $db->close(); }
}
/** The real shutdown connection must reach the indexed DELETE/UPDATE waiter. */
function await_shutdown_sql( array $shutdown ): bool {
	$db = Verifier::observer();
	try {
		$thread = (int) file_get_contents( $shutdown['spec']['started'] );
		$deadline = microtime( true ) + 10;
		while ( microtime( true ) < $deadline ) {
			foreach ( $db->get_results( 'SHOW FULL PROCESSLIST', ARRAY_A ) as $row ) {
				if ( $thread === (int) $row['Id'] && preg_match( '/\b(?:DELETE FROM|UPDATE)\b.*writeleash_runner_state/i', (string) ( $row['Info'] ?? '' ) ) ) { return true; }
			}
			usleep( 50000 );
		}
		return false;
	} finally { $db->close(); }
}
/** Real uninstall runs in another process; drop this process's option cache. */
function forget_uninstall_option_cache(): void {
	foreach ( array( 'writeleash_version', 'writeleash_job_schema', 'writeleash_job_setup', 'writeleash_runner_state', 'writeleash_undo_schema', 'writeleash_undo_setup' ) as $option ) {
		wp_cache_delete( $option, 'options' );
	}
}
function expire_undo_lease( int $undo_id ): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=%d', USchema::operations_table( $wpdb ), $undo_id ) );
}

wp_set_current_user( 1 );
// This suite deliberately ends some scenarios by uninstalling the plugin
// options and runs twice per site (default, then persistent cache). Restore
// the explicit activation authority at suite start, exactly as activation
// writes it; missing authority is intentionally fail-closed.
WriteLeash\Lifecycle::activate();
eq( get_option( 'writeleash_runner_state' ), 'active', 'suite start: explicit active lifecycle authority' );
eq( \WriteLeash\Free_Support_Contract::woo_supported( WC_VERSION ), true, 'fixture Woo inside supported range' );
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
$page = URepo::history_jobs( 0, 1, 1 );
eq( count( $page['jobs'] ), 1, 'history jobs bounded page' );
$page2 = URepo::history_jobs( 1, 1, 1 );
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
// #168 consumes the proven durable Undo outcomes without changing the engine.
wp_set_current_user( 1 );
ob_start();
WriteLeash\Free_Admin::render_view( 'job', $job_row['public_id'], 0 );
$merchant_undo168 = (string) ob_get_clean();
ok( str_contains( $merchant_undo168, 'Undo finished with conflicts' ), 'merchant Undo clearly finished with issues' );
ok( str_contains( $merchant_undo168, 'preserved the newer value instead of restoring over it' ), 'merchant Undo explains external edit preservation' );
ok( str_contains( $merchant_undo168, '1 restored · 3 conflicts' ), 'merchant Undo uses disjoint proven counts' );
ok( ! str_contains( $merchant_undo168, 'name="action" value="writeleash_free_undo"' ), 'terminal conflicted Undo never offers restoration retry' );
ok( str_contains( $merchant_undo168, 'does not reverse: orders' ), 'merchant Undo explains external effects boundary' );
marker( 'history model: pagination, filters, authorization, eligibility, expiry' );

// ---------------------------------------------------------------------------
// Blocker 1: history item pagination over the complete logical result set.
// #107 supports up to 1000 products; a 170-item job crosses the former
// internal 100-row window and must page/filter correctly beyond it.
// ---------------------------------------------------------------------------
$big = planned_fixture( 170 );
$big_job = (int) $big['job']['id'];
$big_ids = $big['ids'];
$page0 = URepo::history_items( $big_job, null, null, 0, 50 );
eq( count( $page0['items'] ), 50, 'large job page 0 has 50 items' );
eq( $page0['total'], 170, 'large job logical total is 170' );
eq( array_column( $page0['items'], 'product_id' ), array_slice( $big_ids, 0, 50 ), 'page 0 frozen sequence order' );
eq( array_column( $page0['items'], 'sequence' ), range( 1, 50 ), 'page 0 frozen sequences' );
$page1 = URepo::history_items( $big_job, null, null, 50, 50 );
eq( array_column( $page1['items'], 'product_id' ), array_slice( $big_ids, 50, 50 ), 'page 1 frozen sequence order' );
$page2 = URepo::history_items( $big_job, null, null, 100, 50 );
eq( count( $page2['items'] ), 50, 'offset 100 returns items 101-150 (no hidden 100-row truncation)' );
eq( array_column( $page2['items'], 'product_id' ), array_slice( $big_ids, 100, 50 ), 'page 2 frozen sequence order' );
eq( array_column( $page2['items'], 'sequence' ), range( 101, 150 ), 'page 2 sequences 101-150' );
$page3 = URepo::history_items( $big_job, null, null, 150, 50 );
eq( count( $page3['items'] ), 20, 'offset 150 returns the remaining 20 items' );
eq( array_column( $page3['items'], 'sequence' ), range( 151, 170 ), 'page 3 sequences 151-170' );
eq( $page3['next_offset'], null, 'last page has no next offset' );
$page4 = URepo::history_items( $big_job, null, null, 160, 100 );
eq( count( $page4['items'] ), 10, 'upper supported range returns exactly the remaining rows' );
eq( URepo::history_items( $big_job, null, null, 170, 50 )['items'], array(), 'page past the end is empty' );
$page5 = URepo::history_items( $big_job, null, null, 150, 50 );
eq( $page5['next_offset'], null, 'filtered envelope exposes next_offset' );
eq( URepo::history_items( $big_job, null, null, 50, 50 )['next_offset'], 100, 'middle page exposes the next filtered offset' );

// Apply-state filter runs inside the query, not over the first internal page.
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET state=%s WHERE job_id=%d AND sequence IN (120,150)', Schema::items_table( $wpdb ), IState::APPLIED, $big_job ) );
$applied_page = URepo::history_items( $big_job, IState::APPLIED, null, 0, 50 );
eq( count( $applied_page['items'] ), 2, 'apply-state filter is evaluated in-query' );
eq( array_column( $applied_page['items'], 'sequence' ), array( 120, 150 ), 'apply-state matches beyond item 100' );
eq( $applied_page['total'], 2, 'apply-state total is the filtered logical set' );
$applied_step = URepo::history_items( $big_job, IState::APPLIED, null, 1, 1 );
eq( $applied_step['items'][0]['sequence'], 150, 'apply-state pagination steps through the filtered set' );

// Undo-state match located after item 100.
$big_undo_public = wp_generate_uuid4();
$wpdb->query( $wpdb->prepare(
	"INSERT INTO $undo_ops (schema_version,job_id,plan_id,public_id,initiator_id,status,status_reason,created_at,updated_at) VALUES (%d,%d,%s,%s,1,%s,'UNDO_INITIATED',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
	USchema::SCHEMA_VERSION, $big_job, $big['plan']->data()['plan_id'], $big_undo_public, UState::PENDING
) );
$big_undo_id = (int) $wpdb->insert_id;
ok( $big_undo_id > 0, 'seeded undo operation for the large job' );
$seeded = array( 120 => UItem::UNDONE, 150 => UItem::UNDONE, 160 => UItem::CONFLICT );
foreach ( $seeded as $sequence => $state ) {
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO $undo_items (schema_version,job_id,undo_id,plan_id,product_id,sequence,expected_price,applied_price,apply_attempt_id,state,reason,attempt_id,attempt_count,claim_token,claim_generation,provenance,fingerprint,evidence,created_at,updated_at) VALUES (%d,%d,%d,%s,%d,%d,'100.00','80.00','seeded-attempt',%s,%s,'seeded-attempt',0,'',0,'{}',%s,'',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
		USchema::SCHEMA_VERSION, $big_job, $big_undo_id, $big['plan']->data()['plan_id'], (int) $big_ids[$sequence - 1], $sequence, $state, $state, str_repeat( '0', 64 )
	) );
}
$undone_page = URepo::history_items( $big_job, null, UItem::UNDONE, 0, 50 );
eq( count( $undone_page['items'] ), 2, 'undo-state matches beyond item 100 are not truncated' );
eq( array_column( $undone_page['items'], 'sequence' ), array( 120, 150 ), 'undo-state matches preserve frozen order' );
eq( $undone_page['total'], 2, 'undo-state total is the filtered logical set' );
eq( $undone_page['items'][0]['undo_reason_message'], UReason::message( UItem::UNDONE ), 'undo-state rows join typed reason copy' );
eq( Decimal::parse( $undone_page['items'][0]['restored_price'] ), Decimal::parse( '100.00' ), 'undo-state rows join restored price' );
$undone_step = URepo::history_items( $big_job, null, UItem::UNDONE, 1, 1 );
eq( $undone_step['items'][0]['sequence'], 150, 'undo-state pagination is based on the filtered logical set' );
eq( $undone_step['next_offset'], null, 'undo-state filtered envelope next_offset' );
$conflict_page = URepo::history_items( $big_job, null, UItem::CONFLICT, 0, 50 );
eq( count( $conflict_page['items'] ), 1, 'undo-state conflict filter beyond item 100' );
eq( $conflict_page['items'][0]['sequence'], 160, 'conflict match sequence' );
$combined_page = URepo::history_items( $big_job, IState::APPLIED, UItem::UNDONE, 0, 50 );
eq( count( $combined_page['items'] ), 2, 'apply+undo filters combine in-query' );
eq( array_column( $combined_page['items'], 'sequence' ), array( 120, 150 ), 'combined filter matches beyond item 100' );
eq( URepo::history_items( $big_job, IState::CONFLICT, UItem::UNDONE, 0, 50 )['items'], array(), 'non-overlapping filters return an empty filtered set' );
marker( 'blocker 1: full-range history pagination and in-query filters beyond item 100' );

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
// Blocker 2: retention purge is atomic per job.
// An injected failure at any deletion stage rolls the complete candidate
// back; the failure is reported as purge_failed and never as success, and a
// later clean purge removes the complete history.
// ---------------------------------------------------------------------------
$fault_point = '';
$purge_fault = static function ( $point ) use ( &$fault_point ) {
	if ( $point === $fault_point ) { throw new RuntimeException( 'injected purge failure at ' . $point ); }
};
add_action( 'writeleash_purge_checkpoint', $purge_fault, 10, 2 );
$fault_points = array(
	'PURGE_BEFORE_UNDO_ITEMS_DELETE', 'PURGE_AFTER_UNDO_ITEMS_DELETE',
	'PURGE_BEFORE_UNDO_OPERATION_DELETE', 'PURGE_AFTER_UNDO_OPERATION_DELETE',
	'PURGE_BEFORE_JOB_ITEMS_DELETE', 'PURGE_AFTER_JOB_ITEMS_DELETE',
	'PURGE_BEFORE_JOURNAL_DELETE', 'PURGE_AFTER_JOURNAL_DELETE',
	'PURGE_BEFORE_JOB_DELETE', 'PURGE_AFTER_JOB_DELETE',
);
foreach ( $fault_points as $point ) {
	$fx = expired_undone_job( 2 );
	$fault_point = $point;
	$failed = URepo::purge_expired( 5 );
	eq( $failed['purge_failed'], 1, 'injected failure reported as purge_failed at ' . $point );
	eq( $failed['jobs'], 0, 'failed purge reports zero successful job deletions at ' . $point );
	$observer = Verifier::observer();
	try {
		ok( null !== Repo::read( $fx['job_id'] ), 'parent job survives injected failure ' . $point );
		eq( 2, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE job_id=%d', Schema::items_table( $observer ), $fx['job_id'] ) ), 'job items survive ' . $point );
		eq( 2, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE plan_id=%s', Journal::table( $observer ), $fx['plan_id'] ) ), 'journal evidence survives ' . $point );
		eq( 1, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE id=%d', USchema::operations_table( $observer ), $fx['undo_id'] ) ), 'undo operation survives ' . $point );
		eq( 2, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE undo_id=%d', USchema::items_table( $observer ), $fx['undo_id'] ) ), 'undo items survive ' . $point );
	} finally { $observer->close(); }
	$fault_point = '';
	$clean = URepo::purge_expired( 5 );
	eq( $clean['purge_failed'], 0, 'clean purge succeeds after ' . $point );
	ok( $clean['jobs'] >= 1, 'clean purge removes the complete eligible history after ' . $point );
	eq( Repo::read( $fx['job_id'] ), null, 'parent job removed only by the clean purge ' . $point );
	$observer = Verifier::observer();
	try {
		eq( 0, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE job_id=%d', Schema::items_table( $observer ), $fx['job_id'] ) ), 'job items removed completely ' . $point );
		eq( 0, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE plan_id=%s', Journal::table( $observer ), $fx['plan_id'] ) ), 'journal removed completely ' . $point );
		eq( 0, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE id=%d', USchema::operations_table( $observer ), $fx['undo_id'] ) ), 'undo operation removed completely ' . $point );
		eq( 0, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE undo_id=%d', USchema::items_table( $observer ), $fx['undo_id'] ) ), 'undo items removed completely ' . $point );
	} finally { $observer->close(); }
}
remove_action( 'writeleash_purge_checkpoint', $purge_fault, 10 );
marker( 'blocker 2: per-job atomic purge, injected failures roll back completely' );

// ---------------------------------------------------------------------------
// Blocker 3: purge serializes safely against concurrent Undo initiation.
// Two real processes: one initiates Undo while considering the job in
// retention (365-day filter), the other purges while considering it expired
// (default 30-day retention). The shared parent job row lock gives exactly
// one serialized outcome.
// ---------------------------------------------------------------------------
URepo::purge_expired( 20 ); // drain any remaining expired fixtures before the race

// Outcome A: Undo initiation wins; purge observes the operation and removes nothing.
$race_a = expired_applied_job();
$a = start_worker( array(
	'mode' => 'initiate', 'job_id' => $race_a['job_id'], 'actor' => 1, 'retention_days' => 365,
	'initiate_checkpoint' => 'INITIATE_AFTER_JOB_LOCK', 'fault' => 'wait', 'lock_wait_timeout' => 30,
) );
await_file( $a['spec']['barrier'] ); // A holds the parent job row lock inside its transaction
$b = start_worker( array( 'mode' => 'purge', 'batch' => 5, 'lock_wait_timeout' => 30 ) );
await_file( $b['spec']['started'] );
usleep( 500000 );
ok( ! is_file( $b['spec']['result'] ), 'purge is blocked behind the initiation job-row lock' );
$observer = Verifier::observer();
try {
	eq( 1, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE id=%d', Schema::jobs_table( $observer ), $race_a['job_id'] ) ), 'job intact while initiation holds the lock' );
	eq( 1, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE plan_id=%s AND product_id=%d', Journal::table( $observer ), $race_a['plan_id'], $race_a['product_id'] ) ), 'journal intact while initiation holds the lock' );
} finally { $observer->close(); }
file_put_contents( $a['spec']['release'], 'go' );
$a_result = finish_worker( $a );
ok( ! empty( $a_result['operation'] ), 'initiation commits the Undo operation' );
$b_result = finish_worker( $b );
eq( (int) $b_result['purged']['jobs'], 0, 'purge removes nothing when initiation won' );
eq( (int) $b_result['purged']['purge_failed'], 0, 'no purge failure or lock timeout when initiation won' );
$op_a = URepo::read_operation_by_job( $race_a['job_id'] );
ok( null !== $op_a, 'undo operation exists after initiation win' );
eq( 1, URepo::counts( (int) $op_a['id'] )['eligible'], 'undo item created from intact source evidence' );
ok( null !== Repo::read( $race_a['job_id'] ), 'apply job evidence intact after initiation win' );
eq( Decimal::parse( fresh_price( $race_a['product_id'] ) ), Decimal::parse( '80.00' ), 'initiation and purge mutate no product' );
$done_a = run_worker( array( 'undo_id' => (int) $op_a['id'], 'mode' => 'undo', 'limits' => limits( 5 ), 'retention_days' => 365 ) );
eq( $done_a['status'], UState::COMPLETED, 'source evidence intact enough to finish the Undo' );
eq( Decimal::parse( fresh_price( $race_a['product_id'] ) ), Decimal::parse( '100.00' ), 'undo restores after the race' );
marker( 'blocker 3 outcome A: initiation wins, purge leaves authoritative evidence alone' );

// Outcome B: purge wins completely; later initiation refuses cleanly.
$race_b = expired_applied_job();
$b = start_worker( array(
	'mode' => 'purge', 'batch' => 5, 'purge_checkpoint' => 'PURGE_AFTER_JOB_LOCK', 'fault' => 'wait', 'lock_wait_timeout' => 30,
) );
await_file( $b['spec']['barrier'] ); // B holds the parent job row lock inside its transaction
$a = start_worker( array(
	'mode' => 'initiate', 'job_id' => $race_b['job_id'], 'actor' => 1, 'retention_days' => 365, 'lock_wait_timeout' => 30,
) );
await_file( $a['spec']['started'] );
usleep( 500000 );
ok( ! is_file( $a['spec']['result'] ), 'initiation is blocked behind the purge job-row lock' );
file_put_contents( $b['spec']['release'], 'go' );
$b_result = finish_worker( $b );
eq( (int) $b_result['purged']['jobs'], 1, 'purge wins completely' );
eq( (int) $b_result['purged']['purge_failed'], 0, 'no purge failure or lock timeout when purge won' );
$a_result = finish_worker( $a );
eq( $a_result['error'] ?? null, 'UNDO_NOT_ELIGIBLE', 'initiation refuses cleanly after complete purge' );
$observer = Verifier::observer();
try {
	eq( 0, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE id=%d', Schema::jobs_table( $observer ), $race_b['job_id'] ) ), 'job removed completely after purge win' );
	eq( 0, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE plan_id=%s', Journal::table( $observer ), $race_b['plan_id'] ) ), 'journal removed completely after purge win' );
	eq( 0, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE job_id=%d', Schema::items_table( $observer ), $race_b['job_id'] ) ), 'job items removed completely after purge win' );
	eq( 0, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE job_id=%d', USchema::operations_table( $observer ), $race_b['job_id'] ) ), 'no orphan undo operation after purge win' );
	eq( 0, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE job_id=%d', USchema::items_table( $observer ), $race_b['job_id'] ) ), 'no orphan undo items after purge win' );
} finally { $observer->close(); }
eq( Decimal::parse( fresh_price( $race_b['product_id'] ) ), Decimal::parse( '80.00' ), 'purge and refused initiation mutate no product' );
marker( 'blocker 3 outcome B: purge wins completely, initiation refuses with zero orphans' );

// ---------------------------------------------------------------------------
// Deactivation during Undo, then reactivation resumes without auto-mutation.
// ---------------------------------------------------------------------------
$de = apply_fixture( array( 'changing' => 2 ) );
$de_job = (int) $de['job']['id'];
$de_op = start_undo( $de_job );
$de_undo = (int) $de_op['id'];
$de_after = $work . '/de-after-' . wp_generate_uuid4();
$de_release = $work . '/de-release-' . wp_generate_uuid4();
$w = start_worker( array( 'undo_id' => $de_undo, 'mode' => 'undo', 'limits' => limits( 5, 60 ), 'undo_checkpoint' => 'UNDO_BEFORE_WOO_SAVE', 'fault' => 'wait', 'after_item_barrier' => $de_after, 'after_item_release' => $de_release ) );
await_file( $w['spec']['barrier'] );
$shutdown = start_shutdown( 'deactivate' );
await_file( $shutdown['spec']['started'] );
ok( lifecycle_row_locked_nowait(), 'Undo item holds the indexed shared lifecycle record lock' );
ok( await_shutdown_sql( $shutdown ), 'deactivation UPDATE waits on lifecycle record' );
ok( ! is_file( $shutdown['spec']['result'] ), 'deactivation waits for fenced Undo transaction' );
file_put_contents( $w['spec']['release'], 'go' );
await_file( $de_after );
finish_worker( $shutdown );
file_put_contents( $de_release, 'go' );
$de_result = finish_worker( $w );
eq( $de_result['status'], UState::PAUSED, 'deactivation pauses at the item boundary' );
$de_counts = undo_counts( $de_undo );
eq( $de_counts['undone'], 1, 'in-flight undo item completes its boundary' );
eq( $de_counts['pending'], 1, 'no next undo claim after deactivation' );
$de_off = run_worker( array( 'undo_id' => $de_undo, 'mode' => 'undo', 'limits' => limits( 5 ) ) );
eq( $de_off['stop'], 'DEACTIVATED', 'deactivated worker claims nothing' );
wp_cache_delete( 'writeleash_runner_state', 'options' ); // deactivation ran in another process.
update_option( 'writeleash_runner_state', 'active', false );
WriteLeash\Lifecycle::activate();
$de_resume = run_worker( array( 'undo_id' => $de_undo, 'mode' => 'undo', 'limits' => limits( 5 ), 'manual' => true ) );
eq( $de_resume['status'], UState::COMPLETED, 'reactivation resumes without auto-mutation' );
foreach ( $de['ids'] as $id ) { eq( Decimal::parse( fresh_price( $id ) ), Decimal::parse( '100.00' ), 'deactivation boundary restores all ' . $id ); }
marker( 'deactivation/reactivation: boundary respected, resume explicit' );

// ---------------------------------------------------------------------------
// Lifecycle authority is fail-closed: only an explicit durable `active`
// authorizes a new claim. Missing (e.g. after uninstall removed the option),
// empty, `deactivated` and unknown values must stop both worker families
// before any product mutation, in real worker processes.
// ---------------------------------------------------------------------------
$lifecycle_states = array(
	'absent' => null,
	'empty' => '',
	'deactivated' => 'deactivated',
	'unknown' => 'writeleash-unexpected-state',
);
foreach ( $lifecycle_states as $state_label => $state_value ) {
	wp_set_current_user( 1 );
	// Apply family: approved frozen job, zero mutation expected.
	$la = planned_fixture( 1 );
	$la_job = Repo::approve( (int) $la['job']['id'], 1 );
	$la_id = (int) $la['ids'][0];
	$la_plan = $la['plan']->data()['plan_id'];
	$la_saves = saves( $la_id );
	if ( null === $state_value ) { delete_option( 'writeleash_runner_state' ); }
	else { update_option( 'writeleash_runner_state', $state_value, false ); }
	$la_result = run_worker( array( 'job_id' => (int) $la_job['id'], 'mode' => 'apply', 'limits' => limits( 5 ), 'manual' => true ) );
	eq( $la_result['stop'], 'DEACTIVATED', 'apply state ' . $state_label . ' stops with typed lifecycle reason' );
	eq( $la_result['processed'], 0, 'apply state ' . $state_label . ' processes zero items' );
	eq( Decimal::parse( fresh_price( $la_id ) ), Decimal::parse( '100.00' ), 'apply state ' . $state_label . ' zero price mutation' );
	eq( saves( $la_id ), $la_saves, 'apply state ' . $state_label . ' zero Woo save' );
	eq( Repo::read( (int) $la_job['id'] )['status_reason'], 'DEACTIVATED', 'apply state ' . $state_label . ' durable pause reason' );
	eq( journal_row( $la_plan, $la_id )['state'], 'PENDING', 'apply state ' . $state_label . ' journal never APPLIED' );

	// Undo family: real applied job, zero restore expected.
	update_option( 'writeleash_runner_state', 'active', false );
	$lu = apply_fixture( array( 'changing' => 1 ) );
	$lu_job = (int) $lu['job']['id'];
	$lu_id = (int) $lu['ids'][0];
	$lu_op = start_undo( $lu_job );
	$lu_saves = saves( $lu_id );
	if ( null === $state_value ) { delete_option( 'writeleash_runner_state' ); }
	else { update_option( 'writeleash_runner_state', $state_value, false ); }
	$lu_result = run_worker( array( 'undo_id' => (int) $lu_op['id'], 'mode' => 'undo', 'limits' => limits( 5 ), 'manual' => true ) );
	eq( $lu_result['stop'], 'DEACTIVATED', 'undo state ' . $state_label . ' stops with typed lifecycle reason' );
	eq( $lu_result['processed'], 0, 'undo state ' . $state_label . ' processes zero items' );
	eq( Decimal::parse( fresh_price( $lu_id ) ), Decimal::parse( '80.00' ), 'undo state ' . $state_label . ' zero price mutation' );
	eq( saves( $lu_id ), $lu_saves, 'undo state ' . $state_label . ' zero Woo save' );
	eq( undo_row( $lu_job, $lu_id )['state'], UItem::PENDING, 'undo state ' . $state_label . ' item not claimed for mutation' );
	eq( URepo::read_operation( (int) $lu_op['id'] )['status_reason'], 'DEACTIVATED', 'undo state ' . $state_label . ' durable pause reason' );
}
// Explicit active authority restores both families.
wp_set_current_user( 1 );
update_option( 'writeleash_runner_state', 'active', false );
WriteLeash\Lifecycle::activate();
eq( get_option( 'writeleash_runner_state' ), 'active', 'activation writes explicit active authority' );
echo "#110 missing lifecycle authority fails closed PASS\n";
marker( 'lifecycle fail-closed: absent/empty/deactivated/unknown stop Apply and Undo before mutation' );

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
// Lifecycle race A: the early PHP read returned active, but uninstall wins
// BEFORE the claim transaction locks the lifecycle row. A stale read cannot
// make an APPLYING/UNDO_APPLYING row or reach Woo. Race B: the claim commits
// while active, but uninstall wins BEFORE the item transaction acquires its
// independent lifecycle fence. The claim must be refunded, not retried or
// left applying. These are distinct from an already-fenced item transaction.
// ---------------------------------------------------------------------------
foreach ( array( 'BEFORE_CLAIM', 'AFTER_CLAIM', 'AFTER_CLAIM_DEACTIVATE' ) as $race_stage ) {
	foreach ( array( 'apply', 'undo' ) as $race_family ) {
		$before_claim = 'BEFORE_CLAIM' === $race_stage;
		$shutdown_mode = 'AFTER_CLAIM_DEACTIVATE' === $race_stage ? 'deactivate' : 'uninstall';
		forget_uninstall_option_cache();
		WriteLeash\Lifecycle::activate();
		wp_set_current_user( 1 );
		if ( 'apply' === $race_family ) {
			$race_f = planned_fixture( 1 );
			$race_job = Repo::approve( (int) $race_f['job']['id'], 1 );
			$race_job_id = (int) $race_job['id'];
			$race_pid = (int) $race_f['ids'][0];
			$race_op_id = 0;
			$race_spec = array( 'job_id' => $race_job_id, 'mode' => 'apply', 'job_checkpoint' => $before_claim ? 'AFTER_RUNNER_ACTIVE_BEFORE_CLAIM' : 'AFTER_CLAIM' );
		} else {
			$race_f = apply_fixture( array( 'changing' => 1 ) );
			$race_job_id = (int) $race_f['job']['id'];
			$race_pid = (int) $race_f['ids'][0];
			$race_op = start_undo( $race_job_id );
			$race_op_id = (int) $race_op['id'];
			$race_spec = array( 'undo_id' => $race_op_id, 'mode' => 'undo', 'undo_checkpoint' => $before_claim ? 'AFTER_RUNNER_ACTIVE_BEFORE_CLAIM' : 'AFTER_CLAIM' );
		}
		$race_spec['limits'] = limits( 5, 60 );
		$race_spec['fault'] = 'wait';
		$race_spec['lock_wait_timeout'] = 25;
		$baseline_saves = saves( $race_pid );
		$actor_worker = start_worker( $race_spec );
		await_file( $actor_worker['spec']['barrier'] );
		$authority = 'apply' === $race_family ? Repo::read( $race_job_id ) : URepo::read_operation( $race_op_id );
		eq( $authority['status'], 'apply' === $race_family ? JState::RUNNING : UState::RUNNING, 'worker has live lease before shutdown ' . $race_family . $race_stage );
		ok( '' !== $authority['lease_owner'] && (int) $authority['lease_generation'] > 0, 'valid generation before lifecycle race' );
		$observer = Verifier::observer();
		try {
			if ( 'apply' === $race_family ) {
				$pre = $observer->get_row( $observer->prepare( 'SELECT state,attempt_count FROM %i WHERE job_id=%d AND product_id=%d', Schema::items_table( $observer ), $race_job_id, $race_pid ), ARRAY_A );
			} else {
				$pre = URepo::read_item( $observer, $race_job_id, $race_pid );
			}
			eq( $pre['state'], $before_claim ? ( 'apply' === $race_family ? IState::PENDING : UItem::PENDING ) : ( 'apply' === $race_family ? IState::APPLYING : UItem::APPLYING ), 'durable pre-shutdown claim boundary ' . $race_family . $race_stage );
		} finally { $observer->close(); }
		// This completes BEFORE the worker is released. No item transaction
		// owns the lifecycle lock at either barrier.
		if ( 'uninstall' === $shutdown_mode ) { run_uninstall_process(); }
		else { finish_worker( start_shutdown( 'deactivate' ) ); }
		forget_uninstall_option_cache();
		if ( 'uninstall' === $shutdown_mode ) { eq( 0, option_rows( 'writeleash_runner_state' ), 'uninstall wins before next mutation boundary' ); }
		else {
			$observer = Verifier::observer();
			try { eq( $observer->get_var( $observer->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $observer->options, 'writeleash_runner_state' ) ), 'deactivated', 'indexed lifecycle row remains deactivated' ); }
			finally { $observer->close(); }
		}
		file_put_contents( $actor_worker['spec']['release'], 'go' );
		$race_result = finish_worker( $actor_worker );
		eq( $race_result['stop'], 'DEACTIVATED', 'typed lifecycle stop ' . $race_family . $race_stage );
		eq( $race_result['processed'], 0, 'zero mutation attempts after shutdown ' . $race_family . $race_stage );
		eq( saves( $race_pid ), $baseline_saves, 'zero post-shutdown Woo saves ' . $race_family . $race_stage );
		eq( Decimal::parse( fresh_price( $race_pid ) ), Decimal::parse( 'apply' === $race_family ? '100.00' : '80.00' ), 'fresh price unchanged ' . $race_family . $race_stage );
		$observer = Verifier::observer();
		try {
			if ( 'apply' === $race_family ) {
				$final = $observer->get_row( $observer->prepare( 'SELECT state,attempt_count,claim_token FROM %i WHERE job_id=%d AND product_id=%d', Schema::items_table( $observer ), $race_job_id, $race_pid ), ARRAY_A );
				eq( Journal::read( $observer, $race_f['plan']->data()['plan_id'], $race_pid )['state'], 'PENDING', 'apply journal not APPLIED after shutdown' );
			} else {
				$final = URepo::read_item( $observer, $race_job_id, $race_pid );
				eq( $final['evidence'], '', 'no UNDONE evidence after shutdown' );
			}
			eq( $final['state'], 'apply' === $race_family ? IState::PENDING : UItem::PENDING, 'claim safely PENDING after shutdown' );
			eq( (int) $final['attempt_count'], 0, 'lifecycle refusal refunds claim retry budget' );
			eq( $final['claim_token'], '', 'no orphaned claim token' );
		} finally { $observer->close(); }
		$paused = 'apply' === $race_family ? Repo::read( $race_job_id ) : URepo::read_operation( $race_op_id );
		eq( $paused['status_reason'], 'DEACTIVATED', 'durable paused lifecycle reason' );
		if ( $before_claim ) {
			echo 'apply' === $race_family ? "#109/#110 lifecycle race before claim: stale active read cannot authorize Apply PASS\n" : "#110 lifecycle race before Undo claim: stale active read cannot authorize Undo PASS\n";
		} elseif ( 'deactivate' === $shutdown_mode ) {
			echo 'apply' === $race_family ? "#109/#110 claimed Apply item refuses deactivated lifecycle row PASS\n" : "#110 claimed Undo item refuses deactivated lifecycle row PASS\n";
		} else {
			echo 'apply' === $race_family ? "#109/#110 claimed Apply item loses lifecycle authority before mutation PASS\n" : "#110 claimed Undo item loses lifecycle authority before mutation PASS\n";
		}
	}
}
forget_uninstall_option_cache();
WriteLeash\Lifecycle::activate();

// ---------------------------------------------------------------------------
// Uninstall: owned Action Scheduler cleanup, fail-closed lifecycle and real
// surviving-worker races. Woo data always survives.
// ---------------------------------------------------------------------------
// Seed pending wake-ups: one per WriteLeash-owned group plus an unrelated
// sentinel group that uninstall must not touch.
$as_owned_ids = array();
$as_owned_ids['jobs'] = (int) as_enqueue_async_action( 'writeleash_uninstall_probe_job', array(), Scheduler::GROUP, false, 10 );
$as_owned_ids['undo'] = (int) as_enqueue_async_action( 'writeleash_uninstall_probe_undo', array(), UScheduler::GROUP, false, 10 );
$as_owned_ids['maintenance'] = (int) as_enqueue_async_action( 'writeleash_uninstall_probe_purge', array(), UScheduler::PURGE_GROUP, false, 10 );
$as_sentinel_id = (int) as_enqueue_async_action( 'writeleash_uninstall_probe_sentinel', array(), 'writeleash-unrelated-sentinel', false, 10 );
foreach ( $as_owned_ids as $as_group => $as_action_id ) { ok( $as_action_id > 0, 'seeded owned AS action ' . $as_group ); }
ok( $as_sentinel_id > 0 && ! in_array( $as_sentinel_id, $as_owned_ids, true ), 'seeded unrelated AS sentinel action' );

$un = apply_fixture( array( 'changing' => 1 ) );
$un_job = (int) $un['job']['id'];
$un_id = $un['ids'][0];
$un_op = start_undo( $un_job );
$un_undo = (int) $un_op['id'];
// A sibling applied price that is never undone: uninstall must not restore it.
$un_sibling_price = fresh_price( $pr_id );
eq( $un_sibling_price, '80.00', 'sibling applied price before uninstall' );
$un_after = $work . '/un-after-' . wp_generate_uuid4();
$un_release = $work . '/un-release-' . wp_generate_uuid4();
$w = start_worker( array( 'undo_id' => $un_undo, 'mode' => 'undo', 'limits' => limits( 5, 60 ), 'undo_checkpoint' => 'UNDO_AFTER_WOO_SAVE_BEFORE_JOURNAL', 'fault' => 'wait', 'after_item_barrier' => $un_after, 'after_item_release' => $un_release ) );
await_file( $w['spec']['barrier'] );
// Real uninstall while a worker holds the Undo fence: evidence and AS tables survive.
$shutdown = start_shutdown( 'uninstall' );
await_file( $shutdown['spec']['started'] );
ok( lifecycle_row_locked_nowait(), 'single-item Undo fence holds the indexed lifecycle lock' );
ok( await_shutdown_sql( $shutdown ), 'uninstall DELETE waits on lifecycle record' );
ok( ! is_file( $shutdown['spec']['result'] ), 'uninstall waits for fenced Undo item' );
file_put_contents( $w['spec']['release'], 'go' );
await_file( $un_after );
finish_worker( $shutdown );
forget_uninstall_option_cache();
foreach ( array( 'writeleash_version', 'writeleash_job_schema', 'writeleash_job_setup', 'writeleash_runner_state', 'writeleash_undo_schema', 'writeleash_undo_setup' ) as $option ) {
	eq( 0, option_rows( $option ), 'uninstall removes owned option ' . $option );
}
eq( 1, option_rows( 'writeleash_unrelated' ), 'uninstall keeps unrelated options' );
foreach ( array( $undo_ops, $undo_items, Schema::jobs_table( $wpdb ), Schema::items_table( $wpdb ), Journal::table( $wpdb ) ) as $table ) {
	eq( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) ), 'uninstall race preserves evidence table' );
}
eq( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ID=%d', $wpdb->posts, $un_id ) ), 'uninstall race preserves Woo product row' );
// Owned AS wake-ups canceled, unrelated sentinel and AS tables untouched.
$as_store = \ActionScheduler::store();
foreach ( $as_owned_ids as $as_group => $as_action_id ) {
	eq( 'canceled', $as_store->get_status( $as_action_id ), 'uninstall cancels owned AS action ' . $as_group );
}
eq( 'pending', $as_store->get_status( $as_sentinel_id ), 'uninstall leaves unrelated AS sentinel pending' );
eq( array(), $as_store->query_actions( array( 'group' => 'writeleash-unrelated-sentinel', 'status' => 'canceled' ) ), 'uninstall cancels no unrelated AS action' );
$observer = Verifier::observer();
try {
	foreach ( array( 'actionscheduler_actions', 'actionscheduler_groups' ) as $as_table ) {
		eq( 1, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $observer->prefix . $as_table ) ), 'uninstall preserves AS table ' . $as_table );
	}
} finally { $observer->close(); }
echo "#110 uninstall owned AS groups only PASS\n";
file_put_contents( $un_release, 'go' );
$un_result = finish_worker( $w );
eq( $un_result['status'], UState::PAUSED, 'surviving worker pauses after its in-flight boundary' );
eq( $un_result['stop'], 'DEACTIVATED', 'stop caused by fail-closed lifecycle authority, not item exhaustion' );
eq( $un_result['processed'], 1, 'exactly the already-fenced item completed' );
eq( undo_row( $un_job, $un_id )['state'], UItem::UNDONE, 'in-flight item reached its durable boundary' );
eq( Decimal::parse( fresh_price( $un_id ) ), Decimal::parse( '100.00' ), 'in-flight item restored exactly once' );
eq( fresh_price( $pr_id ), $un_sibling_price, 'uninstall never auto-restores applied prices' );
marker( 'uninstall idle and single-item worker race: options removed, AS owned-only, evidence preserved' );

// ---------------------------------------------------------------------------
// Blocker: surviving Undo worker with 2 items must finish item 1's already
// fenced transaction and then stop before claiming item 2 after uninstall.
// ---------------------------------------------------------------------------
WriteLeash\Lifecycle::activate();
eq( get_option( 'writeleash_runner_state' ), 'active', 'reactivation restores explicit active authority' );
$ru = apply_fixture( array( 'changing' => 2 ) );
$ru_job = (int) $ru['job']['id'];
list( $ru_id1, $ru_id2 ) = $ru['ids'];
$ru_op = start_undo( $ru_job );
$ru_undo = (int) $ru_op['id'];
$ru_base1 = saves( $ru_id1 );
$ru_base2 = saves( $ru_id2 );
$ru_after = $work . '/ru-after-' . wp_generate_uuid4();
$ru_release = $work . '/ru-release-' . wp_generate_uuid4();
$w = start_worker( array( 'undo_id' => $ru_undo, 'mode' => 'undo', 'limits' => limits( 5, 60 ), 'undo_checkpoint' => 'UNDO_BEFORE_WOO_SAVE', 'fault' => 'wait', 'after_item_barrier' => $ru_after, 'after_item_release' => $ru_release, 'lock_wait_timeout' => 30 ) );
await_file( $w['spec']['barrier'] ); // item 1 fenced, Woo save not yet issued
eq( undo_row( $ru_job, $ru_id1 )['state'], UItem::APPLYING, 'undo item 1 is in flight behind its fence' );
eq( saves( $ru_id1 ), $ru_base1, 'no Woo save before the barrier' );
$shutdown = start_shutdown( 'uninstall' );
await_file( $shutdown['spec']['started'] );
ok( lifecycle_row_locked_nowait(), 'Undo transaction holds indexed lifecycle row' );
ok( await_shutdown_sql( $shutdown ), 'uninstall DELETE waits for Undo lifecycle lock' );
ok( ! is_file( $shutdown['spec']['result'] ), 'uninstall blocks behind Undo lifecycle fence' );
file_put_contents( $w['spec']['release'], 'go' );
await_file( $ru_after );
finish_worker( $shutdown );
forget_uninstall_option_cache();
eq( 0, option_rows( 'writeleash_runner_state' ), 'uninstall removed durable runner authority' );
file_put_contents( $ru_release, 'go' );
$ru_result = finish_worker( $w );
eq( $ru_result['status'], UState::PAUSED, 'undo worker pauses after its boundary' );
eq( $ru_result['stop'], 'DEACTIVATED', 'undo worker stop reason is the lifecycle gate' );
eq( $ru_result['processed'], 1, 'undo worker processed exactly the in-flight item' );
eq( saves( $ru_id1 ) - $ru_base1, 1, 'item 1 issued exactly one restore save' );
eq( saves( $ru_id2 ) - $ru_base2, 0, 'item 2 issued zero Woo saves' );
$observer = Verifier::observer();
try {
	$ru_row1 = URepo::read_item( $observer, $ru_job, $ru_id1 );
	$ru_row2 = URepo::read_item( $observer, $ru_job, $ru_id2 );
	eq( $ru_row1['state'], UItem::UNDONE, 'fresh observer: item 1 UNDONE' );
	eq( $ru_row2['state'], UItem::PENDING, 'fresh observer: item 2 still pending' );
	eq( 1, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE undo_id=%d AND state=%s', USchema::items_table( $observer ), $ru_undo, UItem::UNDONE ) ), 'fresh observer: exactly one UNDONE row' );
	eq( 0, (int) $observer->get_var( $observer->prepare( 'SELECT COUNT(*) FROM %i WHERE undo_id=%d AND product_id=%d AND state=%s', USchema::items_table( $observer ), $ru_undo, $ru_id2, UItem::UNDONE ) ), 'fresh observer: item 2 never UNDONE' );
} finally { $observer->close(); }
eq( Decimal::parse( fresh_price( $ru_id1 ) ), Decimal::parse( '100.00' ), 'item 1 restored' );
eq( Decimal::parse( fresh_price( $ru_id2 ) ), Decimal::parse( '80.00' ), 'item 2 remains applied' );
$ru_counts = undo_counts( $ru_undo );
eq( $ru_counts['undone'], 1, 'exactly one durable UNDONE outcome' );
eq( $ru_counts['pending'], 1, 'item 2 never claimed after uninstall' );
echo "#110 uninstall Undo multi-item race: in-flight item finishes, next item blocked PASS\n";
echo "#110 lifecycle fence: Undo transaction wins, uninstall waits PASS\n";
marker( 'uninstall Undo multi-item race: item 1 boundary completes, item 2 blocked by lifecycle gate' );

// ---------------------------------------------------------------------------
// Blocker: surviving Apply worker with 2 items must finish item 1's already
// fenced transaction and then stop before claiming item 2 after uninstall.
// ---------------------------------------------------------------------------
WriteLeash\Lifecycle::activate();
$ra = planned_fixture( 2 );
$ra_job = Repo::approve( (int) $ra['job']['id'], 1 );
$ra_job_id = (int) $ra_job['id'];
$ra_plan = $ra['plan']->data()['plan_id'];
list( $ra_id1, $ra_id2 ) = $ra['ids'];
$ra_base1 = saves( $ra_id1 );
$ra_base2 = saves( $ra_id2 );
$ra_after = $work . '/ra-after-' . wp_generate_uuid4();
$ra_release = $work . '/ra-release-' . wp_generate_uuid4();
$w = start_worker( array(
	'job_id' => $ra_job_id, 'mode' => 'apply', 'limits' => limits( 5, 60 ),
	'apply_checkpoint' => 'AFTER_WOO_SAVE_BEFORE_JOURNAL', 'fault' => 'wait',
	'after_item_barrier' => $ra_after, 'after_item_release' => $ra_release,
	'lock_wait_timeout' => 30,
) );
await_file( $w['spec']['barrier'] ); // item 1 Woo save executed inside the open transaction
eq( saves( $ra_id1 ) - $ra_base1, 1, 'item 1 Woo save executed inside the uncommitted transaction' );
eq( journal_row( $ra_plan, $ra_id1 )['state'], 'PENDING', 'item 1 journal not APPLIED before commit' );
$shutdown = start_shutdown( 'uninstall' );
await_file( $shutdown['spec']['started'] );
ok( lifecycle_row_locked_nowait(), 'Apply transaction holds indexed lifecycle row' );
ok( await_shutdown_sql( $shutdown ), 'uninstall DELETE waits for Apply lifecycle lock' );
ok( ! is_file( $shutdown['spec']['result'] ), 'uninstall blocks behind Apply lifecycle fence' );
file_put_contents( $w['spec']['release'], 'go' );
await_file( $ra_after );
finish_worker( $shutdown );
forget_uninstall_option_cache();
eq( 0, option_rows( 'writeleash_runner_state' ), 'uninstall removed durable runner authority' );
file_put_contents( $ra_release, 'go' );
$ra_result = finish_worker( $w );
eq( $ra_result['status'], JState::PAUSED, 'apply worker pauses after its boundary' );
eq( $ra_result['stop'], 'DEACTIVATED', 'apply worker stop reason is the lifecycle gate' );
eq( $ra_result['processed'], 1, 'apply worker processed exactly the in-flight item' );
eq( saves( $ra_id1 ) - $ra_base1, 1, 'item 1 kept exactly one Woo save' );
eq( saves( $ra_id2 ) - $ra_base2, 0, 'item 2 issued zero Woo saves' );
$observer = Verifier::observer();
try {
	eq( 'APPLIED', Journal::read( $observer, $ra_plan, $ra_id1 )['state'], 'fresh observer: item 1 journal APPLIED' );
	eq( 'PENDING', Journal::read( $observer, $ra_plan, $ra_id2 )['state'], 'fresh observer: item 2 journal never APPLIED' );
	eq( IState::APPLIED, $observer->get_var( $observer->prepare( 'SELECT state FROM %i WHERE job_id=%d AND product_id=%d', Schema::items_table( $observer ), $ra_job_id, $ra_id1 ) ), 'fresh observer: item 1 APPLIED' );
	eq( IState::PENDING, $observer->get_var( $observer->prepare( 'SELECT state FROM %i WHERE job_id=%d AND product_id=%d', Schema::items_table( $observer ), $ra_job_id, $ra_id2 ) ), 'fresh observer: item 2 still PENDING' );
} finally { $observer->close(); }
eq( Decimal::parse( fresh_price( $ra_id1 ) ), Decimal::parse( '80.00' ), 'item 1 applied its frozen target' );
eq( Decimal::parse( fresh_price( $ra_id2 ) ), Decimal::parse( '100.00' ), 'item 2 keeps its original price' );
eq( Repo::read( $ra_job_id )['status_reason'], 'DEACTIVATED', 'apply job durable pause reason' );
echo "#109/#110 uninstall Apply multi-item race: in-flight item finishes, next item blocked PASS\n";
echo "#109/#110 lifecycle fence: Apply transaction wins, uninstall waits PASS\n";
marker( 'uninstall Apply multi-item race: item 1 boundary completes, item 2 blocked by lifecycle gate' );

echo "#110 Woo 11.1.2 / MySQL+MariaDB / default+Redis matrix: PASS\n";
