<?php
// #298 standing-grant integration (container gate).
// Runs inside the #109 fixture via wp eval-file:
//   WL109_CACHE=default wp --path=$site eval-file /opt/tests/jobs/grant-integration.php
// Requires MySQL 8.0.44 / MariaDB 10.11.15, pinned WP 7.1.2 / PHP 8.2 / Woo 11.1.2,
// default or Redis object cache. Uses real Woo CRUD, real InnoDB row locks,
// real Action Scheduler state and the filesystem hook surrogate for save counts.
// Proves every required category with durable rows: identity/approval, scope,
// expiry/revocation/disable, budget/recovery, idempotency, audit/journal.
// True two-session overlap uses real proc_open workers with deterministic
// barriers (same harness as jobs/integration.php worker.php).
use WriteLeash\Grant_Repository as Grants;
use WriteLeash\Grant_Schema as GSchema;
use WriteLeash\Job_Item_State as IState;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Job_Scheduler as Scheduler;
use WriteLeash\Job_Schema as Schema;
use WriteLeash\Job_State as JState;
use WriteLeash\Job_Worker as Worker;
use WriteLeash\Price_Apply_Journal as Journal;
use WriteLeash\Price_Cache_Verifier as Verifier;
use WriteLeash\Price_Operation as Operation;
use WriteLeash\Price_Selection_Spec as Selection;
use WriteLeash\Safety_Policy as Policy;
use WriteLeash\Woo_Price_Planner as Planner;
use WriteLeash\Undo_Repository as URepo;
use WriteLeash\Undo_Worker as UWorker;
use WriteLeash\Undo_Item_State as UItem;
use WriteLeash\Undo_Schema as USchema;

global $wpdb, $work, $assertions, $hook_log, $items_table, $jobs_table;
$assertions = 0;
$work = sys_get_temp_dir() . '/wl298-' . bin2hex( random_bytes( 5 ) );
mkdir( $work );
$hook_log = $work . '/hooks.log';
touch( $hook_log );
$GLOBALS['wl108_active'] = true;
$GLOBALS['wl108_log'] = $hook_log;
// Audit-store fault injection: the production audit seam fires for every
// append; a test can make the named event throw to prove fail-closed rollback.
$GLOBALS['wl298_audit_fault'] = null;
add_action( 'writeleash_grant_audit_before_insert', function ( $event ) {
	if ( ( $GLOBALS['wl298_audit_fault'] ?? null ) === $event ) { throw new RuntimeException( 'controlled-audit-fault' ); }
} );
@unlink( WP_CONTENT_DIR . '/debug.log' );

function eq( $actual, $expected, string $label ): void {
	global $assertions;
	++$assertions;
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
}
function ok( bool $condition, string $label ): void { eq( $condition, true, $label ); }
function marker( string $label ): void {
	global $assertions;
	echo "#298 $label: PASS ($assertions assertions)\n";
}
function create_product( string $price, string $status = 'publish' ): int {
	// #307: the perimeter guard denies a grant-restricted principal every
	// catalogue write outside an Execution Frame, so fixtures seed products as
	// an operator. The grant actor is made current only for planning and job
	// submission, never for a raw `$product->save()`.
	$previous = get_current_user_id();
	wp_set_current_user( 1 );
	$p = new WC_Product_Simple();
	$p->set_name( 'WL298-' . wp_generate_uuid4() );
	$p->set_status( $status );
	$p->set_regular_price( $price );
	$p->save();
	$id = $p->get_id();
	wp_set_current_user( $previous );
	return $id;
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
function catalog_hash( array $ids ): string {
	global $wpdb;
	$parts = array();
	if ( $ids ) {
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$parts[] = $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_title,post_status,post_type FROM {$wpdb->posts} WHERE ID IN ($placeholders) ORDER BY ID", $ids ), ARRAY_A );
		$parts[] = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id,post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) ORDER BY meta_id", $ids ), ARRAY_A );
		$parts[] = $wpdb->get_results( $wpdb->prepare( "SELECT product_id,min_price,max_price,onsale FROM {$wpdb->wc_product_meta_lookup} WHERE product_id IN ($placeholders) ORDER BY product_id", $ids ), ARRAY_A );
	}
	return hash( 'sha256', serialize( $parts ) );
}
function actor_row_locked_nowait( int $actor_id ): bool {
	global $wpdb;
	$db = Verifier::observer();
	$db->suppress_errors( true );
	try {
		$db->get_var( $db->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE NOWAIT", $actor_id ) );
		$error = (string) $db->last_error;
		if ( '' === $error ) { return false; }
		if ( false === stripos( $error, 'lock' ) ) { throw new RuntimeException( 'actor lock probe failed: ' . $error ); }
		return true;
	} finally { $db->close(); }
}
function lock_diagnostics( int $thread_id ): string {
	global $wpdb;
	$previous = $wpdb->suppress_errors( true );
	$trx = $wpdb->get_results( $wpdb->prepare( 'SELECT trx_mysql_thread_id AS thread, trx_state AS state, trx_rows_locked AS rows_locked, trx_tables_locked AS tables_locked, trx_isolation_level AS isolation FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=%d', $thread_id ), ARRAY_A );
	$trx_text = is_array( $trx ) && '' === (string) $wpdb->last_error ? wp_json_encode( $trx ) : 'err:' . (string) $wpdb->last_error;
	$wpdb->last_error = '';
	$locks = $wpdb->get_results( $wpdb->prepare( 'SELECT OBJECT_NAME AS object_name, LOCK_MODE AS lock_mode, LOCK_DATA AS lock_data FROM performance_schema.data_locks dl INNER JOIN performance_schema.threads th ON th.THREAD_ID=dl.THREAD_ID WHERE th.PROCESSLIST_ID=%d', $thread_id ), ARRAY_A );
	$user_locks = array();
	$count = 0;
	if ( is_array( $locks ) && '' === (string) $wpdb->last_error ) {
		$count = count( $locks );
		foreach ( $locks as $lock ) {
			$name = (string) ( $lock['object_name'] ?? '' );
			if ( false !== strpos( $name, 'users' ) ) { $user_locks[] = $lock; }
		}
		$lock_text = 'count=' . $count . ' user_locks=' . wp_json_encode( $user_locks );
	} else {
		$lock_text = 'err:' . (string) $wpdb->last_error;
	}
	$wpdb->suppress_errors( $previous );
	return 'innodb_trx=' . $trx_text . ' data_locks=' . $lock_text;
}
function merchant_alive( int $pid ): string {
	if ( $pid < 1 ) { return 'unknown'; }
	return is_dir( '/proc/' . $pid ) ? 'yes' : 'no';
}
function await_grant_insert( int $exclude_thread, string $result_file, int $actor_id, float $timeout = 15, int $merchant_pid = 0 ): void {
	global $wpdb;
	$deadline = microtime( true ) + $timeout;
	$hits = 0;
	while ( true ) {
		if ( is_file( $result_file ) && '' !== (string) file_get_contents( $result_file ) ) {
			$still = 'unknown';
			try { $still = actor_row_locked_nowait( $actor_id ) ? 'yes' : 'no'; } catch ( \Throwable $error ) { $still = 'probe:' . $error->getMessage(); }
			throw new RuntimeException( 'create_grant finished without waiting for the item user lock merchant_pid=' . $merchant_pid . ' merchant_alive=' . merchant_alive( $merchant_pid ) . ' user_lock_still=' . $still . ' ' . lock_diagnostics( $exclude_thread ) );
		}
		$waiting = false;
		$rows = $wpdb->get_results( 'SELECT ID, INFO FROM information_schema.PROCESSLIST', ARRAY_A );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( (int) $row['ID'] === $exclude_thread ) { continue; }
				$info = (string) ( $row['INFO'] ?? '' );
				if ( false === strpos( $info, (string) $actor_id ) ) { continue; }
				$user_lock = false !== stripos( $info, 'for update' ) && false !== strpos( $info, $wpdb->users );
				$grant_insert = false !== stripos( $info, 'insert' ) && false !== strpos( $info, 'writeleash_grants' );
				if ( $user_lock || $grant_insert ) { $waiting = true; break; }
			}
		}
		// Two polls, so a statement that finished between them is not a wait.
		$hits = $waiting ? $hits + 1 : 0;
		if ( $hits >= 2 ) { return; }
		if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'create_grant did not block on the item user lock merchant_pid=' . $merchant_pid . ' merchant_alive=' . merchant_alive( $merchant_pid ) . ' ' . lock_diagnostics( $exclude_thread ) ); }
		usleep( 20000 );
	}
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
	clearstatcache( true, $file );
	while ( ! is_file( $file ) || '' === (string) file_get_contents( $file ) ) {
		if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'missing deterministic barrier: ' . $file ); }
		usleep( 10000 );
		clearstatcache( true, $file );
	}
}
/**
 * Wait until a worker connection is visibly running a blocking query. The
 * item transaction still holds its lock when this returns, so observing the
 * worker's SELECT is proof it is waiting rather than racing the release.
 */
function await_worker_query( int $thread_id, string $needle, float $timeout = 25 ): void {
	global $wpdb;
	$deadline = microtime( true ) + $timeout;
	while ( true ) {
		$info = $wpdb->get_var( $wpdb->prepare( 'SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=%d', $thread_id ) );
		if ( is_string( $info ) && str_contains( $info, $needle ) ) { return; }
		if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'worker query not observed for thread ' . $thread_id . ': ' . $needle ); }
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
function grant_audit_rows( string $grant_public_id ): array {
	global $wpdb;
	$grant = Grants::read_by_public_id( $grant_public_id );
	if ( ! $grant ) { return array(); }
	$audit = GSchema::audit_table( $wpdb );
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grant_db_id=%d ORDER BY id', $audit, (int) $grant['id'] ), ARRAY_A );
}
function grant_row( string $public_id ): array {
	$row = Grants::read_by_public_id( $public_id );
	if ( ! $row ) { throw new RuntimeException( 'grant missing: ' . $public_id ); }
	return $row;
}
function grant_count(): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', GSchema::grants_table( $wpdb ) ) );
}
function reservation_state( string $grant_public_id, int $job_id, int $product_id ): ?string {
	global $wpdb;
	$grant = Grants::read_by_public_id( $grant_public_id );
	if ( ! $grant ) { return null; }
	return $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM %i WHERE grant_db_id=%d AND job_id=%d AND product_id=%d', GSchema::reservations_table( $wpdb ), (int) $grant['id'], $job_id, $product_id ) );
}
function reservation_count( string $grant_public_id ): int {
	global $wpdb;
	$grant = Grants::read_by_public_id( $grant_public_id );
	if ( ! $grant ) { return 0; }
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE grant_db_id=%d', GSchema::reservations_table( $wpdb ), (int) $grant['id'] ) );
}
function job_id_for_plan( string $plan_id ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE plan_id=%s', Schema::jobs_table( $wpdb ), $plan_id ) );
}
function expect_grant_error( string $reason, callable $fn, string $label ): void {
	try { $fn(); throw new RuntimeException( $label . ': expected ' . $reason . ' but no error' ); }
	catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), $reason, $label ); }
}
function expect_job_error( string $reason, callable $fn, string $label ): void {
	try { $fn(); throw new RuntimeException( $label . ': expected ' . $reason . ' but no error' ); }
	catch ( WriteLeash\Job_Error $e ) { eq( $e->reason(), $reason, $label ); }
}
function ensure_users(): array {
	// Three distinct product managers: issuer, actor, human approver.
	$ids = array();
	foreach ( array( 'wl298-issuer', 'wl298-actor', 'wl298-approver' ) as $login ) {
		$existing = get_user_by( 'login', $login );
		if ( $existing ) { $ids[ $login ] = (int) $existing->ID; continue; }
		$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 20 ), 'role' => 'administrator', 'user_email' => $login . '@example.test' ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'user creation failed: ' . $login ); }
		$ids[ $login ] = (int) $id;
	}
	ok( count( array_unique( array_values( $ids ) ) ) === 3, 'three distinct grant identities' );
	return array( 'issuer' => $ids['wl298-issuer'], 'actor' => $ids['wl298-actor'], 'approver' => $ids['wl298-approver'] );
}

wp_set_current_user( 1 );
eq( \WriteLeash\Free_Support_Contract::woo_supported( WC_VERSION ), true, 'fixture Woo inside supported range' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wl108_hook_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, product_id bigint unsigned NOT NULL, hook varchar(100) NOT NULL) ENGINE=InnoDB" );
$users = ensure_users();
$issuer = $users['issuer'];
$actor = $users['actor'];
$approver = $users['approver'];
$items_table = Schema::items_table( $wpdb );
$jobs_table = Schema::jobs_table( $wpdb );

// ---------------------------------------------------------------------------
// Schema: grant family install (replayable) + v1->v2 job upgrade.
// ---------------------------------------------------------------------------
// The fixture site persists across cache iterations, so the family may
// already be installed: assert replayable install, never a fresh-only state.
eq( Schema::ready( $wpdb ), true, 'job schema v2 ready (upgraded with grant columns)' );
// First grant use installs the family + defaults the global disable to enabled.
wp_set_current_user( $issuer );
$probe_grant = Grants::create_grant( $issuer, $actor, 100, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
eq( GSchema::ready( $wpdb ), true, 'grant install ready' );
eq( Grants::is_disabled(), false, 'global disable defaults to enabled' );
GSchema::install();
GSchema::install();
eq( GSchema::ready( $wpdb ), true, 'grant install replay idempotent' );
$probe_row = grant_row( $probe_grant['public_id'] );
eq( (int) $probe_row['issuer_id'], $issuer, 'issuer persisted separately' );
eq( (int) $probe_row['actor_id'], $actor, 'actor persisted separately' );
// Job v2 columns present and ordinary jobs read as none/human.
$job_cols = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $jobs_table ), ARRAY_A );
$job_fields = array_column( $job_cols, 'Field' );
foreach ( array( 'approval_mode', 'grant_public_id', 'grant_version', 'grant_idempotency_key' ) as $col ) { ok( in_array( $col, $job_fields, true ), 'job v2 column ' . $col ); }
marker( 'grant schema install + job v2 migration' );

// ---------------------------------------------------------------------------
// Identity and approval.
// ---------------------------------------------------------------------------
wp_set_current_user( $actor );
$ids = array( create_product( '100.00' ), create_product( '100.00' ) );
sort( $ids, SORT_NUMERIC );
$plan = Planner::preview( Selection::ids( $ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
eq( (int) $plan->data()['actor_id'], $actor, 'frozen actor is the planner' );
wp_set_current_user( $issuer );
$grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
$accepted = Grants::accept_plan( $plan, $grant['public_id'], $issuer, 'id-ident-1' );
$job = $accepted['job'];
eq( $accepted['replayed'], false, 'first acceptance not a replay' );
eq( (int) $job['creator_id'], $actor, 'grant job creator is the actor' );
eq( (int) $job['approver_id'], 0, 'grant job fabricates no human approver' );
eq( $job['approval_mode'], 'grant', 'grant mode distinct from PLANNED none' );
eq( $job['status'], JState::READY, 'grant acceptance lands READY without human approval' );
eq( $job['grant_public_id'], $grant['public_id'], 'grant pin on job' );
ok( (int) $job['grant_version'] >= 1, 'grant version pinned' );
// Ordinary unapproved PLANNED is mode none, approver 0; human approval is mode human.
wp_set_current_user( $actor );
$plain_ids = array( create_product( '100.00' ) );
$plain_plan = Planner::preview( Selection::ids( $plain_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$plain_job = Repo::create_from_plan( $plain_plan, $actor );
eq( $plain_job['approval_mode'], 'none', 'unapproved PLANNED mode none' );
eq( (int) $plain_job['approver_id'], 0, 'unapproved approver 0' );
$approved = Repo::approve( (int) $plain_job['id'], $approver );
eq( $approved['approval_mode'], 'human', 'human approval mode human' );
eq( (int) $approved['approver_id'], $approver, 'human approver persisted' );
// Actor capabilities re-checked at execution: strip the actor to subscriber,
// grant execution must deny with zero saves.
$actor_user = new WP_User( $actor );
$actor_user->set_role( 'subscriber' );
$run = run_worker( array( 'job_id' => (int) $job['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
eq( saves( $ids[0] ), 0, 'revoked actor zero Woo saves' );
$actor_user->set_role( 'administrator' );
// Later grant edit (budget bump) does not rewrite the frozen pin.
$before_version = (int) $job['grant_version'];
wp_set_current_user( $issuer );
$grant_after = Grants::update_grant( $grant['public_id'], $issuer, null, 20 );
ok( (int) $grant_after['version'] > $before_version, 'grant version bumped' );
$fresh_job = Repo::read( (int) $job['id'] );
eq( (int) $fresh_job['grant_version'], $before_version, 'frozen pin unchanged by later grant edit' );
// Operator copy never renders 0 as a person.
ok( false === strpos( Grants::operator_label( $fresh_job ), 'Approved by' ), 'grant copy never renders zero as person' );
marker( 'identity: actor/issuer/approver distinct, actor re-checked, pin frozen' );

// ---------------------------------------------------------------------------
// Scope: allow simple + variation; deny each boundary; no selector rerun.
// ---------------------------------------------------------------------------
// #307: seed the catalogue (parent + variation) as an operator; the restricted
// actor is made current further down only to plan the job.
wp_set_current_user( 1 );
// Eligible variation in scope.
$parent = new WC_Product_Variable();
$parent->set_name( 'WL298-parent-' . wp_generate_uuid4() );
$parent->set_status( 'publish' );
$parent_id = $parent->save();
$variation = new WC_Product_Variation();
$variation->set_parent_id( $parent_id );
$variation->set_status( 'publish' );
$variation->set_regular_price( '100.00' );
$variation_id = $variation->save();
WC_Product_Variable::sync( $parent_id );
wp_set_current_user( $issuer );
$scope_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$scope_plan = Planner::preview( Selection::ids( array( $variation_id ) ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$scope_accepted = Grants::accept_plan( $scope_plan, $scope_grant['public_id'], $issuer, 'id-scope-variation' );
eq( $scope_accepted['job']['approval_mode'], 'grant', 'variation in scope accepted' );
// Draft product: planner freezes it UNSUPPORTED; grant scope denies (zero changing).
$draft_id = create_product( '100.00', 'draft' );
wp_set_current_user( $actor );
$draft_plan = Planner::preview( Selection::ids( array( $draft_id ) ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
try { Grants::accept_plan( $draft_plan, $scope_grant['public_id'], $issuer, 'id-scope-draft' ); throw new RuntimeException( 'draft must be denied' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_SCOPE_DENIED', 'draft scope denied' ); }
eq( saves( $draft_id ), 0, 'scope denial zero saves' );
// Apply/Resume never reruns selectors: run the variation job to completion and
// assert only the frozen ID was written with the absolute target.
Worker::queue_job( (int) $scope_accepted['job']['id'] );
for ( $i = 0; $i < 5 && ! JState::is_terminal( Repo::read( (int) $scope_accepted['job']['id'] )['status'] ); ++$i ) {
	run_worker( array( 'job_id' => (int) $scope_accepted['job']['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
}
eq( Repo::read( (int) $scope_accepted['job']['id'] )['status'], JState::COMPLETED, 'variation grant job completes' );
eq( saves( $variation_id ), 1, 'frozen variation exactly one save' );
eq( fresh_price( $variation_id ), '80.00', 'absolute frozen target, no recalculation' );
marker( 'scope allow/deny + frozen IDs/targets, no selector rerun' );

// ---------------------------------------------------------------------------
// Expiry, revocation, emergency disable.
// ---------------------------------------------------------------------------
wp_set_current_user( $issuer );
$exp_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$exp_ids = array( create_product( '100.00' ) );
$exp_plan = Planner::preview( Selection::ids( $exp_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
// Expire the grant beneath the acceptance (durable UTC comparison).
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE public_id=%s', GSchema::grants_table( $wpdb ), $exp_grant['public_id'] ) );
wp_set_current_user( $issuer );
try { Grants::accept_plan( $exp_plan, $exp_grant['public_id'], $issuer, 'id-expired' ); throw new RuntimeException( 'expired must deny' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_EXPIRED', 'expired grant denies acceptance' ); }
eq( saves( $exp_ids[0] ), 0, 'expired denial zero saves' );
// Revoked grant denies acceptance.
wp_set_current_user( $issuer );
$rev_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
Grants::revoke( $rev_grant['public_id'], $issuer );
try { Grants::accept_plan( $exp_plan, $rev_grant['public_id'], $issuer, 'id-revoked' ); throw new RuntimeException( 'revoked must deny' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_REVOKED', 'revoked grant denies acceptance' ); }
eq( saves( $exp_ids[0] ), 0, 'revoked denial zero saves' );
// Revoke races acceptance: accept first, revoke before execution denies items.
wp_set_current_user( $issuer );
$race_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$race_ids = array( create_product( '100.00' ), create_product( '100.00' ) );
sort( $race_ids, SORT_NUMERIC );
$race_plan = Planner::preview( Selection::ids( $race_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$race_accepted = Grants::accept_plan( $race_plan, $race_grant['public_id'], $issuer, 'id-race' );
Grants::revoke( $race_grant['public_id'], $issuer );
$race_run = run_worker( array( 'job_id' => (int) $race_accepted['job']['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
eq( saves( $race_ids[0] ) + saves( $race_ids[1] ), 0, 'revoke-before-execution zero saves' );
$race_items = array();
foreach ( Repo::items( (int) $race_accepted['job']['id'], null, 0, 100 ) as $row ) { $race_items[ (int) $row['product_id'] ] = $row; }
eq( $race_items[ $race_ids[0] ]['state'], IState::FAILED, 'revoked item terminal FAILED' );
ok( 0 === strpos( $race_items[ $race_ids[0] ]['reason'], 'GRANT_' ), 'revoked reason is grant vocabulary' );
// Revoke races authorization (paused before mutator): revoke wins pre-boundary.
wp_set_current_user( $issuer );
$auth_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$auth_ids = array( create_product( '100.00' ) );
$auth_plan = Planner::preview( Selection::ids( $auth_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$auth_accepted = Grants::accept_plan( $auth_plan, $auth_grant['public_id'], $issuer, 'id-auth-race' );
$auth_job = (int) $auth_accepted['job']['id'];
Worker::queue_job( $auth_job );
$paused = start_worker( array( 'job_id' => $auth_job, 'mode' => 'run', 'limits' => limits( 2 ), 'checkpoint' => 'AFTER_CLAIM', 'fault' => 'wait' ) );
await_file( $paused['spec']['barrier'] );
Grants::revoke( $auth_grant['public_id'], $issuer );
file_put_contents( $paused['spec']['release'], 'go' );
$auth_result = finish_worker( $paused );
eq( saves( $auth_ids[0] ), 0, 'revoke-before-boundary zero saves' );
// Revoke races an in-flight write: the worker is paused after the Woo save
// while holding the item transaction (and the grant row lock), so the
// parallel revoke UPDATE blocks until COMMIT/ROLLBACK. Release lets the
// item commit exactly once; the revoke becomes durable right after. The
// documented boundary: revocation wins only pre-boundary. Single item, so
// no racy second-item ordering is asserted here (the two-item
// revoke-before-execution case above already proves denial pre-boundary).
wp_set_current_user( $issuer );
$flight_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$flight_ids = array( create_product( '100.00' ) );
$flight_plan = Planner::preview( Selection::ids( $flight_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$flight_accepted = Grants::accept_plan( $flight_plan, $flight_grant['public_id'], $issuer, 'id-flight' );
$flight_job = (int) $flight_accepted['job']['id'];
Worker::queue_job( $flight_job );
$held = start_worker( array( 'job_id' => $flight_job, 'mode' => 'run', 'limits' => limits( 2 ), 'mutator_checkpoint' => 'AFTER_WOO_SAVE_BEFORE_JOURNAL', 'mutator_fault' => 'wait' ) );
await_file( $held['spec']['barrier'] );
$revoke_proc = start_worker( array( 'job_id' => $flight_job, 'mode' => 'revoke', 'grant' => $flight_grant['public_id'], 'issuer' => $issuer ), 'grant-revoke-worker.php' );
// The revoke UPDATE blocks behind the held grant row lock; release the item
// so it can commit, then the revoke becomes durable after the boundary.
file_put_contents( $held['spec']['release'], 'go' );
$held_result = finish_worker( $held );
$revoke_result = finish_worker( $revoke_proc );
eq( $revoke_result['revoked'], true, 'revoke serialized after the in-flight boundary' );
eq( saves( $flight_ids[0] ), 1, 'in-flight item commits exactly once past the boundary' );
eq( fresh_price( $flight_ids[0] ), '80.00', 'in-flight item reaches its frozen target' );
eq( journal_row( $flight_plan->data()['plan_id'], $flight_ids[0] )['state'], 'APPLIED', 'in-flight journal APPLIED preserved' );
$frow = grant_row( $flight_grant['public_id'] );
eq( $frow['status'], 'revoked', 'revocation durable after serialization' );
eq( (int) $frow['budget_consumed'], 1, 'past-boundary APPLIED consumes once' );
eq( (int) $frow['budget_reserved'], 0, 'no leaked reservation' );
eq( Repo::read( $flight_job )['status'], JState::COMPLETED, 'single-item grant job completes' );
// Global disable blocks restricted writes with zero fallback saves.
wp_set_current_user( $issuer );
Grants::disable( $issuer );
eq( Grants::is_disabled(), true, 'global disable durable' );
wp_set_current_user( $actor );
$dis_ids = array( create_product( '100.00' ) );
$dis_plan = Planner::preview( Selection::ids( $dis_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$dis_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
try { Grants::accept_plan( $dis_plan, $dis_grant['public_id'], $issuer, 'id-disabled' ); throw new RuntimeException( 'disabled must deny' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_DISABLED', 'global disable denies acceptance' ); }
eq( saves( $dis_ids[0] ), 0, 'disabled denial zero saves, no unrestricted fallback' );
Grants::enable( $issuer );
eq( Grants::is_disabled(), false, 'operator recovery re-enables' );
marker( 'expiry, revocation races with documented boundary, global disable' );

// ---------------------------------------------------------------------------
// #308 emergency controls: pause (disable) racing an open item, revoke-all
// racing acceptance, and no-un-leash semantics.
// ---------------------------------------------------------------------------
wp_set_current_user( $issuer );
$pause_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$pause_ids = array( create_product( '100.00' ), create_product( '100.00' ) );
sort( $pause_ids, SORT_NUMERIC );
$pause_plan = Planner::preview( Selection::ids( $pause_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$pause_accepted = Grants::accept_plan( $pause_plan, $pause_grant['public_id'], $issuer, 'id-pause-race' );
$pause_job = (int) $pause_accepted['job']['id'];
Worker::queue_job( $pause_job );
// Hold the first item after the Woo save (the item transaction also holds the
// kill-switch SHARE lock). Pause then blocks on the option row; releasing the
// item lets it commit, pause becomes durable, and the next item is denied.
$pause_worker = start_worker( array( 'job_id' => $pause_job, 'mode' => 'run', 'limits' => limits( 2 ), 'mutator_checkpoint' => 'AFTER_WOO_SAVE_BEFORE_JOURNAL', 'mutator_fault' => 'wait' ) );
await_file( $pause_worker['spec']['barrier'] );
$pause_proc = start_worker( array( 'operator' => $issuer ), 'grant-disable-worker.php' );
await_file( $pause_proc['spec']['barrier'] );
await_worker_query( (int) file_get_contents( $pause_proc['spec']['barrier'] ), 'writeleash_grants_disabled' );
file_put_contents( $pause_worker['spec']['release'], 'go' );
finish_worker( $pause_worker );
$pause_disable = finish_worker( $pause_proc );
eq( $pause_disable['disabled'], true, 'pause committed after the in-flight item' );
// The default object cache is per-process and the pause ran in a separate
// process; refresh this process's option cache before reading the kill-switch.
wp_cache_delete( GSchema::DISABLE_OPTION, 'options' );
wp_cache_delete( 'alloptions', 'options' );
eq( Grants::is_disabled(), true, 'pause durable after serialization' );
eq( saves( $pause_ids[0] ), 1, 'in-flight item finished exactly once under pause' );
eq( fresh_price( $pause_ids[0] ), '80.00', 'in-flight item reached its frozen target' );
eq( saves( $pause_ids[1] ), 0, 'next item denied with zero unguarded saves' );
$pause_items = array();
foreach ( Repo::items( $pause_job, null, 0, 100 ) as $row ) { $pause_items[ (int) $row['product_id'] ] = $row; }
eq( $pause_items[ $pause_ids[0] ]['state'], IState::APPLIED, 'in-flight item is APPLIED' );
eq( $pause_items[ $pause_ids[1] ]['state'], IState::FAILED, 'next item is terminal FAILED' );
ok( str_contains( (string) $pause_items[ $pause_ids[1] ]['reason'], 'GRANT_DISABLED' ), 'next item denial uses GRANT_DISABLED' );
Grants::enable( $issuer );
eq( Grants::is_disabled(), false, 'operator recovery after the pause race' );
marker( 'pause racing an open item' );

// Revoke-all before acceptance denies with no write authority.
wp_set_current_user( $issuer );
$bulk_pre_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$bulk_pre_ids = array( create_product( '100.00' ) );
$bulk_pre_plan = Planner::preview( Selection::ids( $bulk_pre_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
Grants::revoke_all( $issuer );
try { Grants::accept_plan( $bulk_pre_plan, $bulk_pre_grant['public_id'], $issuer, 'id-bulk-pre' ); throw new RuntimeException( 'revoke-all before acceptance must deny' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_REVOKED', 'revoke-all denies a later acceptance' ); }
eq( saves( $bulk_pre_ids[0] ), 0, 'revoke-all denial zero saves' );

// Revoke-all races acceptance: acceptance serializes first, revoke-all then
// revokes the grant, and the already-accepted job gains no write authority.
wp_set_current_user( $issuer );
$bulk_race_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$bulk_race_ids = array( create_product( '100.00' ), create_product( '100.00' ) );
sort( $bulk_race_ids, SORT_NUMERIC );
$bulk_race_plan = Planner::preview( Selection::ids( $bulk_race_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$bulk_accept = start_worker( array(
	'plan_json' => $bulk_race_plan->json(),
	'grant' => (string) $bulk_race_grant['public_id'],
	'issuer' => $issuer,
	'key' => 'id-bulk-race',
	'checkpoint' => 'AFTER_RESERVE',
	'fault' => 'wait',
), 'grant-accept-worker.php' );
await_file( $bulk_accept['spec']['barrier'] );
$bulk_proc = start_worker( array( 'operator' => $issuer ), 'grant-revoke-all-worker.php' );
await_file( $bulk_proc['spec']['barrier'] );
await_worker_query( (int) file_get_contents( $bulk_proc['spec']['barrier'] ), "status='active'" );
file_put_contents( $bulk_accept['spec']['release'], 'go' );
$bulk_accept_result = finish_worker( $bulk_accept );
$bulk_revoke_result = finish_worker( $bulk_proc );
eq( $bulk_accept_result['ok'], true, 'acceptance committed before revoke-all serialized' );
ok( (int) $bulk_revoke_result['revoked'] >= 1, 'revoke-all observed the accepted active grant' );
$bulk_row = grant_row( (string) $bulk_race_grant['public_id'] );
eq( (string) $bulk_row['status'], 'revoked', 'racing revoke-all revoked the accepted grant' );
eq( (string) $bulk_row['revoke_reason'], Grants::EMERGENCY_REVOKE_REASON, 'racing revoke-all records the emergency reason' );
$bulk_job = (int) $bulk_accept_result['job_id'];
Worker::queue_job( $bulk_job );
run_worker( array( 'job_id' => $bulk_job, 'mode' => 'run', 'limits' => limits( 5 ) ) );
eq( saves( $bulk_race_ids[0] ) + saves( $bulk_race_ids[1] ), 0, 'revoke-all racing acceptance grants no write authority' );
$bulk_items = array();
foreach ( Repo::items( $bulk_job, null, 0, 100 ) as $row ) { $bulk_items[ (int) $row['product_id'] ] = $row; }
$bulk_failed = 0;
$bulk_pending = 0;
foreach ( $bulk_race_ids as $bulk_id ) {
	$bulk_state = $bulk_items[ $bulk_id ]['state'];
	if ( IState::FAILED === $bulk_state ) {
		++$bulk_failed;
		ok( str_contains( (string) $bulk_items[ $bulk_id ]['reason'], 'GRANT_REVOKED' ), 'revoke-all racing acceptance denies with GRANT_REVOKED' );
	} elseif ( IState::PENDING === $bulk_state ) {
		++$bulk_pending;
	} else {
		eq( $bulk_state, IState::PENDING, 'no item gains authority after revoke-all' );
	}
}
eq( $bulk_failed, 1, 'the claimed item is terminal FAILED after revoke-all' );
eq( $bulk_pending, 1, 'the sibling stays PENDING because the job pauses on the grant denial' );
eq( Repo::read( $bulk_job )['status'], JState::PAUSED, 'revoke-all racing acceptance pauses the job' );
eq( (string) Repo::read( $bulk_job )['status_reason'], 'GRANT_REVOKED', 'the job pause reason is the grant revocation' );
ok( Grants::is_restricted_actor( $actor ), 'revoke-all racing acceptance leaves the principal restricted' );
marker( 'revoke-all racing acceptance' );

// ---------------------------------------------------------------------------
// Cumulative budget and recovery.
// ---------------------------------------------------------------------------
wp_set_current_user( $issuer );
$budget_grant = Grants::create_grant( $issuer, $actor, 2, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$budget_ids_a = array( create_product( '100.00' ), create_product( '100.00' ) );
sort( $budget_ids_a, SORT_NUMERIC );
$budget_plan_a = Planner::preview( Selection::ids( $budget_ids_a ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$budget_a = Grants::accept_plan( $budget_plan_a, $budget_grant['public_id'], $issuer, 'id-budget-a' );
eq( $budget_a['replayed'], false, 'first budget job accepted' );
// Competing job for the last units is denied atomically (2 reserved, 0 left).
wp_set_current_user( $actor );
$budget_ids_b = array( create_product( '100.00' ) );
$budget_plan_b = Planner::preview( Selection::ids( $budget_ids_b ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
try { Grants::accept_plan( $budget_plan_b, $budget_grant['public_id'], $issuer, 'id-budget-b' ); throw new RuntimeException( 'overbook must deny' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_BUDGET_EXHAUSTED', 'concurrent last-unit contention has one winner' ); }
eq( saves( $budget_ids_b[0] ), 0, 'budget denial zero Woo saves' );
// Run the winner to durable APPLIED: each consumes exactly once.
Worker::queue_job( (int) $budget_a['job']['id'] );
for ( $i = 0; $i < 4 && ! JState::is_terminal( Repo::read( (int) $budget_a['job']['id'] )['status'] ); ++$i ) {
	run_worker( array( 'job_id' => (int) $budget_a['job']['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
}
eq( Repo::read( (int) $budget_a['job']['id'] )['status'], JState::COMPLETED, 'budget winner completes' );
foreach ( $budget_ids_a as $id ) { eq( saves( $id ), 1, 'winner exactly one save ' . $id ); eq( fresh_price( $id ), '80.00', 'winner target ' . $id ); }
$brow = grant_row( $budget_grant['public_id'] );
eq( (int) $brow['budget_consumed'], 2, 'two APPLIED consume two units' );
eq( (int) $brow['budget_reserved'], 0, 'no leaked reservation after APPLIED' );
// Conflicts and failures release without consuming; Undo never consumes.
wp_set_current_user( $issuer );
$conflict_grant = Grants::create_grant( $issuer, $actor, 5, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$conflict_ids = array( create_product( '100.00' ), create_product( '100.00' ) );
sort( $conflict_ids, SORT_NUMERIC );
$conflict_plan = Planner::preview( Selection::ids( $conflict_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$conflict_accepted = Grants::accept_plan( $conflict_plan, $conflict_grant['public_id'], $issuer, 'id-conflict' );
// External newer edit on the first product becomes a conflict.
$p = wc_get_product( $conflict_ids[0] );
$p->set_regular_price( '90.00' );
$p->save();
$conflict_saves = saves( $conflict_ids[0] );
Worker::queue_job( (int) $conflict_accepted['job']['id'] );
for ( $i = 0; $i < 4 && ! JState::is_terminal( Repo::read( (int) $conflict_accepted['job']['id'] )['status'] ); ++$i ) {
	run_worker( array( 'job_id' => (int) $conflict_accepted['job']['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
}
eq( saves( $conflict_ids[0] ) - $conflict_saves, 0, 'conflict zero WriteLeash saves' );
eq( saves( $conflict_ids[1] ), 1, 'clean sibling one save' );
$crow = grant_row( $conflict_grant['public_id'] );
eq( (int) $crow['budget_consumed'], 1, 'conflict consumes nothing' );
eq( (int) $crow['budget_reserved'], 0, 'conflict releases its reservation' );
// Undo of a grant APPLIED item preserves the original outcome and consumes nothing.
wp_set_current_user( 1 );
// Cross-iteration hygiene: the #109 no-DDL test drops/recreates the job
// tables, so auto-increment restarts while the Undo tables persist. A stale
// operation row for this reused job id (different plan) can only predate
// this job's creation. Prove staleness, remove it, then initiate cleanly.
// Same pattern as the #109 reconciliation-fixture cleanup.
$conflict_job_id = (int) $conflict_accepted['job']['id'];
$stale_op = URepo::read_operation_by_job( $conflict_job_id );
if ( $stale_op ) {
	ok( $stale_op['plan_id'] !== $conflict_plan->data()['plan_id'], 'stale cross-iteration Undo operation proven by plan mismatch' );
	$stale_items = $wpdb->delete( USchema::items_table( $wpdb ), array( 'undo_id' => (int) $stale_op['id'] ) );
	ok( false !== $stale_items, 'stale Undo items removed' );
	eq( (int) $wpdb->delete( USchema::operations_table( $wpdb ), array( 'id' => (int) $stale_op['id'] ) ), 1, 'stale Undo operation removed' );
}
$undo_op = URepo::initiate( $conflict_job_id, 1 );
UWorker::run( (int) $undo_op['id'], limits( 5 ), true );
$urow = grant_row( $conflict_grant['public_id'] );
eq( (int) $urow['budget_consumed'], 1, 'Undo consumes no budget unit' );
eq( journal_row( $conflict_plan->data()['plan_id'], $conflict_ids[1] )['state'], 'APPLIED', 'original APPLIED preserved through Undo' );
// Leave no Undo rows behind: a later cache iteration reuses job ids after
// table recreation while Undo tables persist. Only the APPLIED sibling has
// an Undo row (conflicts are never Undo-eligible).
eq( $wpdb->delete( USchema::items_table( $wpdb ), array( 'undo_id' => (int) $undo_op['id'] ) ), 1, 'cleanup this grant Undo items' );
eq( $wpdb->delete( USchema::operations_table( $wpdb ), array( 'id' => (int) $undo_op['id'] ) ), 1, 'cleanup this grant Undo operation' );
// Ambiguous commit retains the reservation until reconciliation consumes it.
wp_set_current_user( $issuer );
$amb_grant = Grants::create_grant( $issuer, $actor, 5, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$amb_ids = array( create_product( '100.00' ) );
$amb_plan = Planner::preview( Selection::ids( $amb_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$amb_accepted = Grants::accept_plan( $amb_plan, $amb_grant['public_id'], $issuer, 'id-ambiguous' );
Worker::queue_job( (int) $amb_accepted['job']['id'] );
$amb_worker = start_worker( array( 'job_id' => (int) $amb_accepted['job']['id'], 'mode' => 'run', 'limits' => limits( 2 ), 'commit_checkpoint' => 'AFTER_COMMIT_BEFORE_RETURN', 'commit_fault' => 'throw-ambiguous' ) );
$amb = finish_worker( $amb_worker );
$amb_journal = journal_row( $amb_plan->data()['plan_id'], $amb_ids[0] );
eq( $amb_journal['state'], 'APPLIED', 'ambiguous commit left durable APPLIED' );
eq( saves( $amb_ids[0] ), 1, 'ambiguous commit never re-saved' );
$amb_row = grant_row( $amb_grant['public_id'] );
eq( (int) $amb_row['budget_consumed'], 1, 'reconciled APPLIED consumes exactly once' );
marker( 'cumulative budget, release, ambiguous retain, Undo preserves' );

// ---------------------------------------------------------------------------
// Idempotency.
// ---------------------------------------------------------------------------
wp_set_current_user( $issuer );
$idem_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$idem_ids = array( create_product( '100.00' ) );
$idem_plan = Planner::preview( Selection::ids( $idem_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$first = Grants::accept_plan( $idem_plan, $idem_grant['public_id'], $issuer, 'id-same-key' );
$second = Grants::accept_plan( $idem_plan, $idem_grant['public_id'], $issuer, 'id-same-key' );
eq( $second['replayed'], true, 'same key+same payload replays' );
eq( (int) $second['job']['id'], (int) $first['job']['id'], 'replay returns the original job' );
wp_set_current_user( $actor );
$other_ids = array( create_product( '100.00' ) );
$other_plan = Planner::preview( Selection::ids( $other_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
try { Grants::accept_plan( $other_plan, $idem_grant['public_id'], $issuer, 'id-same-key' ); throw new RuntimeException( 'different payload must reject' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_IDEMPOTENCY_MISMATCH', 'same key+different payload rejected' ); }
// Retry does not create a second plan or reservation.
$retry = Grants::accept_plan( $idem_plan, $idem_grant['public_id'], $issuer, 'id-same-key' );
eq( (int) $retry['job']['id'], (int) $first['job']['id'], 'retry creates no second plan' );
$irow = grant_row( $idem_grant['public_id'] );
eq( (int) $irow['budget_reserved'], 1, 'retry creates no second reservation' );
marker( 'idempotency replay/reject/retry, durable uniqueness' );

// ---------------------------------------------------------------------------
// Audit and journal.
// ---------------------------------------------------------------------------
$audit = grant_audit_rows( $idem_grant['public_id'] );
ok( count( $audit ) >= 2, 'audit rows exist' );
foreach ( $audit as $row ) {
	foreach ( array( 'actor_id', 'issuer_id', 'approver_id', 'approval_mode', 'grant_db_id', 'grant_version', 'idempotency_key', 'event', 'details' ) as $col ) {
		ok( array_key_exists( $col, $row ), 'audit carries ' . $col );
	}
	$details = json_decode( $row['details'], true );
	ok( is_array( $details ), 'audit details are canonical JSON' );
}
$found_accept = false;
foreach ( $audit as $row ) { if ( 'GRANT_ACCEPTED' === $row['event'] ) { $found_accept = true; } }
ok( $found_accept, 'acceptance audited with identities and targets' );
// Existing journal CAS + Undo semantics remain intact (spot-check the winner).
eq( journal_row( $budget_plan_a->data()['plan_id'], $budget_ids_a[0] )['state'], 'APPLIED', 'journal CAS intact for grant APPLIED' );
marker( 'audit evidence + journal source of truth intact' );

// ---------------------------------------------------------------------------
// Finding 3: grant management is bound to the authenticated principal.
// ---------------------------------------------------------------------------
wp_set_current_user( $issuer );
$ident_grant = Grants::create_grant( $issuer, $actor, 5, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
// Supplying another valid manager's id is refused: the caller must be self.
wp_set_current_user( $approver );
expect_grant_error( 'PERMISSION_DENIED', function () use ( $issuer, $actor ) {
	Grants::create_grant( $issuer, $actor, 5, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
}, 'create as another user refused' );
expect_grant_error( 'PERMISSION_DENIED', function () use ( $ident_grant, $issuer ) {
	Grants::revoke( $ident_grant['public_id'], $issuer );
}, 'revoke as another user refused' );
expect_grant_error( 'PERMISSION_DENIED', function () use ( $ident_grant, $issuer ) {
	Grants::update_grant( $ident_grant['public_id'], $issuer, null, 6 );
}, 'update as another user refused' );
expect_grant_error( 'PERMISSION_DENIED', function () use ( $issuer ) { Grants::disable( $issuer ); }, 'disable as another user refused' );
expect_grant_error( 'PERMISSION_DENIED', function () use ( $issuer ) { Grants::enable( $issuer ); }, 'enable as another user refused' );
// Anonymous caller refused outright.
wp_set_current_user( 0 );
expect_grant_error( 'PERMISSION_DENIED', function () use ( $issuer ) { Grants::disable( $issuer ); }, 'anonymous disable refused' );
// Acceptance caller must be the issuer; the delegated actor cannot accept.
wp_set_current_user( $actor );
$ident_ids = array( create_product( '100.00' ) );
$ident_plan = Planner::preview( Selection::ids( $ident_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
expect_grant_error( 'PERMISSION_DENIED', function () use ( $ident_plan, $ident_grant, $issuer ) {
	Grants::accept_plan( $ident_plan, $ident_grant['public_id'], $issuer, 'id-ident-f3' );
}, 'actor cannot accept as the issuer' );
// The human approval path can never fabricate an approver on a grant job.
wp_set_current_user( $issuer );
$ident_accepted = Grants::accept_plan( $ident_plan, $ident_grant['public_id'], $issuer, 'id-ident-f3' );
eq( (int) $ident_accepted['job']['approver_id'], 0, 'grant job accepted with approver 0' );
	expect_job_error( 'INVALID_TRANSITION', function () use ( $ident_accepted, $approver ) {
		Repo::approve( (int) $ident_accepted['job']['id'], $approver );
	}, 'human approve cannot rewrite a grant job' );
	$ident_job = Repo::read( (int) $ident_accepted['job']['id'] );
eq( (int) $ident_job['approver_id'], 0, 'durable grant job approver stays 0' );
marker( 'F3 identity binding: caller must be the authenticated issuer' );

// ---------------------------------------------------------------------------
// Finding 1: idempotent replay survives post-acceptance state changes, and
// concurrent same-key submissions serialize to one job/one reservation.
// ---------------------------------------------------------------------------
wp_set_current_user( $issuer );
$replay_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$replay_ids = array( create_product( '100.00' ) );
$replay_plan = Planner::preview( Selection::ids( $replay_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$replay_first = Grants::accept_plan( $replay_plan, $replay_grant['public_id'], $issuer, 'id-replay' );
$replay_job_id = (int) $replay_first['job']['id'];
Grants::revoke( $replay_grant['public_id'], $issuer );
eq( grant_row( $replay_grant['public_id'] )['status'], 'revoked', 'grant revoked after acceptance' );
$replay_after_revoke = Grants::accept_plan( $replay_plan, $replay_grant['public_id'], $issuer, 'id-replay' );
eq( $replay_after_revoke['replayed'], true, 'replay after revoke still returns the original job' );
eq( (int) $replay_after_revoke['job']['id'], $replay_job_id, 'replay after revoke resolves to the same job' );
eq( reservation_count( $replay_grant['public_id'] ), 1, 'replay after revoke creates no second reservation' );
// Global disable must not block a replay either (checked before grant state).
wp_set_current_user( $issuer );
Grants::disable( $issuer );
$replay_after_disable = Grants::accept_plan( $replay_plan, $replay_grant['public_id'], $issuer, 'id-replay' );
eq( $replay_after_disable['replayed'], true, 'replay while globally disabled still returns the original job' );
Grants::enable( $issuer );
eq( Grants::is_disabled(), false, 'operator re-enable restores the kill-switch' );
// Expiry must not block a replay.
wp_set_current_user( $issuer );
$exp_replay_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$exp_replay_ids = array( create_product( '100.00' ) );
$exp_replay_plan = Planner::preview( Selection::ids( $exp_replay_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$exp_replay_first = Grants::accept_plan( $exp_replay_plan, $exp_replay_grant['public_id'], $issuer, 'id-expired-replay' );
Grants::update_grant( $exp_replay_grant['public_id'], $issuer, gmdate( 'Y-m-d H:i:s', time() - 3600 ), null );
$exp_replay_again = Grants::accept_plan( $exp_replay_plan, $exp_replay_grant['public_id'], $issuer, 'id-expired-replay' );
eq( $exp_replay_again['replayed'], true, 'replay after expiry still returns the original job' );
eq( (int) $exp_replay_again['job']['id'], (int) $exp_replay_first['job']['id'], 'expiry replay resolves to the same job' );
// A new acceptance on an expired grant is still denied.
wp_set_current_user( $actor );
$exp_fresh_ids = array( create_product( '100.00' ) );
$exp_fresh_plan = Planner::preview( Selection::ids( $exp_fresh_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
expect_grant_error( 'GRANT_EXPIRED', function () use ( $exp_fresh_plan, $exp_replay_grant, $issuer ) {
	Grants::accept_plan( $exp_fresh_plan, $exp_replay_grant['public_id'], $issuer, 'id-expired-fresh' );
}, 'new acceptance on an expired grant still denied' );
// The same key on a different grant is a distinct acceptance, not a replay.
wp_set_current_user( $issuer );
$cross_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$cross_ids = array( create_product( '100.00' ) );
$cross_plan = Planner::preview( Selection::ids( $cross_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$cross = Grants::accept_plan( $cross_plan, $cross_grant['public_id'], $issuer, 'id-replay' );
eq( $cross['replayed'], false, 'same key on another grant is a new acceptance' );
ok( (int) $cross['job']['id'] !== $replay_job_id, 'cross-grant acceptance mints a distinct job' );
// Tampered idempotency evidence fails closed rather than minting a job.
wp_set_current_user( $issuer );
$tamper_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$tamper_ids = array( create_product( '100.00' ) );
$tamper_plan = Planner::preview( Selection::ids( $tamper_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
Grants::accept_plan( $tamper_plan, $tamper_grant['public_id'], $issuer, 'id-tamper' );
$tamper_db_id = (int) grant_row( $tamper_grant['public_id'] )['id'];
$idem_table = GSchema::idempotency_table( $wpdb );
$idem_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE grant_db_id=%d AND idempotency_key=%s', $idem_table, $tamper_db_id, 'id-tamper' ), ARRAY_A );
ok( is_array( $idem_row ), 'idempotency evidence persisted' );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET job_id=%d WHERE id=%d', $idem_table, 2147483000, (int) $idem_row['id'] ) );
expect_grant_error( 'GRANT_STORE_UNAVAILABLE', function () use ( $tamper_plan, $tamper_grant, $issuer ) {
	Grants::accept_plan( $tamper_plan, $tamper_grant['public_id'], $issuer, 'id-tamper' );
}, 'replay fails closed when the referenced job is missing' );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET job_id=%d, canonical_hash=%s WHERE id=%d', $idem_table, (int) $idem_row['job_id'], str_repeat( 'a', 64 ), (int) $idem_row['id'] ) );
expect_grant_error( 'GRANT_IDEMPOTENCY_MISMATCH', function () use ( $tamper_plan, $tamper_grant, $issuer ) {
	Grants::accept_plan( $tamper_plan, $tamper_grant['public_id'], $issuer, 'id-tamper' );
}, 'tampered stored canonical hash fails closed' );
// Deterministic concurrency: B reserves then holds the grant row lock; A
// blocks on the same lock, and on release replays B's job (one reservation).
wp_set_current_user( $issuer );
$conc_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$conc_ids = array( create_product( '100.00' ) );
$conc_plan = Planner::preview( Selection::ids( $conc_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$conc_json = $conc_plan->json();
$conc_spec = array( 'plan_json' => $conc_json, 'grant' => $conc_grant['public_id'], 'issuer' => $issuer, 'key' => 'id-conc' );
$conc_b = start_worker( array_merge( $conc_spec, array( 'checkpoint' => 'AFTER_RESERVE', 'fault' => 'wait' ) ), 'grant-accept-worker.php' );
await_file( $conc_b['spec']['barrier'] );
$conc_a = start_worker( $conc_spec, 'grant-accept-worker.php' );
await_file( $conc_a['spec']['started'] );
usleep( 400000 );
eq( is_file( $conc_a['spec']['result'] ), false, 'second accept blocks on the held grant lock' );
file_put_contents( $conc_b['spec']['release'], 'go' );
$conc_rb = finish_worker( $conc_b );
$conc_ra = finish_worker( $conc_a );
eq( $conc_rb['ok'], true, 'lock holder acceptance committed' );
eq( $conc_ra['ok'], true, 'concurrent same-key acceptance replayed, not failed' );
eq( $conc_ra['replayed'], true, 'concurrent second accept is a replay' );
eq( (int) $conc_ra['job_id'], (int) $conc_rb['job_id'], 'concurrent accepts resolve to one job' );
eq( reservation_count( $conc_grant['public_id'] ), 1, 'concurrent accepts create one reservation' );
marker( 'F1 idempotency: replay across revoke/expire/disable + concurrency' );

// ---------------------------------------------------------------------------
// Finding 2: mandatory audit evidence fails closed (rolls back), and evidence
// is append-only with the acceptance-time grant version pinned.
// ---------------------------------------------------------------------------
wp_set_current_user( $issuer );
$audit_rows_before = grant_count();
$GLOBALS['wl298_audit_fault'] = 'GRANT_CREATED';
expect_grant_error( 'GRANT_STORE_UNAVAILABLE', function () use ( $issuer, $actor ) {
	Grants::create_grant( $issuer, $actor, 5, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
}, 'create fails closed on audit write failure' );
$GLOBALS['wl298_audit_fault'] = null;
eq( grant_count(), $audit_rows_before, 'no grant row persisted when create audit fails' );
$audit_grant = Grants::create_grant( $issuer, $actor, 5, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
$GLOBALS['wl298_audit_fault'] = 'GRANT_REVOKED';
	expect_grant_error( 'GRANT_STORE_UNAVAILABLE', function () use ( $audit_grant, $issuer ) {
		Grants::revoke( $audit_grant['public_id'], $issuer );
	}, 'revoke fails closed on audit write failure' );
$GLOBALS['wl298_audit_fault'] = null;
eq( grant_row( $audit_grant['public_id'] )['status'], 'active', 'grant stays active when revoke audit fails' );
$audit_version = (int) grant_row( $audit_grant['public_id'] )['version'];
$GLOBALS['wl298_audit_fault'] = 'GRANT_UPDATED';
expect_grant_error( 'GRANT_STORE_UNAVAILABLE', function () use ( $audit_grant, $issuer ) {
	Grants::update_grant( $audit_grant['public_id'], $issuer, null, 6 );
}, 'update fails closed on audit write failure' );
$GLOBALS['wl298_audit_fault'] = null;
eq( (int) grant_row( $audit_grant['public_id'] )['version'], $audit_version, 'grant version unchanged when update audit fails' );
wp_set_current_user( $issuer );
$GLOBALS['wl298_audit_fault'] = 'GRANT_DISABLED';
expect_grant_error( 'GRANT_STORE_UNAVAILABLE', function () use ( $issuer ) { Grants::disable( $issuer ); }, 'disable fails closed on audit write failure' );
$GLOBALS['wl298_audit_fault'] = null;
eq( Grants::is_disabled(), false, 'kill-switch unchanged when disable audit fails' );
// Acceptance rolls back job + idempotency + reservation on audit failure.
wp_set_current_user( $actor );
$audit_ids = array( create_product( '100.00' ) );
$audit_plan = Planner::preview( Selection::ids( $audit_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$audit_reserved = (int) grant_row( $audit_grant['public_id'] )['budget_reserved'];
$GLOBALS['wl298_audit_fault'] = 'GRANT_ACCEPTED';
expect_grant_error( 'GRANT_STORE_UNAVAILABLE', function () use ( $audit_plan, $audit_grant, $issuer ) {
	Grants::accept_plan( $audit_plan, $audit_grant['public_id'], $issuer, 'id-audit-accept' );
}, 'acceptance fails closed on audit write failure' );
$GLOBALS['wl298_audit_fault'] = null;
eq( job_id_for_plan( $audit_plan->data()['plan_id'] ), 0, 'no job persisted when acceptance audit fails' );
eq( (int) grant_row( $audit_grant['public_id'] )['budget_reserved'], $audit_reserved, 'no reservation persisted when acceptance audit fails' );
// Append-only: a later edit adds a row and mutates no prior evidence.
wp_set_current_user( $issuer );
$append_before = grant_audit_rows( $replay_grant['public_id'] );
$append_ids = array_column( $append_before, 'id' );
Grants::update_grant( $replay_grant['public_id'], $issuer, null, 20 );
$append_after = grant_audit_rows( $replay_grant['public_id'] );
eq( count( $append_after ), count( $append_before ) + 1, 'grant edit appends exactly one audit row' );
foreach ( $append_before as $i => $row ) {
	eq( (int) $append_after[ $i ]['id'], (int) $row['id'], 'append-only: prior audit row id preserved' );
	eq( $append_after[ $i ]['details'], $row['details'], 'append-only: prior audit details preserved' );
}
eq( $append_after[ count( $append_after ) - 1 ]['event'], 'GRANT_UPDATED', 'grant edit is audited' );
marker( 'F2 audit fail-closed: create/revoke/update/disable/acceptance roll back' );

// Per-item evidence: ITEM_APPLIED pins the acceptance-time version, and a
// failed item audit rolls back (price unwritten, budget untouched).
wp_set_current_user( $issuer );
$pin_grant = Grants::create_grant( $issuer, $actor, 5, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$pin_ids = array( create_product( '100.00' ) );
$pin_plan = Planner::preview( Selection::ids( $pin_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$pin_accepted = Grants::accept_plan( $pin_plan, $pin_grant['public_id'], $issuer, 'id-pin' );
$pin_version = (int) $pin_accepted['job']['grant_version'];
eq( $pin_version, (int) grant_row( $pin_grant['public_id'] )['version'], 'job pins the current grant version' );
$pin_bumped = Grants::update_grant( $pin_grant['public_id'], $issuer, null, 9 );
eq( (int) $pin_bumped['version'], $pin_version + 1, 'grant edit bumps the grant version' );
Worker::queue_job( (int) $pin_accepted['job']['id'] );
for ( $i = 0; $i < 4 && ! JState::is_terminal( Repo::read( (int) $pin_accepted['job']['id'] )['status'] ); ++$i ) {
	run_worker( array( 'job_id' => (int) $pin_accepted['job']['id'], 'mode' => 'run', 'limits' => limits( 2 ) ) );
}
eq( fresh_price( $pin_ids[0] ), '80.00', 'pinned job applies after a later grant edit' );
$pin_applied = null;
foreach ( grant_audit_rows( $pin_grant['public_id'] ) as $row ) { if ( 'ITEM_APPLIED' === $row['event'] ) { $pin_applied = $row; } }
ok( is_array( $pin_applied ), 'ITEM_APPLIED evidence recorded' );
eq( (int) $pin_applied['grant_version'], $pin_version, 'ITEM_APPLIED pins the acceptance-time grant version' );
ok( (int) grant_row( $pin_grant['public_id'] )['version'] > $pin_version, 'current grant version is ahead of the pin' );
// Item-level audit failure: no false success.
wp_set_current_user( $issuer );
$fail_grant = Grants::create_grant( $issuer, $actor, 5, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$fail_ids = array( create_product( '100.00' ) );
$fail_plan = Planner::preview( Selection::ids( $fail_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
wp_set_current_user( $issuer );
$fail_accepted = Grants::accept_plan( $fail_plan, $fail_grant['public_id'], $issuer, 'id-audit-item' );
$fail_job = (int) $fail_accepted['job']['id'];
Worker::queue_job( $fail_job );
for ( $i = 0; $i < 4 && ! JState::is_terminal( Repo::read( $fail_job )['status'] ); ++$i ) {
	run_worker( array( 'job_id' => $fail_job, 'mode' => 'run', 'limits' => limits( 2 ), 'audit_fault_event' => 'ITEM_APPLIED', 'audit_fault' => 'throw' ) );
}
eq( fresh_price( $fail_ids[0] ), '100.00', 'failed item audit leaves the price unwritten' );
ok( 'APPLIED' !== journal_row( $fail_plan->data()['plan_id'], $fail_ids[0] )['state'], 'failed item audit leaves no durable APPLIED' );
eq( (int) grant_row( $fail_grant['public_id'] )['budget_consumed'], 0, 'failed item audit consumes no budget' );
	eq( reservation_state( $fail_grant['public_id'], $fail_job, $fail_ids[0] ), 'RESERVED', 'failed item audit retains the reservation for operator review' );
	// Consumed reservation must adopt durable APPLIED, not deny as budget exhaustion.
	$pin_saves = saves( $pin_ids[0] );
	$pin_consumed = (int) grant_row( $pin_grant['public_id'] )['budget_consumed'];
	$pin_job = (int) $pin_accepted['job']['id'];
	eq( 1, (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET state='PENDING',reason='',claim_token='',claim_generation=0,attempt_count=0,updated_at=UTC_TIMESTAMP() WHERE job_id=%d AND product_id=%d", Schema::items_table( $wpdb ), $pin_job, $pin_ids[0] ) ), 'reopen applied item' );
	eq( 1, (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='READY',status_reason='GRANT_ACCEPTED',lease_owner='',lease_expires_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=%d", Schema::jobs_table( $wpdb ), $pin_job ) ), 'reopen completed job' );
	run_worker( array( 'job_id' => $pin_job, 'mode' => 'run', 'limits' => limits( 2 ) ) );
	eq( saves( $pin_ids[0] ), $pin_saves, 'consumed reservation does not save again' );
	eq( journal_row( $pin_plan->data()['plan_id'], $pin_ids[0] )['state'], 'APPLIED', 'durable APPLIED is adopted' );
	eq( reservation_state( $pin_grant['public_id'], $pin_job, $pin_ids[0] ), 'CONSUMED', 'consumed reservation stays consumed' );
	eq( (int) grant_row( $pin_grant['public_id'] )['budget_consumed'], $pin_consumed, 'adopted APPLIED does not consume again' );
	$adopted = null;
	foreach ( Repo::items( $pin_job, null, 0, 10 ) as $row ) { if ( (int) $row['product_id'] === $pin_ids[0] ) { $adopted = $row; } }
	eq( $adopted['state'], IState::APPLIED, 're-entered item is not a false grant denial' );
	ok( 'GRANT_BUDGET_EXHAUSTED' !== $adopted['reason'], 'consumed reservation is not budget exhaustion' );
	// Refusal evidence failure must not commit the journal terminal row.
	foreach ( array( 'throw', 'false-insert' ) as $fault ) {
		wp_set_current_user( $issuer );
		$rel_grant = Grants::create_grant( $issuer, $actor, 5, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
		wp_set_current_user( $actor );
		$rel_ids = array( create_product( '100.00' ) );
		$rel_plan = Planner::preview( Selection::ids( $rel_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
		wp_set_current_user( $issuer );
		$rel_accepted = Grants::accept_plan( $rel_plan, $rel_grant['public_id'], $issuer, 'id-release-' . $fault );
		$rel_job = (int) $rel_accepted['job']['id'];
		$rel_reserved = (int) grant_row( $rel_grant['public_id'] )['budget_reserved'];
		Grants::revoke( $rel_grant['public_id'], $issuer );
		run_worker( array( 'job_id' => $rel_job, 'mode' => 'run', 'limits' => limits( 2 ), 'audit_fault_event' => 'ITEM_RELEASED', 'audit_fault' => $fault ) );
		eq( fresh_price( $rel_ids[0] ), '100.00', $fault . ' refusal leaves the price unwritten' );
		eq( journal_row( $rel_plan->data()['plan_id'], $rel_ids[0] )['state'], 'PENDING', $fault . ' refusal does not commit a terminal journal row' );
		eq( reservation_state( $rel_grant['public_id'], $rel_job, $rel_ids[0] ), 'RESERVED', $fault . ' refusal retains the reservation' );
		eq( (int) grant_row( $rel_grant['public_id'] )['budget_reserved'], $rel_reserved, $fault . ' refusal does not change budget_reserved' );
		$released = false;
		foreach ( grant_audit_rows( $rel_grant['public_id'] ) as $row ) { if ( 'ITEM_RELEASED' === $row['event'] ) { $released = true; } }
		ok( ! $released, $fault . ' refusal writes no ITEM_RELEASED row' );
	}
	marker( 'F2 per-item evidence: pinned version + rollback on audit failure' );

// ---------------------------------------------------------------------------
// #306: merchant Apply by a restricted actor writes nothing. An unrestricted
// merchant still applies. A grant committed before the decision is visible
// inside the item transaction, and create_grant waits on that lock.
// ---------------------------------------------------------------------------
wp_set_current_user( 1 );
$ng_id = create_product( '100.00' );
$ng_hash = catalog_hash( array( $ng_id ) );
$ng_saves = saves( $ng_id );
wp_set_current_user( $actor );
$ng_plan = Planner::preview( Selection::ids( array( $ng_id ) ), new Operation( Operation::SET, '70.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$ng_job = Repo::create_from_plan( $ng_plan, $actor );
wp_set_current_user( $approver );
$ng_approved = Repo::approve( (int) $ng_job['id'], $approver );
eq( $ng_approved['approval_mode'], 'human', 'restricted merchant job is a human approval' );
eq( (string) ( $ng_approved['grant_public_id'] ?? '' ), '', 'restricted merchant job has no grant pin' );
Worker::queue_job( (int) $ng_job['id'] );
$ng_run = run_worker( array( 'job_id' => (int) $ng_job['id'], 'mode' => 'run', 'limits' => limits( 3 ) ) );
eq( $ng_run['stop'], 'PERMISSION_REVOKED', 'restricted merchant apply pauses' );
eq( fresh_price( $ng_id ), '100.00', 'restricted merchant apply leaves the price' );
eq( saves( $ng_id ), $ng_saves, 'restricted merchant apply zero Woo saves' );
eq( catalog_hash( array( $ng_id ) ), $ng_hash, 'restricted merchant apply zero post meta lookup change' );
$ng_journal = journal_row( $ng_plan->data()['plan_id'], $ng_id );
eq( $ng_journal['state'], 'FAILED', 'restricted merchant journal FAILED' );
eq( $ng_journal['reason'], 'PERMISSION_DENIED', 'restricted merchant journal reason' );
$ng_item = null;
foreach ( Repo::items( (int) $ng_job['id'], null, 0, 10 ) as $row ) { if ( (int) $row['product_id'] === $ng_id ) { $ng_item = $row; } }
eq( $ng_item['state'], IState::FAILED, 'restricted merchant item FAILED' );
eq( $ng_item['reason'], 'PERMISSION_DENIED', 'restricted merchant item reason' );

$free_login = 'wl306-free-' . wp_generate_uuid4();
$free_id_user = wp_insert_user( array( 'user_login' => $free_login, 'user_pass' => wp_generate_password( 20 ), 'role' => 'administrator', 'user_email' => $free_login . '@example.test' ) );
if ( is_wp_error( $free_id_user ) ) { throw new RuntimeException( 'free merchant user failed' ); }
$free_user = (int) $free_id_user;
wp_set_current_user( 1 );
$free_product = create_product( '100.00' );
wp_set_current_user( $free_user );
$free_plan = Planner::preview( Selection::ids( array( $free_product ) ), new Operation( Operation::SET, '70.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$free_job = Repo::create_from_plan( $free_plan, $free_user );
wp_set_current_user( $issuer );
Repo::approve( (int) $free_job['id'], $issuer );
Worker::queue_job( (int) $free_job['id'] );
for ( $i = 0; $i < 4 && ! JState::is_terminal( Repo::read( (int) $free_job['id'] )['status'] ); ++$i ) {
	run_worker( array( 'job_id' => (int) $free_job['id'], 'mode' => 'run', 'limits' => limits( 2 ) ) );
}
eq( fresh_price( $free_product ), '70.00', 'unrestricted merchant apply still writes' );
eq( journal_row( $free_plan->data()['plan_id'], $free_product )['state'], 'APPLIED', 'unrestricted merchant journal APPLIED' );

wp_set_current_user( 1 );
$fault_product = create_product( '100.00' );
$fault_hash = catalog_hash( array( $fault_product ) );
$fault_saves = saves( $fault_product );
wp_set_current_user( $free_user );
$fault_plan = Planner::preview( Selection::ids( array( $fault_product ) ), new Operation( Operation::SET, '70.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$fault_job = Repo::create_from_plan( $fault_plan, $free_user );
wp_set_current_user( $issuer );
Repo::approve( (int) $fault_job['id'], $issuer );
Worker::queue_job( (int) $fault_job['id'] );
run_worker( array( 'job_id' => (int) $fault_job['id'], 'mode' => 'run', 'limits' => limits( 2 ), 'restriction_read_fault' => true ) );
eq( fresh_price( $fault_product ), '100.00', 'unreadable restriction read leaves the price' );
eq( saves( $fault_product ), $fault_saves, 'unreadable restriction read zero Woo saves' );
eq( catalog_hash( array( $fault_product ) ), $fault_hash, 'unreadable restriction read zero post meta lookup change' );
$fault_journal = journal_row( $fault_plan->data()['plan_id'], $fault_product );
eq( $fault_journal['state'], 'NEEDS_REVIEW', 'unreadable restriction read journal NEEDS_REVIEW' );
eq( $fault_journal['reason'], 'GRANT_STORE_UNAVAILABLE', 'unreadable restriction read journal reason' );

$race_login = 'wl306-race-' . wp_generate_uuid4();
$race_user_id = wp_insert_user( array( 'user_login' => $race_login, 'user_pass' => wp_generate_password( 20 ), 'role' => 'administrator', 'user_email' => $race_login . '@example.test' ) );
if ( is_wp_error( $race_user_id ) ) { throw new RuntimeException( 'race merchant user failed' ); }
$race_user = (int) $race_user_id;
wp_set_current_user( 1 );
$race_product = create_product( '100.00' );
wp_set_current_user( $race_user );
$race_plan = Planner::preview( Selection::ids( array( $race_product ) ), new Operation( Operation::SET, '70.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$race_job = Repo::create_from_plan( $race_plan, $race_user );
wp_set_current_user( $issuer );
Repo::approve( (int) $race_job['id'], $issuer );
Worker::queue_job( (int) $race_job['id'] );
$race_worker = start_worker( array( 'job_id' => (int) $race_job['id'], 'mode' => 'run', 'limits' => limits( 2, 150 ), 'mutator_checkpoint' => 'RESTRICTION_DECIDED', 'mutator_fault' => 'wait', 'mutator_wait_seconds' => 90 ) );
await_file( $race_worker['spec']['barrier'] );
$race_barrier = (string) file_get_contents( $race_worker['spec']['barrier'] );
ok( str_starts_with( $race_barrier, 'RESTRICTION_DECIDED:' ), 'restriction decision reached before the Woo save' );
$race_holder = (int) substr( $race_barrier, strlen( 'RESTRICTION_DECIDED:' ) );
$race_pid = (int) ( is_file( $race_worker['spec']['started'] ) ? file_get_contents( $race_worker['spec']['started'] ) : 0 );
eq( fresh_price( $race_product ), '100.00', 'locked decision has not saved the product' );
$held_user = actor_row_locked_nowait( $race_user );
if ( ! $held_user ) {
	throw new RuntimeException( 'in-flight item does not hold the actor user row at RESTRICTION_DECIDED actor=' . $race_user . ' thread=' . $race_holder . ' merchant_pid=' . $race_pid . ' merchant_alive=' . merchant_alive( $race_pid ) . ' ' . lock_diagnostics( $race_holder ) );
}
ok( $held_user, 'in-flight item holds the actor user row at RESTRICTION_DECIDED' );
$race_creator = start_worker( array( 'issuer' => $issuer, 'actor' => $race_user, 'lock_wait_timeout' => 120 ), 'grant-create-worker.php' );
await_grant_insert( $race_holder, $race_creator['spec']['result'], $race_user, 25, $race_pid );
file_put_contents( $race_worker['spec']['release'], 'go' );
finish_worker( $race_worker );
$race_created = finish_worker( $race_creator );
ok( is_string( $race_created['public_id'] ?? null ) && '' !== $race_created['public_id'], 'create_grant commits after the item lock' );
eq( fresh_price( $race_product ), '70.00', 'in-flight unrestricted item still applies' );
wp_set_current_user( 1 );
$after_product = create_product( '100.00' );
$after_hash = catalog_hash( array( $after_product ) );
$after_saves = saves( $after_product );
wp_set_current_user( $race_user );
$after_plan = Planner::preview( Selection::ids( array( $after_product ) ), new Operation( Operation::SET, '55.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$after_job = Repo::create_from_plan( $after_plan, $race_user );
wp_set_current_user( $issuer );
Repo::approve( (int) $after_job['id'], $issuer );
Worker::queue_job( (int) $after_job['id'] );
$after_run = run_worker( array( 'job_id' => (int) $after_job['id'], 'mode' => 'run', 'limits' => limits( 2 ) ) );
eq( $after_run['stop'], 'PERMISSION_REVOKED', 'grant committed during the previous item denies the next merchant apply' );
eq( fresh_price( $after_product ), '100.00', 'later merchant apply leaves the price' );
eq( saves( $after_product ), $after_saves, 'later merchant apply zero Woo saves' );
eq( catalog_hash( array( $after_product ) ), $after_hash, 'later merchant apply zero post meta lookup change' );
eq( journal_row( $after_plan->data()['plan_id'], $after_product )['state'], 'FAILED', 'later merchant journal FAILED' );
eq( journal_row( $after_plan->data()['plan_id'], $after_product )['reason'], 'PERMISSION_DENIED', 'later merchant journal reason' );
marker( 'non-grant apply: restricted and unreadable reads write nothing; unrestricted apply and the grant lock stay serial' );

echo "#298 grant-integration PASS ($assertions assertions)\n";
