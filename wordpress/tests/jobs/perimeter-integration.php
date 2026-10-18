<?php
// #307 Perimeter Guard + Execution Frame integration (container gate).
// Runs inside the #109 fixture via wp eval-file:
//   WL109_CACHE=default wp --path=$site eval-file /opt/tests/jobs/perimeter-integration.php
// Requires MySQL 8.0.44 / MariaDB 10.11.15, pinned WP 7.1.2 / PHP 8.2 / Woo 11.1.2,
// default or Redis object cache. Proves every bypass path is denied before
// persistence for a restricted principal, and that the controlled path still
// works inside a matching Execution Frame.
use WriteLeash\Grant_Repository as Grants;
use WriteLeash\Grant_Schema as GSchema;
use WriteLeash\Job_Item_State as IState;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Job_Schema as Schema;
use WriteLeash\Job_State as JState;
use WriteLeash\Job_Worker as Worker;
use WriteLeash\Perimeter_Guard as Guard;
use WriteLeash\Price_Apply_Journal as Journal;
use WriteLeash\Price_Cache_Verifier as Verifier;
use WriteLeash\Price_Operation as Operation;
use WriteLeash\Price_Selection_Spec as Selection;
use WriteLeash\Safety_Policy as Policy;
use WriteLeash\Woo_Price_Planner as Planner;

global $wpdb, $work, $assertions, $hook_log, $items_table, $jobs_table;
$assertions = 0;
$work = sys_get_temp_dir() . '/wl307-' . bin2hex( random_bytes( 5 ) );
mkdir( $work );
$hook_log = $work . '/hooks.log';
touch( $hook_log );
$GLOBALS['wl108_active'] = true;
$GLOBALS['wl108_log'] = $hook_log;
$GLOBALS['wl307_denials'] = array();
add_action( 'writeleash_perimeter_denied', function ( $code, $where, $actor, $object_id, $kind, $detail ) {
	$GLOBALS['wl307_denials'][] = array( 'code' => $code, 'where' => $where, 'actor' => (int) $actor, 'object_id' => (int) $object_id, 'kind' => $kind, 'detail' => $detail );
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
	echo "#307 $label: PASS ($assertions assertions)\n";
}
function create_product( string $price, string $status = 'publish' ): int {
	$p = new WC_Product_Simple();
	$p->set_name( 'WL307-' . wp_generate_uuid4() );
	$p->set_status( $status );
	$p->set_regular_price( $price );
	$p->save();
	return $p->get_id();
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
function fresh_price( int $id ): string {
	Verifier::invalidate( $id );
	$p = wc_get_product( $id );
	return (string) $p->get_regular_price( 'edit' );
}
/** Hash of the durable rows for a set of products, for before/after zero-change assertions. */
function hash_rows( array $ids ): string {
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
function assert_zero_changes( array $ids, string $before, string $label ): void {
	eq( hash_rows( $ids ), $before, $label . ' zero durable row change' );
}
function limits( int $items, int $seconds = 20 ): array { return array( 'max_items' => $items, 'budget_seconds' => $seconds ); }
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
function finish_worker( array $worker ): array {
	$exit = proc_close( $worker['proc'] );
	eq( in_array( $exit, array( 0 ), true ), true, 'worker exit: ' . file_get_contents( $worker['prefix'] . '.stderr' ) );
	if ( ! is_file( $worker['spec']['result'] ) ) { throw new RuntimeException( 'no worker result' ); }
	return json_decode( file_get_contents( $worker['spec']['result'] ), true );
}
function run_worker( array $spec, string $script = 'worker.php' ): array { return finish_worker( start_worker( $spec, $script ) ); }
function rest_dispatch( string $method, string $route, array $body = array() ) {
	$server = rest_get_server();
	$request = new \WP_REST_Request( $method, $route );
	if ( $body ) { $request->set_body_params( $body ); }
	return $server->dispatch( $request );
}
function deny_code(): string { return 'writeleash_perimeter_denied'; }
function exc_code( \Throwable $e ): string {
	if ( method_exists( $e, 'getErrorCode' ) ) { return $e->getErrorCode(); }
	return (string) $e->getCode();
}
function ensure_users(): array {
	$ids = array();
	foreach ( array( 'wl307-issuer', 'wl307-actor', 'wl307-manager' ) as $login ) {
		$existing = get_user_by( 'login', $login );
		if ( $existing ) { $ids[ $login ] = (int) $existing->ID; continue; }
		$role = 'wl307-manager' === $login ? 'shop_manager' : 'administrator';
		$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 20 ), 'role' => $role, 'user_email' => $login . '@example.test' ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'user creation failed: ' . $login ); }
		$ids[ $login ] = (int) $id;
	}
	ok( count( array_unique( array_values( $ids ) ) ) === 3, 'three distinct identities' );
	return $ids;
}
function job_fixture( array $ids, string $price, string $target, int $actor ): array {
	$snapshots = array();
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		$snapshots[] = array( 'product_id' => $id, 'expected_regular_price' => $price, 'planned_regular_price' => $target, 'result' => 'CHANGING', 'snapshot' => array( 'type' => 'simple', 'core_simple' => true, 'status' => 'publish', 'regular_price' => $price ) );
	}
	$plan = Planner::preview( Selection::ids( $ids ), new Operation( Operation::SET, $target ), new Policy( 1000, '100', '100', false, '100' ) );
	// Repoint the frozen actor so the job runs as the restricted principal.
	$data = $plan->data();
	$data['actor_id'] = $actor;
	return array( 'plan' => $plan, 'data' => $data );
}

wp_set_current_user( 1 );
eq( \WriteLeash\Free_Support_Contract::woo_supported( WC_VERSION ), true, 'fixture Woo inside supported range' );
eq( get_bloginfo( 'version' ), '7.1.2', 'pinned WordPress' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wl108_hook_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, product_id bigint unsigned NOT NULL, hook varchar(100) NOT NULL) ENGINE=InnoDB" );
eq( Schema::ready( $wpdb ), true, 'job schema v2 ready' );
$users = ensure_users();
$issuer = $users['issuer'];
$actor = $users['actor'];
$manager = $users['manager'];
$items_table = Schema::items_table( $wpdb );
$jobs_table = Schema::jobs_table( $wpdb );

// ---------------------------------------------------------------------------
// A standing grant makes the actor a restricted principal.
// ---------------------------------------------------------------------------
wp_set_current_user( $issuer );
$grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
ok( Grants::is_disabled() === false, 'global disable defaults to enabled' );
ok( is_restricted_actor( $actor ) === true, 'grant actor is restricted' );
ok( is_restricted_actor( $issuer ) === false, 'grant issuer is not restricted' );
ok( is_restricted_actor( $manager ) === false, 'shop manager is not restricted' );
ok( is_restricted_actor( 0 ) === false, 'anonymous/cron user is not restricted' );
marker( 'restricted actor predicate' );

// ---------------------------------------------------------------------------
// Bypass probes: every path denied before persistence, zero row changes.
// ---------------------------------------------------------------------------
$probe_id = create_product( '100.00' );
$probe_before = hash_rows( array( $probe_id ) );
wp_set_current_user( $actor );

// 1. WC REST single product create/update/delete.
$create = rest_dispatch( 'POST', '/wp-json/wc/v3/products', array( 'name' => 'WL307-rest', 'regular_price' => '50.00', 'status' => 'publish' ) );
ok( $create->is_error(), 'REST product create is an error response' );
eq( $create->get_status(), 403, 'REST product create status 403' );
eq( $create->get_error_code(), deny_code(), 'REST product create denial code' );
$update = rest_dispatch( 'PUT', '/wp-json/wc/v3/products/' . $probe_id, array( 'regular_price' => '50.00' ) );
ok( $update->is_error(), 'REST product update is an error response' );
eq( $update->get_status(), 403, 'REST product update status 403' );
$del = rest_dispatch( 'DELETE', '/wp-json/wc/v3/products/' . $probe_id );
ok( $del->is_error(), 'REST product delete is an error response' );
eq( $del->get_status(), 403, 'REST product delete status 403' );

// 2. WC REST batch create/update/delete.
$batch = rest_dispatch( 'POST', '/wp-json/wc/v3/products/batch', array( 'create' => array( array( 'name' => 'WL307-batch', 'regular_price' => '50.00' ) ) ) );
ok( $batch->is_error(), 'REST product batch is an error response' );
eq( $batch->get_status(), 403, 'REST product batch status 403' );

// 3. $product->save() directly on a restricted product.
$p = wc_get_product( $probe_id );
$p->set_regular_price( '55.00' );
try {
	$p->save();
	throw new RuntimeException( 'expected save to be denied' );
} catch ( \WC_Data_Exception $e ) {
	eq( $e->getErrorCode() ?? $e->getCode(), deny_code(), 'direct save denial code' );
} catch ( \RuntimeException $e ) {
	throw new RuntimeException( 'direct save must throw WC_Data_Exception, got ' . $e->getMessage() );
}

// 4. wc_update_product_stock() directly.
try {
	wc_update_product_stock( $probe_id, 5, 'decrease' );
	throw new RuntimeException( 'expected stock update to be denied' );
} catch ( \WC_Data_Exception $e ) {
	eq( $e->getErrorCode() ?? $e->getCode(), deny_code(), 'stock update denial code' );
} catch ( \RuntimeException $e ) {
	throw new RuntimeException( 'stock update must throw WC_Data_Exception, got ' . $e->getMessage() );
}

// 5. Protected postmeta add/update/delete on price/stock keys.
ok( update_post_metadata( $probe_id, '_regular_price', '60.00' ) === false, 'update_post_metadata _regular_price denied' );
ok( add_post_metadata( $probe_id, '_sale_price', '60.00' ) === false, 'add_post_metadata _sale_price denied' );
ok( delete_post_metadata( $probe_id, '_stock' ) === false, 'delete_post_metadata _stock denied' );
ok( update_post_metadata( $probe_id, '_stock_status', 'outofstock' ) === false, 'update_post_metadata _stock_status denied' );
ok( add_post_metadata( $probe_id, '_price', '60.00' ) === false, 'add_post_metadata _price denied' );

// 6. Trash / delete of a product.
ok( wp_trash_post( $probe_id ) instanceof \WP_Error, 'trash post denied' );
try {
	wp_delete_post( $probe_id, true );
	throw new RuntimeException( 'expected delete to be denied' );
} catch ( \WC_Data_Exception $e ) {
	eq( exc_code( $e ), deny_code(), 'direct delete denial code' );
}

assert_zero_changes( array( $probe_id ), $probe_before, 'all bypass probes' );
ok( count( $GLOBALS['wl307_denials'] ) >= 1, 'at least one refusal logged' );
eq( fresh_price( $probe_id ), '100.00', 'bypass target price untouched' );
marker( 'bypass probes: REST single+batch, save, stock, meta, trash/delete all denied before persistence' );

// GET reads remain allowed for a restricted principal.
$get = rest_dispatch( 'GET', '/wp-json/wc/v3/products/' . $probe_id );
ok( ! $get->is_error(), 'REST product read allowed for restricted principal' );
eq( $get->get_status(), 200, 'REST product read status 200' );

// ---------------------------------------------------------------------------
// Inside the Execution Frame: the controlled path works.
// ---------------------------------------------------------------------------
$item_ids = array( create_product( '100.00' ), create_product( '100.00' ) );
sort( $item_ids, SORT_NUMERIC );
wp_set_current_user( $issuer );
$fixture = job_fixture( $item_ids, '100.00', '80.00', $actor );
$accepted = Grants::accept_plan( $fixture['plan'], $grant['public_id'], $issuer, 'id-307-frame' );
$job = $accepted['job'];
eq( $accepted['replayed'], false, 'frame job not a replay' );
wp_set_current_user( $actor );
$actor_user = new \WP_User( $actor );
$actor_user->set_role( 'administrator' ); // ensure execution caps while restricted
Worker::queue_job( (int) $job['id'] );
for ( $i = 0; $i < 5 && ! JState::is_terminal( Repo::read( (int) $job['id'] )['status'] ); ++$i ) {
	run_worker( array( 'job_id' => (int) $job['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
}
eq( Repo::read( (int) $job['id'] )['status'], JState::COMPLETED, 'grant job completes inside frame' );
eq( fresh_price( $item_ids[0] ), '80.00', 'controlled price write inside frame applied' );
eq( saves( $item_ids[0] ), 1, 'exactly one Woo save for the framed product' );
$grant_row = Grants::read_by_public_id( $grant['public_id'] );
eq( (int) $grant_row['budget_consumed'], 1, 'one budget unit consumed, no extra for sync' );
marker( 'inside frame: controlled price write applies, one save, one budget unit' );

// ---------------------------------------------------------------------------
// Non-interference: other principals and paths unchanged.
// ---------------------------------------------------------------------------
$manager_id = create_product( '100.00' );
$manager_before = hash_rows( array( $manager_id ) );
wp_set_current_user( $manager );
$mp = wc_get_product( $manager_id );
$mp->set_regular_price( '90.00' );
$mp->save(); // shop manager (not restricted) edits freely
eq( fresh_price( $manager_id ), '90.00', 'shop manager edit unaffected' );
assert_zero_changes( array( $manager_id ), $manager_before, 'shop manager edit' );
marker( 'non-interference: human shop manager edit unaffected' );

// Guest checkout stock reduction (user_id = 0) and cron (user_id = 0).
$stock_id = create_product( '10.00' );
wp_set_current_user( 0 );
$stock_before = wc_get_product( $stock_id )->get_stock_quantity( 'edit' );
wc_update_product_stock( $stock_id, 2, 'decrease' );
eq( wc_get_product( $stock_id )->get_stock_quantity( 'edit' ), $stock_before - 2, 'guest stock reduction unaffected' );
marker( 'non-interference: guest/cron user_id=0 stock reduction unaffected' );

// Variable parent sync inside the frame: authorized only as an explicit extra product id.
$parent = new \WC_Product_Variable();
$parent->set_name( 'WL307-parent-' . wp_generate_uuid4() );
$parent->set_status( 'publish' );
$parent_id = $parent->save();
$variation = new \WC_Product_Variation();
$variation->set_parent_id( $parent_id );
$variation->set_status( 'publish' );
$variation->set_regular_price( '100.00' );
$variation_id = $variation->save();
$variation_before = hash_rows( array( $variation_id, $parent_id ) );
wp_set_current_user( $issuer );
$var_fixture = job_fixture( array( $variation_id ), '100.00', '70.00', $actor );
$var_accepted = Grants::accept_plan( $var_fixture['plan'], $grant['public_id'], $issuer, 'id-307-sync' );
$var_job = $var_accepted['job'];
wp_set_current_user( $actor );
Worker::queue_job( (int) $var_job['id'] );
for ( $i = 0; $i < 5 && ! JState::is_terminal( Repo::read( (int) $var_job['id'] )['status'] ); ++$i ) {
	run_worker( array( 'job_id' => (int) $var_job['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
}
eq( Repo::read( (int) $var_job['id'] )['status'], JState::COMPLETED, 'variation grant job completes inside frame' );
eq( fresh_price( $variation_id ), '70.00', 'variation price inside frame applied' );
assert_zero_changes( array( $variation_id, $parent_id ), $variation_before, 'variation + parent sync inside frame' );
marker( 'non-interference: variable parent sync inside frame succeeds with no extra budget unit' );

// ---------------------------------------------------------------------------
// Concurrency: revoke racing the item transaction denies items.
// ---------------------------------------------------------------------------
$race_ids = array( create_product( '100.00' ) );
wp_set_current_user( $issuer );
$race_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$race_fixture = job_fixture( $race_ids, '100.00', '60.00', $actor );
wp_set_current_user( $issuer );
$race_accepted = Grants::accept_plan( $race_fixture['plan'], $race_grant['public_id'], $issuer, 'id-307-revoke' );
$race_job = $race_accepted['job'];
// Revoke before execution.
Grants::revoke( $race_grant['public_id'], $issuer );
wp_set_current_user( $actor );
$actor_user->set_role( 'administrator' );
Worker::queue_job( (int) $race_job['id'] );
for ( $i = 0; $i < 3 && ! JState::is_terminal( Repo::read( (int) $race_job['id'] )['status'] ); ++$i ) {
	run_worker( array( 'job_id' => (int) $race_job['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
}
eq( fresh_price( $race_ids[0] ), '100.00', 'revoked grant item not applied' );
assert_zero_changes( $race_ids, hash_rows( $race_ids ), 'revoke race zero price change' );
marker( 'concurrency: revoke racing the item transaction denies with zero saves' );

// ---------------------------------------------------------------------------
// Frame-leak check after forced errors: no authorized frame survives a
// rolled-back item.
// ---------------------------------------------------------------------------
ok( Guard::current_frame() === null, 'no frame leaks outside an item transaction' );
$fault_id = create_product( '100.00' );
wp_set_current_user( $issuer );
$fault_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$fault_fixture = job_fixture( array( $fault_id ), '100.00', '50.00', $actor );
$fault_accepted = Grants::accept_plan( $fault_fixture['plan'], $fault_grant['public_id'], $issuer, 'id-307-fault' );
$fault_job = $fault_accepted['job'];
// Force a mutation-boundary failure: change the product to draft mid-flight so
// the item precondition conflicts and the item rolls back.
wp_set_current_user( 1 );
$fp = wc_get_product( $fault_id );
$fp->set_status( 'draft' );
$fp->save();
wp_set_current_user( $actor );
$actor_user->set_role( 'administrator' );
Worker::queue_job( (int) $fault_job['id'] );
run_worker( array( 'job_id' => (int) $fault_job['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
ok( Guard::current_frame() === null, 'no frame survives a rolled-back item' );
eq( fresh_price( $fault_id ), '100.00', 'failed item price untouched' );
assert_zero_changes( array( $fault_id ), hash_rows( array( $fault_id ) ), 'forced error zero change' );
marker( 'concurrency: frame-leak check after forced errors' );

// ---------------------------------------------------------------------------
// Kill-switch: missing/malformed option fails closed with zero saves.
// ---------------------------------------------------------------------------
wp_set_current_user( $issuer );
$kill_ids = array( create_product( '100.00' ) );
$kill_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $issuer );
update_option( GSchema::DISABLE_OPTION, '1', false );
ok( Grants::is_disabled() === true, 'kill switch engaged' );
wp_set_current_user( $actor );
$kill_fixture = job_fixture( $kill_ids, '100.00', '40.00', $actor );
wp_set_current_user( $issuer );
$kill_accepted = Grants::accept_plan( $kill_fixture['plan'], $kill_grant['public_id'], $issuer, 'id-307-kill' );
$kill_job = $kill_accepted['job'];
wp_set_current_user( $actor );
$actor_user->set_role( 'administrator' );
Worker::queue_job( (int) $kill_job['id'] );
for ( $i = 0; $i < 3 && ! JState::is_terminal( Repo::read( (int) $kill_job['id'] )['status'] ); ++$i ) {
	run_worker( array( 'job_id' => (int) $kill_job['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
}
eq( fresh_price( $kill_ids[0] ), '100.00', 'kill switch denies item with zero saves' );
assert_zero_changes( $kill_ids, hash_rows( $kill_ids ), 'kill switch zero change' );
// Missing option fails closed.
delete_option( GSchema::DISABLE_OPTION );
ok( Grants::is_disabled() === true, 'missing kill-switch option fails closed' );
update_option( GSchema::DISABLE_OPTION, '0', false );
ok( Grants::is_disabled() === false, 'explicit 0 re-enables' );
marker( 'kill-switch: missing/malformed option fails closed, explicit 0 enables' );

marker( '#307 perimeter guard complete' );
