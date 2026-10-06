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

global $wpdb, $work, $assertions, $hook_log, $items_table, $jobs_table;
$assertions = 0;
$work = sys_get_temp_dir() . '/wl109-' . bin2hex( random_bytes( 5 ) );
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
	echo "#109 $label: PASS ($assertions assertions)\n";
}
function create_product( string $price, string $status = 'publish' ): int {
	$p = new WC_Product_Simple();
	$p->set_name( 'WL109-' . wp_generate_uuid4() );
	$p->set_status( $status );
	$p->set_regular_price( $price );
	$p->save();
	return $p->get_id();
}
/** Frozen plan + job for a controlled product mix. One #107 operation for all items. */
function job_fixture( array $opts = array() ): array {
	$changing = (int) ( $opts['changing'] ?? 1 );
	$unchanged = (int) ( $opts['unchanged'] ?? 0 );
	$unsupported = (int) ( $opts['unsupported'] ?? 0 );
	$price = (string) ( $opts['price'] ?? '100.00' );
	$target = (string) ( $opts['target'] ?? '80.00' );
	$actor = (int) ( $opts['actor'] ?? 1 );
	wp_set_current_user( $actor );
	$ids = array();
	$roles = array();
	for ( $i = 0; $i < $changing; ++$i ) { $id = create_product( $price, 'publish' ); $ids[] = $id; $roles[$id] = 'changing'; }
	for ( $i = 0; $i < $unchanged; ++$i ) { $id = create_product( $target, 'publish' ); $ids[] = $id; $roles[$id] = 'unchanged'; }
	for ( $i = 0; $i < $unsupported; ++$i ) { $id = create_product( $price, 'draft' ); $ids[] = $id; $roles[$id] = 'unsupported'; }
	sort( $ids, SORT_NUMERIC );
	$plan = Planner::preview( Selection::ids( $ids ), new Operation( Operation::SET, $target ), new Policy( 1000, '100', '100', true, '100' ) );
	$job = Repo::create_from_plan( $plan, $actor );
	return array( 'plan' => $plan, 'job' => $job, 'ids' => $ids, 'roles' => $roles, 'target' => $target );
}
function approves( array $f, int $approver = 1 ): array { return Repo::approve( (int) $f['job']['id'], $approver ); }
function item_map( int $job_id ): array {
	$map = array();
	foreach ( Repo::items( $job_id, null, 0, Repo::PAGE_LIMIT ) as $row ) { $map[ (int) $row['product_id'] ] = $row; }
	return $map;
}
function assert_counts( int $job_id, string $label ): array {
	$derived = Repo::counts( $job_id );
	$stored = Repo::read( $job_id );
	foreach ( array( 'planned', 'pending', 'applying', 'applied', 'unchanged', 'conflict', 'failed', 'needs_review', 'unsupported' ) as $key ) {
		eq( (int) $stored[$key], $derived[$key], $label . ' stored counter ' . $key );
	}
	return $derived;
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

wp_set_current_user( 1 );
eq( \WriteLeash\Free_Support_Contract::woo_supported( WC_VERSION ), true, 'fixture Woo inside supported range' );
eq( get_bloginfo( 'version' ), '7.1.2', 'pinned WordPress' );
eq( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, '8.2', 'pinned PHP' );
eq( (bool) wp_using_ext_object_cache(), 'persistent' === getenv( 'WL109_CACHE' ), 'real persistent cache mode' );
if ( wp_using_ext_object_cache() ) {
	eq( get_file_data( WP_CONTENT_DIR . '/object-cache.php', array( 'version' => 'Version' ) )['version'], '2.7.0', 'pinned Redis drop-in' );
}
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wl108_hook_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, product_id bigint unsigned NOT NULL, hook varchar(100) NOT NULL) ENGINE=InnoDB" );

// ---------------------------------------------------------------------------
// Schema: fresh install, replay, unknown version, partial state.
// ---------------------------------------------------------------------------
$f = job_fixture( array( 'changing' => 0, 'unchanged' => 1 ) );
eq( Schema::ready( $wpdb ), true, 'fresh import installed schema' );
eq( get_option( 'writeleash_job_setup' ), 'READY', 'setup recorded ready' );
Schema::install();
Schema::install();
eq( Schema::ready( $wpdb ), true, 'install replay idempotent' );
marker( 'schema fresh install + replay' );

update_option( 'writeleash_job_schema', 99, false );
eq( Schema::ready( $wpdb ), false, 'unknown schema version not ready' );
$result = run_worker( array( 'job_id' => (int) $f['job']['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
eq( $result['stop'], 'SCHEMA_UNAVAILABLE', 'worker refuses unknown schema' );
eq( $result['processed'], 0, 'zero items on unknown schema' );
update_option( 'writeleash_job_schema', Schema::SCHEMA_VERSION, false );

$items_table = Schema::items_table( $wpdb );
$jobs_table = Schema::jobs_table( $wpdb );
$f2 = job_fixture( array( 'changing' => 2 ) );
approves( $f2 );
$f2_job = (int) $f2['job']['id'];
$before_saves = saves( $f2['ids'][0] );
// Break the schema after approval: the worker must not execute a partial shape.
$wpdb->query( "DROP TABLE IF EXISTS {$items_table}_partial" );
$wpdb->query( "RENAME TABLE $items_table TO {$items_table}_partial" );
$result = run_worker( array( 'job_id' => $f2_job, 'mode' => 'run', 'limits' => limits( 5 ) ) );
eq( $result['stop'], 'SCHEMA_UNAVAILABLE', 'missing items table refuses execution' );
eq( $result['processed'], 0, 'zero processed with partial schema' );
eq( saves( $f2['ids'][0] ), $before_saves, 'partial schema zero mutation' );
$f2_row = Repo::read( $f2_job );
eq( $f2_row['status'], JState::PAUSED, 'partial schema persisted paused' );
eq( $f2_row['status_reason'], 'SCHEMA_UNAVAILABLE', 'partial schema typed reason' );
Schema::install();
eq( Schema::ready( $wpdb ), true, 'partial replay repaired' );
// Interrupted migration recovery: existing durable item rows are restored into
// the recreated shape before the operator removes the staging copy.
$wpdb->query( "INSERT INTO $items_table SELECT * FROM {$items_table}_partial" );
$wpdb->query( "DROP TABLE {$items_table}_partial" );
eq( count( item_map( $f2_job ) ), 2, 'repaired schema retains durable item rows' );
$resume = run_worker( array( 'job_id' => $f2_job, 'mode' => 'run', 'limits' => limits( 5 ), 'manual' => true ) );
eq( $resume['status'], JState::COMPLETED, 'manual resume after repair completes' );
eq( saves( $f2['ids'][0] ), 1, 'repaired resume applies once' );
marker( 'schema unknown/partial fail closed with zero mutation' );

$f3 = job_fixture( array( 'changing' => 2 ) );
approves( $f3 );
$f3_job = (int) $f3['job']['id'];
$wpdb->query( "ALTER TABLE $items_table ENGINE=MyISAM" );
$result = run_worker( array( 'job_id' => $f3_job, 'mode' => 'run', 'limits' => limits( 5 ) ) );
eq( $result['stop'], 'SCHEMA_UNAVAILABLE', 'wrong engine refuses execution' );
eq( $result['processed'], 0, 'wrong engine zero processed' );
eq( saves( $f3['ids'][0] ), 0, 'wrong engine zero mutation' );
eq( Repo::read( $f3_job )['status_reason'], 'SCHEMA_UNAVAILABLE', 'wrong engine typed pause' );
$wpdb->query( "ALTER TABLE $items_table ENGINE=InnoDB" );
eq( Schema::ready( $wpdb ), true, 'engine restored' );
eq( run_worker( array( 'job_id' => $f3_job, 'mode' => 'run', 'limits' => limits( 5 ), 'manual' => true ) )['status'], JState::COMPLETED, 'resume after engine restore' );
marker( 'schema wrong engine fails closed' );

// ---------------------------------------------------------------------------
// Required basic E2E: 13 changing (1 conflict injected), 2 unchanged, 2 unsupported.
// ---------------------------------------------------------------------------
$f = job_fixture( array( 'changing' => 13, 'unchanged' => 2, 'unsupported' => 2 ) );
$job_id = (int) $f['job']['id'];
eq( $f['job']['status'], JState::PLANNED, 'import lands PLANNED' );
$job = approves( $f );
eq( $job['status'], JState::READY, 'approval lands READY' );
eq( Scheduler::available(), true, 'Action Scheduler 4.0.0 initialized after woocommerce_init' );
eq( Worker::queue_job( $job_id ), 'SCHEDULED', 'wake-up scheduled' );
eq( Repo::read( $job_id )['status'], JState::QUEUED, 'queued status persisted' );

$items = item_map( $job_id );
$conflict_id = $f['ids'][0];
$roles = $f['roles'];
foreach ( $items as $row ) {
	if ( 'changing' === $roles[ (int) $row['product_id'] ] ) { eq( $row['state'], IState::PENDING, 'changing item pending after import' ); }
	if ( 'unchanged' === $roles[ (int) $row['product_id'] ] ) { eq( $row['state'], IState::UNCHANGED, 'unchanged item terminal at import' ); }
	if ( 'unsupported' === $roles[ (int) $row['product_id'] ] ) { eq( $row['state'], IState::UNSUPPORTED, 'unsupported item terminal at import' ); }
}
// External conflict: the first changing product is edited after approval.
fresh_price( $conflict_id );
$p = wc_get_product( $conflict_id );
$p->set_regular_price( '90.00' );
$p->save();

$runs = 0;
do {
	$result = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 4 ) ) );
	++$runs;
	$result_status = Repo::read( $job_id )['status'];
} while ( in_array( $result_status, array( JState::QUEUED, JState::READY ), true ) && $runs < 10 );
$job = Repo::read( $job_id );
eq( $job['status'], JState::COMPLETED_WITH_ISSUES, 'mixed outcome is not generic success' );
eq( $runs, 4, 'bounded batches of four' );
$counts = assert_counts( $job_id, 'E2E' );
eq( $counts['applied'], 12, 'twelve applied' );
eq( $counts['conflict'], 1, 'one conflict' );
eq( $counts['unchanged'], 2, 'two unchanged' );
eq( $counts['unsupported'], 2, 'two unsupported' );
eq( $counts['pending'] + $counts['applying'], 0, 'nothing left pending' );
foreach ( $f['ids'] as $id ) {
	$role = $roles[$id];
	if ( 'changing' === $role && $id !== $conflict_id ) {
		eq( fresh_price( $id ), $f['target'], 'applied target price ' . $id );
		eq( journal_row( $f['plan']->data()['plan_id'], $id )['state'], 'APPLIED', 'journal applied ' . $id );
		eq( saves( $id ), 1, 'exactly one save ' . $id );
	} elseif ( 'unchanged' === $role ) {
		eq( fresh_price( $id ), $f['target'], 'unchanged price kept ' . $id );
		eq( saves( $id ), 0, 'unchanged zero saves ' . $id );
		eq( journal_row( $f['plan']->data()['plan_id'], $id ), null, 'unchanged not seeded ' . $id );
	} elseif ( 'unsupported' === $role ) {
		eq( fresh_price( $id ), '100.00', 'unsupported price kept ' . $id );
		eq( saves( $id ), 0, 'unsupported zero saves ' . $id );
		eq( journal_row( $f['plan']->data()['plan_id'], $id ), null, 'unsupported not seeded ' . $id );
	}
}
eq( fresh_price( $conflict_id ), '90.00', 'conflict price not overwritten' );
eq( journal_row( $f['plan']->data()['plan_id'], $conflict_id )['state'], 'CONFLICT', 'conflict journal' );
eq( saves( $conflict_id ), 0, 'conflict zero WriteLeash saves' );
$obs = Repo::observe( $job_id );
eq( $obs['counts']['applied'], 12, 'observe counts from rows' );
eq( $obs['stalled'], false, 'completed job not stalled' );
marker( 'basic E2E 17 items: no false generic success' );

// ---------------------------------------------------------------------------
// Host without CREATE TABLE: typed setup failure, zero job execution.
// A restricted account with no CREATE privilege simulates the host directly;
// MySQL/MariaDB do not always drop privileges of already-authenticated connections.
// ---------------------------------------------------------------------------
$root_user = getenv( 'WL109_ROOT_USER' ) ?: 'root';
$root_password = getenv( 'WL109_ROOT_PASSWORD' );
$root_password = false === $root_password ? '' : $root_password;
$root = new wpdb( $root_user, $root_password, DB_NAME, DB_HOST );
$root->suppress_errors( true );
$wpdb->suppress_errors( true );
$restricted_user = 'wl109_nocreate';
$restricted_password = 'wl109_no_create_pw';
$root->query( "DROP USER IF EXISTS '$restricted_user'@'%'" );
$root->query( "CREATE USER '$restricted_user'@'%' IDENTIFIED BY '$restricted_password'" );
$root->query( "GRANT SELECT, INSERT, UPDATE, DELETE ON wp_test.* TO '$restricted_user'@'%'" );
$root->query( 'FLUSH PRIVILEGES' );
$wpdb->query( "DROP TABLE IF EXISTS $items_table, $jobs_table" );
$wpdb->query( 'DROP TABLE IF EXISTS ' . Journal::table( $wpdb ) );
delete_option( 'writeleash_job_schema' );
delete_option( 'writeleash_job_setup' );
$restricted_db = new wpdb( $restricted_user, $restricted_password, DB_NAME, DB_HOST );
$restricted_db->set_prefix( $wpdb->prefix );
$restricted_db->suppress_errors( true );
$original_db = $wpdb;
$GLOBALS['wpdb'] = $restricted_db;
$no_ddl_error = null;
try { job_fixture( array( 'changing' => 1 ) ); }
catch ( WriteLeash\Job_Error $error ) { $no_ddl_error = $error->reason(); }
finally { $GLOBALS['wpdb'] = $original_db; }
eq( $no_ddl_error, 'SCHEMA_UNAVAILABLE', 'no-DDL host typed setup failure' );
eq( get_option( 'writeleash_job_setup' ), 'SCHEMA_UNAVAILABLE', 'no-DDL host diagnostic recorded' );
eq( Schema::ready( $wpdb ), false, 'no-DDL host setup not ready' );
$root->query( "DROP USER IF EXISTS '$restricted_user'@'%'" );
$root->query( 'FLUSH PRIVILEGES' );
$root->close();
$wpdb->suppress_errors( false );
$f = job_fixture( array( 'changing' => 1 ) );
eq( Schema::ready( $wpdb ), true, 'setup recovers once CREATE TABLE is available' );
marker( 'host without CREATE TABLE: no partial start' );

// ---------------------------------------------------------------------------
// Browser close: request creates/queues, process exits, worker runs later.
// ---------------------------------------------------------------------------
$ids = array( create_product( '100.00' ), create_product( '100.00' ), create_product( '100.00' ), create_product( '100.00' ) );
$admin = start_worker( array( 'ids' => $ids, 'target' => '80.00', 'actor' => 1, 'plan' => $work . '/admin.plan', 'result' => $work . '/admin.result' ), 'admin-request.php' );
$admin_result = finish_worker( $admin );
eq( $admin_result['queue'], 'SCHEDULED', 'browser-close request queued the job' );
$job_id = (int) $admin_result['job_id'];
$plan = unserialize( file_get_contents( $work . '/admin.plan' ) );
eq( $plan->data()['plan_id'], $admin_result['plan_id'], 'frozen plan persisted outside the request' );
eq( count( as_get_scheduled_actions( array( 'hook' => Scheduler::HOOK, 'args' => array( $job_id ), 'group' => Scheduler::GROUP, 'status' => 'pending' ), 'ids' ) ) > 0, true, 'owned scheduled action exists' );
$callback = run_worker( array( 'job_id' => $job_id, 'mode' => 'callback', 'limits' => limits( 10 ) ) );
eq( $callback['callback'], true, 'scheduler callback executed without request state' );
eq( Repo::read( $job_id )['status'], JState::COMPLETED, 'background worker completed after request exit' );
foreach ( $ids as $id ) { eq( fresh_price( $id ), '80.00', 'browser-close applied ' . $id ); eq( saves( $id ), 1, 'browser-close one save ' . $id ); }
marker( 'browser close: durable job survives request exit' );

// ---------------------------------------------------------------------------
// Bounded execution: 20 items, budget permits 5 per run.
// ---------------------------------------------------------------------------
$f = job_fixture( array( 'changing' => 20 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
$first = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 5, 20 ) ) );
eq( $first['processed'], 5, 'first chunk processed five' );
eq( $first['stop'], 'BATCH_LIMIT', 'stop reason batch limit' );
$counts = assert_counts( $job_id, 'timeout' );
eq( $counts['applied'], 5, 'five durable outcomes' );
eq( $counts['pending'], 15, 'fifteen still pending' );
eq( Repo::read( $job_id )['status'], JState::QUEUED, 'queued for continuation' );
for ( $i = 0; $i < 4; ++$i ) { $last = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 5, 20 ) ) ); }
eq( $last['status'], JState::COMPLETED, 'batched continuation completes' );
assert_counts( $job_id, 'timeout-final' );
foreach ( $f['ids'] as $id ) { eq( saves( $id ), 1, 'no item replay ' . $id ); eq( fresh_price( $id ), '80.00', 'batched target ' . $id ); }
marker( 'bounded chunks: five durable outcomes, resume continues, no replay' );

// ---------------------------------------------------------------------------
// Two workers: real process overlap, one fence authority, no duplicate save.
// ---------------------------------------------------------------------------
$f = job_fixture( array( 'changing' => 6 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
$a = start_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 3 ), 'checkpoint' => 'AFTER_CLAIM', 'fault' => 'wait' ) );
await_file( $a['spec']['barrier'] );
$seen = Repo::read( $job_id );
eq( $seen['status'], JState::RUNNING, 'first worker holds running lease' );
$second = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 3 ) ) );
eq( $second['stop'], 'LEASE_HELD', 'second worker cannot acquire live lease' );
eq( $second['processed'], 0, 'second worker mutates nothing' );
eq( saves( $f['ids'][0] ) + saves( $f['ids'][1] ), 0, 'no mutation while first worker paused' );
file_put_contents( $a['spec']['release'], 'go' );
$first = finish_worker( $a );
eq( $first['processed'], 3, 'first worker resumes its bounded chunk' );
for ( $i = 0; $i < 4 && Repo::read( $job_id )['status'] !== JState::COMPLETED; ++$i ) { run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 3 ) ) ); }
eq( Repo::read( $job_id )['status'], JState::COMPLETED, 'overlap job completes' );
foreach ( $f['ids'] as $id ) { eq( saves( $id ), 1, 'overlap exactly one save ' . $id ); }
assert_counts( $job_id, 'overlap' );
// Truly concurrent duplicate callbacks on a fresh job: invariants only.
$f = job_fixture( array( 'changing' => 8 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
$x = start_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 8 ) ) );
$y = start_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 8 ) ) );
finish_worker( $x );
finish_worker( $y );
$counts = assert_counts( $job_id, 'duplicate' );
eq( in_array( Repo::read( $job_id )['status'], array( JState::COMPLETED ), true ), true, 'duplicate callbacks finish truthfully' );
eq( $counts['applied'], 8, 'duplicate callbacks apply each item once' );
foreach ( $f['ids'] as $id ) { eq( saves( $id ), 1, 'duplicate callback no double save ' . $id ); }
marker( 'duplicate wake-ups: one effective authority / no duplicate mutation' );

// ---------------------------------------------------------------------------
// Stale worker takeover: A loses generation N, B acquires N+1, A is harmless.
// ---------------------------------------------------------------------------
$f = job_fixture( array( 'changing' => 4 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
$a = start_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 4 ), 'checkpoint' => 'AFTER_CLAIM', 'fault' => 'wait' ) );
await_file( $a['spec']['barrier'] );
list( , $generation_a ) = explode( ':', (string) file_get_contents( $a['spec']['barrier'] ) );
$generation_a = (int) $generation_a;
$wpdb->query( $wpdb->prepare( "UPDATE $jobs_table SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=%d", $job_id ) );
$b = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 4 ) ) );
eq( $b['generation'], $generation_a + 1, 'takeover increments the fence generation' );
eq( $b['status'], JState::COMPLETED, 'new generation completes the job' );
file_put_contents( $a['spec']['release'], 'go' );
$stale = finish_worker( $a );
eq( $stale['stop'], 'FENCE_LOST', 'stale worker stops on lost fence' );
eq( $stale['processed'], 0, 'stale worker performs zero mutations after losing fence' );
foreach ( $f['ids'] as $id ) { eq( saves( $id ), 1, 'stale takeover one save total ' . $id ); }
assert_counts( $job_id, 'stale' );
echo "#109 stale takeover marker: A generation=$generation_a B generation={$b['generation']} processed=0 saves=4\n";
marker( 'stale lease takeover: old worker cannot mutate after fence loss' );

// ---------------------------------------------------------------------------
// Killed worker after item commit: reconcile from #108 journal without replay.
// ---------------------------------------------------------------------------
$f = job_fixture( array( 'changing' => 2 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
$a = start_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 2 ), 'mutator_checkpoint' => 'AFTER_COMMIT_BEFORE_RESPONSE', 'mutator_fault' => 'wait' ) );
await_file( $a['spec']['barrier'] );
$applied_id = null;
foreach ( $f['ids'] as $id ) { if ( 'APPLIED' === ( journal_row( $f['plan']->data()['plan_id'], $id )['state'] ?? '' ) ) { $applied_id = $id; } }
ok( null !== $applied_id, 'journal shows the committed item' );
eq( saves( $applied_id ), 1, 'committed item one save before kill' );
proc_terminate( $a['proc'], 9 );
finish_worker( $a, true );
$before = item_map( $job_id );
eq( $before[$applied_id]['state'], IState::APPLYING, 'killed worker left item claiming state' );
$wpdb->query( $wpdb->prepare( "UPDATE $jobs_table SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=%d", $job_id ) );
$recovery = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 2 ) ) );
eq( $recovery['status'], JState::COMPLETED, 'recovery worker completes' );
eq( saves( $applied_id ), 1, 'recovered APPLIED item never re-saved' );
$after = item_map( $job_id );
eq( $after[$applied_id]['state'], IState::APPLIED, 'item reconciled to applied' );
eq( $after[$applied_id]['reason'], 'DURABLE_RECONCILED', 'typed reconciliation reason' );
assert_counts( $job_id, 'killed-after-commit' );
marker( 'killed worker after COMMIT: journal reconciliation without Woo replay' );

// ---------------------------------------------------------------------------
// Controlled PHP exception between items: resumable, no mutation ambiguity.
// ---------------------------------------------------------------------------
$f = job_fixture( array( 'changing' => 6 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
$crashed = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 6 ), 'checkpoint' => 'AFTER_ITEM', 'fault' => 'throw' ) );
eq( $crashed['stop'], 'WORKER_EXCEPTION', 'controlled exception typed stop' );
$row = Repo::read( $job_id );
eq( $row['status'], JState::NEEDS_REVIEW, 'unexpected exception needs review' );
eq( $row['status_reason'], 'WORKER_EXCEPTION', 'typed exception reason' );
$counts = assert_counts( $job_id, 'exception' );
eq( $counts['applied'], 1, 'item committed before exception is durable' );
eq( $counts['pending'], 5, 'remaining items pending' );
eq( $counts['needs_review'], 0, 'exception between items creates no review item' );
$resumed = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 6 ), 'manual' => true ) );
eq( $resumed['status'], JState::COMPLETED, 'manual resume after exception completes' );
foreach ( $f['ids'] as $id ) { eq( saves( $id ), 1, 'exception run no item replay ' . $id ); }
marker( 'PHP exception between items: durable state, explicit resume' );

// ---------------------------------------------------------------------------
// Scheduler unavailable + cron-impaired host: truthful wait, protected resume.
// ---------------------------------------------------------------------------
$f = job_fixture( array( 'changing' => 2 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
$fail_enqueue = static function () { return 0; };
add_filter( 'pre_as_enqueue_async_action', $fail_enqueue );
eq( Worker::queue_job( $job_id ), 'SCHEDULER_UNAVAILABLE', 'enqueue failure typed' );
remove_filter( 'pre_as_enqueue_async_action', $fail_enqueue );
$row = Repo::read( $job_id );
eq( $row['status'], JState::PAUSED, 'enqueue failure pauses instead of running' );
eq( $row['status_reason'], 'SCHEDULER_UNAVAILABLE', 'enqueue failure typed reason' );
eq( saves( $f['ids'][0] ), 0, 'enqueue failure zero mutations' );
$obs = Repo::observe( $job_id );
eq( $obs['waiting'], true, 'observable waiting state without a runner' );
eq( $obs['effective_status'], JState::PAUSED, 'never lies as runnable' );
$resumed = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 2 ), 'manual' => true ) );
eq( $resumed['status'], JState::COMPLETED, 'manual resume works without scheduler wake-up' );
marker( 'scheduler unavailable: paused with typed reason, manual resume completes' );

// ---------------------------------------------------------------------------
// Woo dependency loss and reactivation.
// ---------------------------------------------------------------------------
function wp_command( array $args ): array {
	$proc = proc_open( array_merge( array( 'wp', '--path=' . ABSPATH ), $args ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $proc ) ) { throw new RuntimeException( 'wp command spawn failed' ); }
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	return array( 'exit' => proc_close( $proc ), 'stdout' => $stdout, 'stderr' => $stderr );
}
$f = job_fixture( array( 'changing' => 2 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
$off = wp_command( array( 'plugin', 'deactivate', 'woocommerce' ) );
eq( $off['exit'], 0, 'Woo deactivated for dependency-loss test' );
$blocked = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 2 ) ) );
eq( $blocked['stop'], 'DEPENDENCY_UNAVAILABLE', 'worker refuses without Woo' );
eq( $blocked['processed'], 0, 'dependency loss zero processed' );
eq( saves( $f['ids'][0] ), 0, 'dependency loss zero mutations' );
$row = Repo::read( $job_id );
eq( $row['status'], JState::PAUSED, 'dependency loss pauses' );
eq( $row['status_reason'], 'DEPENDENCY_UNAVAILABLE', 'dependency loss typed reason' );
$on = wp_command( array( 'plugin', 'activate', 'woocommerce' ) );
eq( $on['exit'], 0, 'Woo reactivated' );
$resumed = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 2 ), 'manual' => true ) );
eq( $resumed['status'], JState::COMPLETED, 'resume after dependency restore' );
marker( 'Woo dependency loss: no mutation, typed pause, safe manual resume' );

// Reactivation reaps stale leases without mutating products.
$f = job_fixture( array( 'changing' => 2 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
$crash_owner = wp_generate_uuid4();
$lease = Repo::acquire_lease( $job_id, $crash_owner, true );
ok( ! empty( $lease ), 'simulated crashed worker holds a lease' );
$wpdb->query( $wpdb->prepare( "UPDATE $jobs_table SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND), updated_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 120 SECOND) WHERE id=%d", $job_id ) );
$obs = Repo::observe( $job_id );
eq( $obs['stalled'], true, 'stale heartbeat observable' );
eq( $obs['effective_reason'], 'LEASE_RECOVERY', 'stale heartbeat typed reason' );
\WriteLeash\Lifecycle::activate();
$row = Repo::read( $job_id );
eq( $row['status'], JState::PAUSED, 'reactivation reaps stale lease' );
eq( $row['status_reason'], 'LEASE_RECOVERY', 'reactivation typed reason' );
eq( saves( $f['ids'][0] ), 0, 'reactivation mutates nothing' );
eq( run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 2 ), 'manual' => true ) )['status'], JState::COMPLETED, 'resume after reactivation' );
marker( 'reactivation: stale lease reconciliation without product mutation' );

// ---------------------------------------------------------------------------
// Manual resume REST: capability, ownership and nonce negatives.
// ---------------------------------------------------------------------------
function rest_resume( string $public_id, int $user_id, ?string $nonce, string $method = 'POST' ): \WP_REST_Response {
	wp_set_current_user( $user_id );
	$request = new \WP_REST_Request( $method, '/writeleash/v1/jobs/' . $public_id . '/resume' );
	if ( null !== $nonce ) { $request->set_header( 'X-WP-Nonce', $nonce ); }
	return rest_do_request( $request );
}
/** Core cookie-nonce gate exactly as rest_api_loaded() applies it before dispatch. */
function nonce_gate( ?string $nonce ): array {
	if ( null === $nonce ) { unset( $_SERVER['HTTP_X_WP_NONCE'] ); } else { $_SERVER['HTTP_X_WP_NONCE'] = $nonce; }
	$GLOBALS['wp_rest_auth_cookie'] = true;
	$result = rest_cookie_check_errors( null );
	unset( $GLOBALS['wp_rest_auth_cookie'] );
	if ( true === $result ) { return array( 200, '' ); }
	$data = is_wp_error( $result ) ? $result->get_error_data() : array();
	return array( (int) ( $data['status'] ?? 0 ), is_wp_error( $result ) ? $result->get_error_code() : '' );
}
$f = job_fixture( array( 'changing' => 4 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
$public_id = Repo::read( $job_id )['public_id'];
$valid_nonce = wp_create_nonce( 'wp_rest' );
$subscriber = wp_insert_user( array( 'user_login' => 'wl109-sub-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$manager = wp_insert_user( array( 'user_login' => 'wl109-mgr-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$negatives = array(
	'unauthenticated' => rest_resume( $public_id, 0, $valid_nonce ),
	'subscriber' => rest_resume( $public_id, (int) $subscriber, $valid_nonce ),
	'foreign shop manager' => rest_resume( $public_id, (int) $manager, $valid_nonce ),
	'GET method' => rest_resume( $public_id, 1, $valid_nonce, 'GET' ),
	'malformed job id' => rest_resume( 'not-a-uuid', 1, $valid_nonce ),
);
eq( $negatives['unauthenticated']->get_status(), 401, 'unauthenticated denied' );
eq( $negatives['subscriber']->get_status(), 403, 'subscriber denied' );
eq( $negatives['foreign shop manager']->get_status(), 403, 'foreign manager denied' );
eq( in_array( $negatives['GET method']->get_status(), array( 404, 405 ), true ), true, 'GET does not mutate' );
eq( $negatives['malformed job id']->get_status(), 404, 'malformed job id denied' );
wp_set_current_user( 1 );
eq( nonce_gate( null ), array( 200, '' ), 'missing nonce downgrades the request' );
eq( get_current_user_id(), 0, 'missing nonce clears the authenticated user' );
eq( nonce_gate( 'deadbeefdead' ), array( 403, 'rest_cookie_invalid_nonce' ), 'wrong nonce denied by core gate before dispatch' );
wp_set_current_user( 1 );
eq( nonce_gate( $valid_nonce ), array( 200, '' ), 'valid nonce accepted by core gate' );
foreach ( $f['ids'] as $id ) { eq( saves( $id ), 0, 'REST negatives zero mutation ' . $id ); }
eq( Repo::read( $job_id )['status'], JState::READY, 'REST negatives leave state untouched' );
$success = rest_resume( $public_id, 1, $valid_nonce );
eq( $success->get_status(), 200, 'authorized creator resume succeeds' );
$payload = $success->get_data();
eq( $payload['job']['status'], JState::COMPLETED, 'authorized resume completes bounded work' );
eq( $payload['job']['counts']['applied'], 4, 'authorized resume applied all items' );
$terminal = rest_resume( $public_id, 1, $valid_nonce );
eq( $terminal->get_status(), 409, 'terminal job resume denied' );
// Schema failure surfaces as a typed 503 without mutation.
Schema::assert_schema( $wpdb );
$recreated_items = Schema::items_table( $wpdb );
$wpdb->query( "DROP TABLE $recreated_items" );
$broken = rest_resume( $public_id, 1, $valid_nonce );
eq( $broken->get_status(), 503, 'schema failure typed 503' );
Schema::install();
$wpdb->suppress_errors( false );
marker( 'manual resume REST negatives: zero mutation, strict POST/nonce/capability' );

// ---------------------------------------------------------------------------
// Permission revocation and actor removal: no implicit root substitution.
// ---------------------------------------------------------------------------
$manager_role = get_role( 'shop_manager' );
$actor = wp_insert_user( array( 'user_login' => 'wl109-actor-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$f = job_fixture( array( 'changing' => 3, 'actor' => (int) $actor ) );
$job_id = (int) $f['job']['id'];
approves( $f );
$manager_role->remove_cap( 'edit_products' );
$revoked = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 3 ) ) );
eq( $revoked['stop'], 'PERMISSION_REVOKED', 'revoked capability typed pause' );
$counts = assert_counts( $job_id, 'revoked' );
eq( $counts['failed'], 1, 'revoked item failed without retry' );
eq( $counts['applied'], 0, 'no unauthorized mutation' );
eq( $counts['pending'], 2, 'remaining pending' );
foreach ( $f['ids'] as $id ) { eq( saves( $id ), 0, 'revocation zero Woos saves ' . $id ); }
eq( Repo::read( $job_id )['status_reason'], 'PERMISSION_REVOKED', 'revocation typed job reason' );
$manager_role->add_cap( 'edit_products' );
$recovered = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 3 ), 'manual' => true ) );
eq( $recovered['status'], JState::COMPLETED_WITH_ISSUES, 'recovered job completes with issues' );
$counts = assert_counts( $job_id, 'revoked-resume' );
eq( $counts['failed'], 1, 'permission denial never retried' );
eq( $counts['applied'], 2, 'remaining items apply after restore' );
marker( 'permission revocation: zero unauthorized mutation, typed state, no root substitution' );

$ghost = wp_insert_user( array( 'user_login' => 'wl109-ghost-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$f = job_fixture( array( 'changing' => 2, 'actor' => (int) $ghost ) );
$job_id = (int) $f['job']['id'];
approves( $f );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( (int) $ghost );
$ghost_result = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 2 ) ) );
eq( $ghost_result['stop'], 'PERMISSION_REVOKED', 'deleted actor pauses job' );
foreach ( $f['ids'] as $id ) { eq( saves( $id ), 0, 'deleted actor zero saves ' . $id ); }
marker( 'actor removal: typed pause, zero implicit root mutation' );

// ---------------------------------------------------------------------------
// Multiple jobs touching the same product: optimistic expected-old semantics.
// ---------------------------------------------------------------------------
function single_job( int $product_id, string $target, int $actor = 1 ): array {
	wp_set_current_user( $actor );
	$plan = Planner::preview( Selection::ids( array( $product_id ) ), new Operation( Operation::SET, $target ), new Policy( 1000, '100', '100', true, '100' ) );
	$job = Repo::create_from_plan( $plan, $actor );
	Repo::approve( (int) $job['id'], 1 );
	return array( 'plan' => $plan, 'job' => Repo::read( (int) $job['id'] ) );
}
$product = create_product( '100.00' );
$a = single_job( $product, '80.00' );
$b = single_job( $product, '90.00' );
eq( run_worker( array( 'job_id' => (int) $a['job']['id'], 'mode' => 'run', 'limits' => limits( 1 ) ) )['status'], JState::COMPLETED, 'job A applies first' );
$b_result = run_worker( array( 'job_id' => (int) $b['job']['id'], 'mode' => 'run', 'limits' => limits( 1 ) ) );
eq( $b_result['status'], JState::COMPLETED_WITH_ISSUES, 'job B conflicts, not overwrite' );
eq( fresh_price( $product ), '80.00', 'later job never overwrites first winner' );
eq( saves( $product ), 1, 'one product save across two jobs' );
$product2 = create_product( '100.00' );
$c = single_job( $product2, '80.00' );
$d = single_job( $product2, '90.00' );
eq( run_worker( array( 'job_id' => (int) $d['job']['id'], 'mode' => 'run', 'limits' => limits( 1 ) ) )['status'], JState::COMPLETED, 'reverse order first winner' );
eq( run_worker( array( 'job_id' => (int) $c['job']['id'], 'mode' => 'run', 'limits' => limits( 1 ) ) )['status'], JState::COMPLETED_WITH_ISSUES, 'reverse order second conflicts' );
eq( fresh_price( $product2 ), '90.00', 'reverse order keeps first winner' );
$product3 = create_product( '100.00' );
$e = single_job( $product3, '80.00' );
$g = single_job( $product3, '80.00' );
eq( run_worker( array( 'job_id' => (int) $e['job']['id'], 'mode' => 'run', 'limits' => limits( 1 ) ) )['status'], JState::COMPLETED, 'same-target first applies' );
eq( run_worker( array( 'job_id' => (int) $g['job']['id'], 'mode' => 'run', 'limits' => limits( 1 ) ) )['status'], JState::COMPLETED_WITH_ISSUES, 'same target without own journal is conflict' );
eq( saves( $product3 ), 1, 'same-target equality never certifies second apply' );
marker( 'multi-job same product: expected-old optimistic conflict, no overwrite' );

// Parallel jobs: independent leases, no global queue lock.
$p1 = create_product( '100.00' );
$p2 = create_product( '100.00' );
$j1 = single_job( $p1, '80.00' );
$j2 = single_job( $p2, '80.00' );
$w1 = start_worker( array( 'job_id' => (int) $j1['job']['id'], 'mode' => 'run', 'limits' => limits( 1 ) ) );
$w2 = start_worker( array( 'job_id' => (int) $j2['job']['id'], 'mode' => 'run', 'limits' => limits( 1 ) ) );
finish_worker( $w1 );
finish_worker( $w2 );
eq( Repo::read( (int) $j1['job']['id'] )['status'], JState::COMPLETED, 'parallel job one completes' );
eq( Repo::read( (int) $j2['job']['id'] )['status'], JState::COMPLETED, 'parallel job two completes' );
eq( (int) Repo::read( (int) $j1['job']['id'] )['lease_generation'], 1, 'job one single lease' );
eq( (int) Repo::read( (int) $j2['job']['id'] )['lease_generation'], 1, 'job two single lease' );
eq( fresh_price( $p1 ), '80.00', 'parallel job one applied' );
eq( fresh_price( $p2 ), '80.00', 'parallel job two applied' );
marker( 'parallel jobs: lease isolation by job id, no global serialization' );

// ---------------------------------------------------------------------------
// Counters, pagination and exhaustive state machines.
// ---------------------------------------------------------------------------
$f = job_fixture( array( 'changing' => 6 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 6 ) ) );
$derived = Repo::counts( $job_id );
$wpdb->query( $wpdb->prepare( "UPDATE $jobs_table SET applied=999, pending=999 WHERE id=%d", $job_id ) );
eq( Repo::counts( $job_id )['applied'], $derived['applied'], 'derived counts ignore corrupted counters' );
Repo::refresh_counters( $wpdb, $job_id );
eq( (int) Repo::read( $job_id )['applied'], $derived['applied'], 'refresh repairs stored counters' );
$page = Repo::items( $job_id, null, 0, 4 );
eq( count( $page ), 4, 'bounded page size' );
eq( array_map( 'intval', array_column( $page, 'sequence' ) ), array( 1, 2, 3, 4 ), 'deterministic sequence order' );
$applied_page = Repo::items( $job_id, IState::APPLIED, 0, Repo::PAGE_LIMIT );
eq( count( $applied_page ), 6, 'state filter bounded read' );
$page_error = null;
try { Repo::items( $job_id, null, 0, 101 ); } catch ( WriteLeash\Job_Error $error ) { $page_error = $error->reason(); }
eq( $page_error, 'INVALID_PAGE', 'oversized page rejected' );
eq( run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 1 ) ) )['stop'], 'JOB_TERMINAL', 'completed job never runs again' );
$cancel_error = null;
try { Repo::cancel( $job_id, 1 ); } catch ( WriteLeash\Job_Error $error ) { $cancel_error = $error->reason(); }
eq( $cancel_error, 'INVALID_TRANSITION', 'completed job cannot be cancelled' );

$job_states = array( JState::DRAFT, JState::PLANNING, JState::PLANNED, JState::BLOCKED, JState::READY, JState::QUEUED, JState::RUNNING, JState::PAUSED, JState::COMPLETED, JState::COMPLETED_WITH_ISSUES, JState::NEEDS_REVIEW, JState::CANCELLED );
$job_allowed = array(
	JState::DRAFT => array( JState::PLANNED, JState::BLOCKED, JState::CANCELLED ),
	JState::PLANNING => array( JState::PLANNED, JState::BLOCKED, JState::CANCELLED ),
	JState::PLANNED => array( JState::READY, JState::BLOCKED, JState::CANCELLED ),
	JState::BLOCKED => array( JState::CANCELLED ),
	JState::READY => array( JState::QUEUED, JState::RUNNING, JState::PAUSED, JState::CANCELLED ),
	JState::QUEUED => array( JState::QUEUED, JState::RUNNING, JState::PAUSED, JState::CANCELLED ),
	JState::RUNNING => array( JState::RUNNING, JState::QUEUED, JState::PAUSED, JState::COMPLETED, JState::COMPLETED_WITH_ISSUES, JState::NEEDS_REVIEW, JState::CANCELLED ),
	JState::PAUSED => array( JState::PAUSED, JState::QUEUED, JState::RUNNING, JState::CANCELLED ),
	JState::NEEDS_REVIEW => array( JState::NEEDS_REVIEW, JState::PAUSED, JState::RUNNING, JState::CANCELLED ),
	JState::COMPLETED => array(),
	JState::COMPLETED_WITH_ISSUES => array(),
	JState::CANCELLED => array(),
);
foreach ( $job_states as $from ) {
	foreach ( $job_states as $to ) {
		$accepted = true;
		try { JState::assert_transition( $from, $to ); } catch ( WriteLeash\Job_Error $error ) { $accepted = false; }
		eq( $accepted, in_array( $to, $job_allowed[$from], true ), "job transition $from->$to" );
	}
}
$item_states = array( IState::PENDING, IState::APPLYING, IState::APPLIED, IState::UNCHANGED, IState::CONFLICT, IState::FAILED, IState::NEEDS_REVIEW, IState::UNSUPPORTED );
foreach ( $item_states as $from ) {
	foreach ( $item_states as $to ) {
		$accepted = true;
		try { IState::assert_transition( $from, $to ); } catch ( WriteLeash\Job_Error $error ) { $accepted = false; }
		$allowed = ( IState::PENDING === $from && in_array( $to, array( IState::APPLYING, IState::APPLIED, IState::CONFLICT, IState::FAILED, IState::NEEDS_REVIEW ), true ) ) ||
			( IState::APPLYING === $from && in_array( $to, array( IState::PENDING, IState::APPLIED, IState::CONFLICT, IState::FAILED, IState::NEEDS_REVIEW ), true ) );
		eq( $accepted, $allowed, "item transition $from->$to" );
	}
}
$item_initial_error = null;
try { IState::assert_transition( null, IState::APPLIED ); } catch ( WriteLeash\Job_Error $error ) { $item_initial_error = $error->reason(); }
eq( $item_initial_error, 'INVALID_ITEM_TRANSITION', 'items cannot be born applied' );
// CONFLICT can never silently return to a claimable state.
$product4 = create_product( '100.00' );
$conflict_plan = Planner::preview( Selection::ids( array( $product4 ) ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', true, '100' ) );
$conflict_job = Repo::create_from_plan( $conflict_plan, 1 );
Repo::approve( (int) $conflict_job['id'], 1 );
fresh_price( $product4 );
$pp = wc_get_product( $product4 );
$pp->set_regular_price( '95.00' );
$pp->save();
run_worker( array( 'job_id' => (int) $conflict_job['id'], 'mode' => 'run', 'limits' => limits( 1 ) ) );
eq( Repo::counts( (int) $conflict_job['id'] )['conflict'], 1, 'conflict recorded' );
eq( Repo::claim_next_item( (int) $conflict_job['id'], 'someone', wp_generate_uuid4(), 5 ), null, 'conflict item never reclaimable' );
$conflict_item = item_map( (int) $conflict_job['id'] );
$conflict_item = reset( $conflict_item );
$replay_error = null;
try { Repo::record_item( $conflict_item, 'token', IState::APPLYING, 'CONFLICT' ); } catch ( WriteLeash\Job_Error $error ) { $replay_error = $error->reason(); }
eq( $replay_error, 'INVALID_ITEM_TRANSITION', 'conflict cannot silently become applying' );
marker( 'counters/pagination/exhaustive state machine enforcement' );

// ---------------------------------------------------------------------------
// Job material tampering: every immutable binding fails closed with zero saves.
// ---------------------------------------------------------------------------
$tamper_cases = array();
$tamper_cases['plan target'] = static function ( array &$data ) { $data['items'][0]['planned_regular_price'] = '10.00'; };
$tamper_cases['policy snapshot'] = static function ( array &$data ) { $data['policy_snapshot']['block_zero'] = false; };
$make_tamper_fixture = static function (): array {
	$f = job_fixture( array( 'changing' => 1 ) );
	approves( $f );
	Worker::queue_job( (int) $f['job']['id'] );
	return $f;
};
foreach ( $tamper_cases as $label => $edit ) {
	$f = $make_tamper_fixture();
	$job_id = (int) $f['job']['id'];
	$json = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT plan_json FROM $jobs_table WHERE id=%d", $job_id ) ), true );
	$edit( $json );
	$wpdb->update( $jobs_table, array( 'plan_json' => WriteLeash\Plan_Hasher::canonical_json( $json ) ), array( 'id' => $job_id ) );
	$result = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 1 ) ) );
	eq( $result['stop'], 'JOB_MATERIAL_MISMATCH', 'tamper typed refusal: ' . $label );
	eq( $result['processed'], 0, 'tamper zero processed: ' . $label );
	eq( saves( $f['ids'][0] ), 0, 'tamper zero mutation: ' . $label );
	eq( Repo::read( $job_id )['status'], JState::NEEDS_REVIEW, 'tamper needs review: ' . $label );
	eq( Repo::counts( $job_id )['pending'], 1, 'tampered item untouched: ' . $label );
}
$set_column = static function ( int $job_id, string $column, string $value ) use ( $wpdb, $jobs_table ): void {
	$wpdb->update( $jobs_table, array( $column => $value ), array( 'id' => $job_id ) );
};
$f = $make_tamper_fixture();
$set_column( (int) $f['job']['id'], 'plan_hash', str_repeat( '0', 64 ) );
$result = run_worker( array( 'job_id' => (int) $f['job']['id'], 'mode' => 'run', 'limits' => limits( 1 ) ) );
eq( $result['stop'], 'JOB_MATERIAL_MISMATCH', 'plan_hash tamper refused' );
eq( saves( $f['ids'][0] ), 0, 'plan_hash tamper zero mutation' );
$f = $make_tamper_fixture();
$set_column( (int) $f['job']['id'], 'plan_id', 'tampered-plan-id' );
$result = run_worker( array( 'job_id' => (int) $f['job']['id'], 'mode' => 'run', 'limits' => limits( 1 ) ) );
eq( $result['stop'], 'JOB_MATERIAL_MISMATCH', 'plan_id tamper refused' );
eq( saves( $f['ids'][0] ), 0, 'plan_id tamper zero mutation' );
$f = $make_tamper_fixture();
$wpdb->update( $items_table, array( 'planned_price' => '10.00' ), array( 'job_id' => (int) $f['job']['id'] ) );
$result = run_worker( array( 'job_id' => (int) $f['job']['id'], 'mode' => 'run', 'limits' => limits( 1 ) ) );
eq( $result['stop'], 'JOB_MATERIAL_MISMATCH', 'item target tamper refused' );
eq( saves( $f['ids'][0] ), 0, 'item target tamper zero mutation' );
$item_row = reset( item_map( (int) $f['job']['id'] ) );
eq( $item_row['state'], IState::NEEDS_REVIEW, 'item target tamper needs review' );
$f = $make_tamper_fixture();
$item_row = reset( item_map( (int) $f['job']['id'] ) );
$wpdb->update( $items_table, array( 'product_id' => 999999 ), array( 'id' => (int) $item_row['id'] ) );
$result = run_worker( array( 'job_id' => (int) $f['job']['id'], 'mode' => 'run', 'limits' => limits( 1 ) ) );
eq( $result['stop'], 'JOB_MATERIAL_MISMATCH', 'item product tamper refused' );
eq( saves( $f['ids'][0] ), 0, 'item product tamper zero mutation' );
marker( 'material tampering fails closed with zero mutation' );

// ---------------------------------------------------------------------------
// Cancel: stop claiming, retain applied facts, no rollback.
// ---------------------------------------------------------------------------
$f = job_fixture( array( 'changing' => 6 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 2 ) ) );
$counts = Repo::counts( $job_id );
eq( $counts['applied'], 2, 'cancel fixture applied two' );
ok( Repo::cancel( $job_id, 1 ), 'cancel accepted' );
$row = Repo::read( $job_id );
eq( $row['status'], JState::CANCELLED, 'cancel persists terminal state' );
eq( $row['status_reason'], 'OPERATOR_CANCELLED', 'cancel typed reason' );
eq( run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 6 ) ) )['stop'], 'JOB_TERMINAL', 'cancelled job never claims again' );
eq( run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 6 ), 'manual' => true ) )['stop'], 'JOB_TERMINAL', 'manual resume cannot uncancel' );
$counts = assert_counts( $job_id, 'cancel' );
eq( $counts['applied'], 2, 'applied facts retained after cancel' );
eq( $counts['pending'], 4, 'pending items retained truthfully' );
foreach ( array_slice( $f['ids'], 0, 2 ) as $id ) { eq( fresh_price( $id ), '80.00', 'cancelled job keeps applied price ' . $id ); }
marker( 'cancel: stops claims, retains applied/pending truth, no rollback' );

// ---------------------------------------------------------------------------
// Deactivation: no new claims, history retained, reactivation resumes.
// ---------------------------------------------------------------------------
$f = job_fixture( array( 'changing' => 4 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 2 ) ) );
$before_saves = saves( $f['ids'][2] );
\WriteLeash\Lifecycle::deactivate();
eq( get_option( 'writeleash_runner_state' ), 'deactivated', 'deactivation persisted' );
eq( count( as_get_scheduled_actions( array( 'hook' => Scheduler::HOOK, 'group' => Scheduler::GROUP, 'status' => 'pending' ), 'ids' ) ), 0, 'deactivation cancels only owned wake-ups' );
$blocked = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 4 ) ) );
eq( $blocked['stop'], 'DEACTIVATED', 'worker refuses while deactivated' );
eq( $blocked['processed'], 0, 'deactivated zero processed' );
eq( saves( $f['ids'][2] ), $before_saves, 'deactivated zero new claims' );
$row = Repo::read( $job_id );
eq( $row['status'], JState::PAUSED, 'deactivated job pauses' );
eq( $row['status_reason'], 'DEACTIVATED', 'deactivated typed reason' );
eq( Repo::counts( $job_id )['applied'], 2, 'in-flight applied items retained' );
\WriteLeash\Lifecycle::activate();
eq( get_option( 'writeleash_runner_state' ), 'active', 'reactivation restores runner' );
eq( saves( $f['ids'][2] ), $before_saves, 'reactivation mutates nothing by itself' );
eq( run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 4 ), 'manual' => true ) )['status'], JState::COMPLETED, 'manual resume after reactivation completes' );
marker( 'deactivation/reactivation: claims stop, history retained, explicit resume' );

// ---------------------------------------------------------------------------
// Progress timestamps distinguish running/queued/waiting/paused.
// ---------------------------------------------------------------------------
$row = Repo::read( $job_id );
foreach ( array( 'created_at', 'approved_at', 'queued_at', 'started_at', 'completed_at', 'updated_at', 'last_worker_at' ) as $field ) {
	ok( ! empty( $row[$field] ), 'timestamp populated: ' . $field );
}
ok( strtotime( $row['completed_at'] ) >= strtotime( $row['created_at'] ), 'completion after creation' );
ok( strtotime( $row['approved_at'] ) >= strtotime( $row['created_at'] ), 'approval order' );
$paused_fixture = job_fixture( array( 'changing' => 4 ) );
approves( $paused_fixture );
run_worker( array( 'job_id' => (int) $paused_fixture['job']['id'], 'mode' => 'run', 'limits' => limits( 2 ) ) );
\WriteLeash\Lifecycle::deactivate();
run_worker( array( 'job_id' => (int) $paused_fixture['job']['id'], 'mode' => 'run', 'limits' => limits( 2 ) ) );
$paused_row = Repo::read( (int) $paused_fixture['job']['id'] );
ok( ! empty( $paused_row['paused_at'] ), 'paused_at persisted' );
eq( $paused_row['status'], JState::PAUSED, 'paused state persisted for display' );
\WriteLeash\Lifecycle::activate();
marker( 'progress timestamps distinguish lifecycle stages' );

// ---------------------------------------------------------------------------
// #109 transactional job-row fence. The job lease and the #108 item
// transaction are mutually exclusive: takeover/cancel/reaper cannot become
// authoritative while an item transaction holds the job row lock. Lease
// expiry only makes takeover eligible; the generation bump is the authority.
// ---------------------------------------------------------------------------
function fence_facts( string $barrier ): array {
	$parts = explode( ':', (string) file_get_contents( $barrier ) );
	return array( 'point' => (string) ( $parts[0] ?? '' ), 'generation' => (int) ( $parts[1] ?? 0 ), 'connection' => (int) ( $parts[2] ?? 0 ) );
}
function mutator_facts( string $barrier ): array {
	$parts = explode( ':', (string) file_get_contents( $barrier ) );
	return array( 'point' => (string) ( $parts[0] ?? '' ), 'connection' => (int) ( $parts[1] ?? 0 ) );
}
function await_session( string $needle, float $timeout = 10 ): bool {
	$db = Verifier::observer();
	try {
		$deadline = microtime( true ) + $timeout;
		while ( microtime( true ) < $deadline ) {
			foreach ( $db->get_results( 'SHOW FULL PROCESSLIST', ARRAY_A ) as $process ) {
				if ( str_contains( (string) ( $process['Info'] ?? '' ), $needle ) ) { return true; }
			}
			usleep( 50000 );
		}
		return false;
	} finally { $db->close(); }
}
function job_row_locked_nowait( int $job_id ): bool {
	$db = Verifier::observer();
	$db->suppress_errors( true );
	try {
		$db->get_var( $db->prepare( 'SELECT lease_generation FROM %i WHERE id=%d FOR UPDATE NOWAIT', Schema::jobs_table( $db ), $job_id ) );
		return '' !== (string) $db->last_error;
	} finally { $db->close(); }
}
function hook_connections( int $id ): array {
	global $hook_log;
	if ( ! is_file( $hook_log ) ) { return array(); }
	$connections = array();
	foreach ( file( $hook_log, FILE_IGNORE_NEW_LINES ) as $line ) {
		$entry = json_decode( $line, true );
		if ( is_array( $entry ) && $id === (int) ( $entry['id'] ?? 0 ) ) { $connections[] = (int) ( $entry['connection'] ?? 0 ); }
	}
	return array_values( array_unique( $connections ) );
}
function expire_lease( int $job_id ): void {
	global $wpdb, $jobs_table;
	$rows = $wpdb->query( $wpdb->prepare( "UPDATE $jobs_table SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=%d", $job_id ) );
	if ( 1 !== $rows ) { throw new RuntimeException( 'lease expiry failed: rows=' . var_export( $rows, true ) . ' error=' . $wpdb->last_error ); }
	$expired = $wpdb->get_var( $wpdb->prepare( "SELECT (lease_expires_at<UTC_TIMESTAMP()) FROM $jobs_table WHERE id=%d", $job_id ) );
	if ( '1' !== (string) $expired ) { throw new RuntimeException( 'lease not expired after update' ); }
}

// Race A: takeover attempt while an item transaction holds the fence lock.
$f = job_fixture( array( 'changing' => 2 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
$prefix = $work . '/fence-a-' . bin2hex( random_bytes( 5 ) );
$a = start_worker( array(
	'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 1, 30 ),
	'checkpoint' => 'AFTER_CLAIM', 'fault' => 'wait', 'barrier' => $prefix . '.barrier', 'release' => $prefix . '.release',
	'mutator_checkpoint' => 'ITEM_LOCKED', 'mutator_fault' => 'wait', 'mutator_barrier' => $prefix . '.mutbarrier', 'mutator_release' => $prefix . '.mutrelease',
	'lock_wait_timeout' => 20,
) );
await_file( $prefix . '.barrier' );
$claim_facts = fence_facts( $prefix . '.barrier' );
eq( $claim_facts['point'], 'AFTER_CLAIM', 'A claimed under its generation before entering the item transaction' );
expire_lease( $job_id );
file_put_contents( $prefix . '.release', 'go' );
await_file( $prefix . '.mutbarrier' );
$mut_facts = mutator_facts( $prefix . '.mutbarrier' );
eq( $mut_facts['point'], 'ITEM_LOCKED', 'A paused inside the #108 item transaction after the fence lock' );
eq( $mut_facts['connection'], $claim_facts['connection'], 'item transaction runs on the claimed writer connection' );
eq( job_row_locked_nowait( $job_id ), true, 'A holds the job row FOR UPDATE lock inside the item transaction' );
$b = start_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 2, 30 ), 'lock_wait_timeout' => 20 ) );
await_file( $b['spec']['started'] );
$takeover_blocked = true;
$deadline = microtime( true ) + 3;
while ( microtime( true ) < $deadline ) {
	if ( (int) Repo::read( $job_id )['lease_generation'] !== $claim_facts['generation'] || is_file( $b['spec']['result'] ) ) { $takeover_blocked = false; break; }
	usleep( 100000 );
}
eq( $takeover_blocked, true, 'takeover cannot become authoritative while A holds the transactional fence' );
eq( await_session( 'SET lease_owner=', 10 ), true, 'B takeover UPDATE is queued behind the item transaction row lock' );
file_put_contents( $prefix . '.mutrelease', 'go' );
$ra = finish_worker( $a );
$rb = finish_worker( $b );
eq( $ra['processed'], 1, 'A commits its one claimed item before any takeover' );
eq( $rb['generation'], $claim_facts['generation'] + 1, 'B generation=N+1 only after A commits' );
eq( Repo::read( $job_id )['status'], JState::COMPLETED, 'transactional fence race job completes' );
foreach ( $f['ids'] as $id ) { eq( saves( $id ), 1, 'fence race exactly one save ' . $id ); eq( fresh_price( $id ), '80.00', 'fence race target ' . $id ); }
$journal = journal_row( $f['plan']->data()['plan_id'], (int) $f['ids'][0] );
$evidence = json_decode( (string) $journal['evidence'], true );
eq( $journal['state'], 'APPLIED', 'A committed item is durably APPLIED' );
eq( (int) $evidence['fence_connection_id'], $claim_facts['connection'], 'job fence lock connection equals item writer connection' );
eq( (int) $evidence['connection_id'], $claim_facts['connection'], 'journal write connection equals item writer connection' );
eq( in_array( $claim_facts['connection'], hook_connections( (int) $f['ids'][0] ), true ), true, 'Woo save hooks ran on the item writer connection' );
echo "#109 transactional fence same connection: fence={$evidence['fence_connection_id']} journal={$evidence['connection_id']} woo=" . implode( ',', hook_connections( (int) $f['ids'][0] ) ) . "\n";
echo "#109 transactional fence takeover:\nA owns generation={$claim_facts['generation']}\nA holds job row lock inside item transaction\nB takeover blocked\nA commit\nB generation={$rb['generation']} only after commit\nPASS\n";
marker( 'takeover blocked during item transaction: mutually exclusive job fence and #108 transaction' );

// Race B: a newer generation is already authoritative; the inner fence fails.
$f = job_fixture( array( 'changing' => 1 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
$a = start_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 1, 30 ), 'checkpoint' => 'BEFORE_MUTATION', 'fault' => 'wait', 'lock_wait_timeout' => 20 ) );
await_file( $a['spec']['barrier'] );
$facts_a = fence_facts( $a['spec']['barrier'] );
eq( $facts_a['point'], 'BEFORE_MUTATION', 'A passed the outer pre-dispatch fence before B is authoritative' );
expire_lease( $job_id );
$b = start_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 1, 30 ), 'checkpoint' => 'AFTER_LEASE', 'fault' => 'wait', 'lock_wait_timeout' => 20 ) );
await_file( $b['spec']['barrier'] );
$facts_b = fence_facts( $b['spec']['barrier'] );
eq( $facts_b['generation'], $facts_a['generation'] + 1, 'B generation=N+1 already authoritative' );
file_put_contents( $a['spec']['release'], 'go' );
$stale = finish_worker( $a );
eq( $stale['stop'], 'FENCE_LOST', 'A inner transactional fence verify fails' );
eq( $stale['processed'], 0, 'A processed zero items' );
eq( saves( $f['ids'][0] ), 0, 'A Woo saves zero' );
eq( journal_row( $f['plan']->data()['plan_id'], (int) $f['ids'][0] )['state'], 'PENDING', 'A journal not APPLIED' );
echo "#109 transactional fence stale worker:\nB generation={$facts_b['generation']} already authoritative\nA inner fence verify fails\nA Woo saves=0\nPASS\n";
file_put_contents( $b['spec']['release'], 'go' );
finish_worker( $b );
eq( Repo::read( $job_id )['status'], JState::COMPLETED, 'B completes safely after refusing stale A' );
eq( saves( $f['ids'][0] ), 1, 'B applies exactly once' );
eq( fresh_price( $f['ids'][0] ), '80.00', 'B durable target' );
marker( 'new generation wins first: inner transactional fence FENCE_LOST, zero stale save' );

// Race C: stale claim after takeover, exercised past the outer fence check.
$f = job_fixture( array( 'changing' => 1 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
$a = start_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 1, 30 ), 'checkpoint' => 'BEFORE_MUTATION', 'fault' => 'wait', 'lock_wait_timeout' => 20 ) );
await_file( $a['spec']['barrier'] );
$facts_a = fence_facts( $a['spec']['barrier'] );
expire_lease( $job_id );
$b = finish_worker( start_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 1, 30 ), 'lock_wait_timeout' => 20 ) ) );
eq( $b['generation'], $facts_a['generation'] + 1, 'new generation processes the stale claim first' );
eq( $b['status'], JState::COMPLETED, 'new generation completes the item' );
eq( saves( $f['ids'][0] ), 1, 'new generation one save' );
file_put_contents( $a['spec']['release'], 'go' );
$stale = finish_worker( $a );
eq( $stale['stop'], 'FENCE_LOST', 'stale claim past the outer fence is refused by the inner guard' );
eq( $stale['processed'], 0, 'stale worker performs zero mutations' );
eq( saves( $f['ids'][0] ), 1, 'stale worker adds no Woo save' );
eq( journal_row( $f['plan']->data()['plan_id'], (int) $f['ids'][0] )['state'], 'APPLIED', 'journal remains the new generation APPLIED' );
marker( 'stale claimed item after takeover: inner transactional fence refuses, no replay' );

// Race D: operator cancel while the item transaction holds the fence lock.
$f = job_fixture( array( 'changing' => 2 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
$a = start_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 1, 30 ), 'mutator_checkpoint' => 'ITEM_LOCKED', 'mutator_fault' => 'wait', 'lock_wait_timeout' => 20 ) );
await_file( $a['spec']['barrier'] );
$mut_facts = mutator_facts( $a['spec']['barrier'] );
eq( $mut_facts['point'], 'ITEM_LOCKED', 'item transaction paused after the fence lock for cancel' );
$generation_a = (int) Repo::read( $job_id )['lease_generation'];
eq( job_row_locked_nowait( $job_id ), true, 'item transaction holds the job row lock before cancel' );
$cancel = start_worker( array( 'job_id' => $job_id, 'actor' => 1, 'lock_wait_timeout' => 20 ), 'cancel.php' );
await_file( $cancel['spec']['started'] );
eq( await_session( "status='CANCELLED'", 10 ), true, 'cancel UPDATE is queued behind the item transaction' );
$cancel_blocked = true;
$deadline = microtime( true ) + 2;
while ( microtime( true ) < $deadline ) {
	$row = Repo::read( $job_id );
	if ( JState::RUNNING !== $row['status'] || (int) $row['lease_generation'] !== $generation_a || is_file( $cancel['spec']['result'] ) ) { $cancel_blocked = false; break; }
	usleep( 100000 );
}
eq( $cancel_blocked, true, 'cancel cannot revoke ownership mid-item' );
file_put_contents( $a['spec']['release'], 'go' );
finish_worker( $a );
$rc = finish_worker( $cancel );
eq( $rc['result'], true, 'cancel completes after the item commits' );
$row = Repo::read( $job_id );
eq( $row['status'], JState::CANCELLED, 'cancel is authoritative after the item boundary' );
eq( $row['status_reason'], 'OPERATOR_CANCELLED', 'cancel typed reason' );
$counts = assert_counts( $job_id, 'transactional-cancel' );
eq( $counts['applied'], 1, 'already committed item remains APPLIED' );
eq( $counts['pending'], 1, 'pending item remains unprocessed' );
eq( saves( $f['ids'][0] ), 1, 'committed item saved exactly once' );
eq( saves( $f['ids'][1] ), 0, 'pending item never saved' );
echo "#109 transactional fence cancel:\ncancel cannot revoke mid-item\napplied item truthful\nno later stale claims\nPASS\n";
marker( 'cancel during in-flight item: waits for COMMIT, keeps applied facts, stops later claims' );

// Race E: activation-style reaper while the item transaction holds the fence.
$f = job_fixture( array( 'changing' => 2 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
$prefix = $work . '/reap-' . bin2hex( random_bytes( 5 ) );
$a = start_worker( array(
	'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 1, 30 ),
	'checkpoint' => 'AFTER_CLAIM', 'fault' => 'wait', 'barrier' => $prefix . '.barrier', 'release' => $prefix . '.release',
	'mutator_checkpoint' => 'ITEM_LOCKED', 'mutator_fault' => 'wait', 'mutator_barrier' => $prefix . '.mutbarrier', 'mutator_release' => $prefix . '.mutrelease',
	'lock_wait_timeout' => 20,
) );
await_file( $prefix . '.barrier' );
$claim_facts = fence_facts( $prefix . '.barrier' );
expire_lease( $job_id );
$wpdb->query( $wpdb->prepare( "UPDATE $jobs_table SET updated_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 120 SECOND) WHERE id=%d", $job_id ) );
file_put_contents( $prefix . '.release', 'go' );
await_file( $prefix . '.mutbarrier' );
$mut_facts = mutator_facts( $prefix . '.mutbarrier' );
eq( $mut_facts['point'], 'ITEM_LOCKED', 'item transaction paused after the fence lock for the reaper' );
$reap = start_worker( array( 'job_id' => $job_id, 'lock_wait_timeout' => 20 ), 'reap.php' );
await_file( $reap['spec']['started'] );
eq( await_session( "status_reason='LEASE_RECOVERY'", 10 ), true, 'reaper UPDATE is queued behind the item transaction' );
$reap_blocked = true;
$deadline = microtime( true ) + 2;
while ( microtime( true ) < $deadline ) {
	$row = Repo::read( $job_id );
	if ( JState::RUNNING !== $row['status'] || (int) $row['lease_generation'] !== $claim_facts['generation'] || is_file( $reap['spec']['result'] ) ) { $reap_blocked = false; break; }
	usleep( 100000 );
}
eq( $reap_blocked, true, 'reaper cannot revoke ownership mid-item' );
file_put_contents( $prefix . '.mutrelease', 'go' );
finish_worker( $a );
$rr = finish_worker( $reap );
ok( (int) $rr['reaped'] >= 1, 'reaper completes after the item boundary' );
$row = Repo::read( $job_id );
eq( $row['status'], JState::PAUSED, 'reaper is authoritative after the item boundary' );
eq( $row['status_reason'], 'LEASE_RECOVERY', 'reaper typed reason' );
$counts = assert_counts( $job_id, 'transactional-reap' );
eq( $counts['applied'], 1, 'reaper keeps the committed item' );
eq( $counts['pending'], 1, 'reaper leaves pending items unprocessed' );
eq( saves( $f['ids'][1] ), 0, 'reaper never mutates' );
marker( 'reaper/deactivation path waits for the in-flight item transaction' );

// Crash while holding the transactional fence, before COMMIT.
$f = job_fixture( array( 'changing' => 1 ) );
$job_id = (int) $f['job']['id'];
approves( $f );
Worker::queue_job( $job_id );
$product_id = (int) $f['ids'][0];
$plan_id = $f['plan']->data()['plan_id'];
$prefix = $work . '/crash-' . bin2hex( random_bytes( 5 ) );
$a = start_worker( array(
	'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 1, 30 ),
	'checkpoint' => 'AFTER_CLAIM', 'fault' => 'wait', 'barrier' => $prefix . '.barrier', 'release' => $prefix . '.release',
	'mutator_checkpoint' => 'AFTER_WOO_SAVE_BEFORE_JOURNAL', 'mutator_fault' => 'wait', 'mutator_barrier' => $prefix . '.mutbarrier', 'mutator_release' => $prefix . '.mutrelease',
	'lock_wait_timeout' => 20,
) );
await_file( $prefix . '.barrier' );
$claim_facts = fence_facts( $prefix . '.barrier' );
expire_lease( $job_id );
file_put_contents( $prefix . '.release', 'go' );
await_file( $prefix . '.mutbarrier' );
eq( saves( $product_id ), 1, 'Woo save executed inside the uncommitted item transaction' );
// Independent connection read: parent-side cache invalidation would touch
// option rows that the paused worker holds locked and would deadlock the test.
$observer = Verifier::observer();
try { $uncommitted = Verifier::storage( $observer, $product_id ); }
finally { $observer->close(); }
eq( Decimal::parse( $uncommitted['meta']['_regular_price'][0] ), Decimal::parse( '100.00' ), 'uncommitted Woo save is not durable' );
eq( journal_row( $plan_id, $product_id )['state'], 'PENDING', 'journal not APPLIED before COMMIT' );
eq( job_row_locked_nowait( $job_id ), true, 'A holds the fence at the crash point' );
$probe = start_worker( array( 'job_id' => $job_id, 'owner' => wp_generate_uuid4(), 'manual' => true, 'lock_wait_timeout' => 20 ), 'lease-probe.php' );
await_file( $probe['spec']['started'] );
eq( await_session( 'SET lease_owner=', 10 ), true, 'takeover blocked while A holds the crash-point fence' );
proc_terminate( $a['proc'], 9 );
finish_worker( $a, true );
$probe_result = finish_worker( $probe );
ok( ! empty( $probe_result['lease'] ), 'server rollback released the fence lock after SIGKILL' );
eq( (int) $probe_result['lease']['generation'], $claim_facts['generation'] + 1, 'takeover generation after crash rollback' );
eq( fresh_price( $product_id ), '100.00', 'original durable price after rollback' );
eq( journal_row( $plan_id, $product_id )['state'], 'PENDING', 'journal not APPLIED after rollback' );
expire_lease( $job_id );
$b = run_worker( array( 'job_id' => $job_id, 'mode' => 'run', 'limits' => limits( 1, 30 ), 'manual' => true, 'lock_wait_timeout' => 20 ) );
eq( $b['status'], JState::COMPLETED, 'recovery worker completes after crash' );
eq( fresh_price( $product_id ), '80.00', 'recovery applies the target' );
eq( journal_row( $plan_id, $product_id )['state'], 'APPLIED', 'recovery journal APPLIED' );
eq( saves( $product_id ), 2, 'rolled-back Woo save replays once on recovery' );
echo "#109 transactional fence crash:\nA killed while holding fence\nDB rollback releases lock\nB takeover/recovery PASS\n";
marker( 'SIGKILL while holding the transactional fence: rollback, release, safe recovery' );

// Deadlock/lock-timeout stress: different jobs on one product overlap in
// real processes with independent job-row fences and shared Woo rows.
$product = create_product( '100.00' );
$j1 = single_job( $product, '80.00' );
$j2 = single_job( $product, '90.00' );
$x = start_worker( array( 'job_id' => (int) $j1['job']['id'], 'mode' => 'run', 'limits' => limits( 1, 30 ), 'lock_wait_timeout' => 20 ) );
$y = start_worker( array( 'job_id' => (int) $j2['job']['id'], 'mode' => 'run', 'limits' => limits( 1, 30 ), 'lock_wait_timeout' => 20 ) );
finish_worker( $x );
finish_worker( $y );
$statuses = array( Repo::read( (int) $j1['job']['id'] )['status'], Repo::read( (int) $j2['job']['id'] )['status'] );
sort( $statuses );
eq( $statuses, array( JState::COMPLETED, JState::COMPLETED_WITH_ISSUES ), 'different jobs same product: one applies, one conflicts, no deadlock' );
eq( saves( $product ), 1, 'different jobs same product: exactly one Woo save' );
ok( in_array( fresh_price( $product ), array( '80.00', '90.00' ), true ), 'winner target durable' );
echo "#109 transactional fence deadlock stress: same job duplicate / different jobs same product / cancel / takeover finished without DB deadlock or lock wait timeout\n";
marker( 'overlapping item transactions: deterministic lock order, no deadlock or timeout outcome' );

echo "#109 complete durable job engine lab: {$assertions} assertions\n";
