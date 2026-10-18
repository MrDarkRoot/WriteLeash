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
}, 10, 6 );
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
	// #307: seed the catalogue as an operator. The fixture itself must never
	// perform an unframed catalogue write as the restricted principal.
	$previous = get_current_user_id();
	wp_set_current_user( 1 );
	$p = new WC_Product_Simple();
	$p->set_name( 'WL307-' . wp_generate_uuid4() );
	$p->set_status( $status );
	$p->set_regular_price( $price );
	$p->save();
	$id = $p->get_id();
	wp_set_current_user( $previous );
	return $id;
}
/** Operator-seeded product that manages stock, so stock helpers really write. */
function create_stock_product( string $price, int $quantity ): int {
	$previous = get_current_user_id();
	wp_set_current_user( 1 );
	$p = new WC_Product_Simple();
	$p->set_name( 'WL307-stock-' . wp_generate_uuid4() );
	$p->set_status( 'publish' );
	$p->set_regular_price( $price );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $quantity );
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
	// rest_do_request runs the same rest_pre_dispatch filter chain the plugin
	// hooks, with the current user as the authenticated principal.
	$request = new \WP_REST_Request( $method, $route );
	if ( $body ) { $request->set_body_params( $body ); }
	return rest_do_request( $request );
}
function deny_code(): string { return 'writeleash_perimeter_denied'; }
function exc_code( \Throwable $e ): string {
	if ( method_exists( $e, 'getErrorCode' ) ) { return $e->getErrorCode(); }
	return (string) $e->getCode();
}
/** WP_Error or WP_REST_Response -> refusal status (0 when neither carries one). */
function resp_status( $response ): int {
	if ( is_wp_error( $response ) ) { $data = $response->get_error_data(); return (int) ( is_array( $data ) ? ( $data['status'] ?? 0 ) : 0 ); }
	return (int) $response->get_status();
}
/** WP_Error or WP_REST_Response -> machine error code. */
function resp_code( $response ): string {
	if ( is_wp_error( $response ) ) { return (string) $response->get_error_code(); }
	if ( method_exists( $response, 'get_error_code' ) ) { return (string) ( $response->get_error_code() ?? '' ); }
	$data = $response->get_data();
	if ( is_array( $data ) && isset( $data['code'] ) ) { return (string) $data['code']; }
	if ( is_array( $data ) && is_array( $data['data'] ?? null ) && isset( $data['data']['code'] ) ) { return (string) $data['data']['code']; }
	return '';
}
function resp_is_error( $response ): bool {
	if ( is_wp_error( $response ) ) { return true; }
	if ( method_exists( $response, 'is_error' ) ) { return (bool) $response->is_error(); }
	return resp_status( $response ) >= 400;
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
	return array( 'issuer' => $ids['wl307-issuer'], 'actor' => $ids['wl307-actor'], 'manager' => $ids['wl307-manager'] );
}
function job_fixture( array $ids, string $price, string $target, int $actor ): array {
	// The plan freezes its actor from whoever is current at preview time, so the
	// restricted principal must be current here for the job to run as them.
	unset( $price );
	$previous = get_current_user_id();
	wp_set_current_user( $actor );
	$plan = Planner::preview( Selection::ids( $ids ), new Operation( Operation::SET, $target ), new Policy( 1000, '100', '100', false, '100' ) );
	wp_set_current_user( $previous );
	eq( (int) $plan->data()['actor_id'], $actor, 'frozen actor is the restricted principal' );
	return array( 'plan' => $plan );
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
ok( Grants::is_restricted_actor( $actor ) === true, 'grant actor is restricted' );
ok( Grants::is_restricted_actor( $issuer ) === false, 'grant issuer is not restricted' );
ok( Grants::is_restricted_actor( $manager ) === false, 'shop manager is not restricted' );
ok( Grants::is_restricted_actor( 0 ) === false, 'anonymous/cron user is not restricted' );
marker( 'restricted actor predicate' );

// ---------------------------------------------------------------------------
// Bypass probes: every path denied before persistence, zero row changes.
// ---------------------------------------------------------------------------
$probe_id = create_product( '100.00' );
$probe_before = hash_rows( array( $probe_id ) );
wp_set_current_user( $actor );

// 1. WC REST single product create/update/delete.
$create = rest_dispatch( 'POST', '/wc/v3/products', array( 'name' => 'WL307-rest', 'regular_price' => '50.00', 'status' => 'publish' ) );
ok( resp_is_error( $create ), 'REST product create is an error response' );
eq( resp_status( $create ), 403, 'REST product create status 403' );
eq( resp_code( $create ), deny_code(), 'REST product create denial code' );
$update = rest_dispatch( 'PUT', '/wc/v3/products/' . $probe_id, array( 'regular_price' => '50.00' ) );
ok( resp_is_error( $update ), 'REST product update is an error response' );
eq( resp_status( $update ), 403, 'REST product update status 403' );
$del = rest_dispatch( 'DELETE', '/wc/v3/products/' . $probe_id );
ok( resp_is_error( $del ), 'REST product delete is an error response' );
eq( resp_status( $del ), 403, 'REST product delete status 403' );

// 2. WC REST batch create/update/delete.
$batch = rest_dispatch( 'POST', '/wc/v3/products/batch', array( 'create' => array( array( 'name' => 'WL307-batch', 'regular_price' => '50.00' ) ) ) );
ok( resp_is_error( $batch ), 'REST product batch is an error response' );
eq( resp_status( $batch ), 403, 'REST product batch status 403' );

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

// 4. wc_update_product_stock() directly (product must manage stock to write).
$stock_probe        = create_stock_product( '100.00', 10 );
$stock_probe_before = hash_rows( array( $stock_probe ) );
try {
	wc_update_product_stock( $stock_probe, 2, 'decrease' );
	throw new RuntimeException( 'expected stock update to be denied' );
} catch ( \WC_Data_Exception $e ) {
	eq( exc_code( $e ), deny_code(), 'stock update denial code' );
} catch ( \RuntimeException $e ) {
	throw new RuntimeException( 'stock update must throw WC_Data_Exception, got ' . $e->getMessage() );
}
assert_zero_changes( array( $stock_probe ), $stock_probe_before, 'stock probe zero change' );

// 5. Protected postmeta add/update/delete on price/stock keys.
ok( update_post_meta( $probe_id, '_regular_price', '60.00' ) === false, 'update_post_meta _regular_price denied' );
ok( update_post_meta( $probe_id, '_price', '60.00' ) === false, 'update_post_meta _price denied' );
ok( update_post_meta( $probe_id, '_sale_price', '60.00' ) === false, 'update_post_meta _sale_price denied' );
ok( add_post_meta( $probe_id, '_sale_price', '60.00', true ) === false, 'add_post_meta _sale_price denied' );
ok( update_post_meta( $stock_probe, '_stock', '999' ) === false, 'update_post_meta _stock denied' );
ok( update_post_meta( $stock_probe, '_stock_status', 'outofstock' ) === false, 'update_post_meta _stock_status denied' );
ok( delete_post_meta( $stock_probe, '_stock' ) === false, 'delete_post_meta _stock denied' );
assert_zero_changes( array( $stock_probe ), $stock_probe_before, 'stock meta probes' );

// 6. Trash / delete of a product (wp_trash_post, wp_delete_post, WC_Product::delete).
try {
	wp_trash_post( $probe_id );
	throw new RuntimeException( 'expected trash to be denied' );
} catch ( \WC_Data_Exception $e ) {
	eq( exc_code( $e ), deny_code(), 'trash post denial code' );
} catch ( \RuntimeException $e ) {
	throw new RuntimeException( 'trash must throw WC_Data_Exception, got ' . $e->getMessage() );
}
try {
	wp_delete_post( $probe_id, true );
	throw new RuntimeException( 'expected delete to be denied' );
} catch ( \WC_Data_Exception $e ) {
	eq( exc_code( $e ), deny_code(), 'direct delete denial code' );
} catch ( \RuntimeException $e ) {
	throw new RuntimeException( 'delete must throw WC_Data_Exception, got ' . $e->getMessage() );
}
try {
	wc_get_product( $probe_id )->delete();
	throw new RuntimeException( 'expected WC_Product::delete to be denied' );
} catch ( \WC_Data_Exception $e ) {
	eq( exc_code( $e ), deny_code(), 'WC_Product::delete denial code' );
} catch ( \RuntimeException $e ) {
	throw new RuntimeException( 'WC_Product::delete must throw WC_Data_Exception, got ' . $e->getMessage() );
}

assert_zero_changes( array( $probe_id ), $probe_before, 'all bypass probes' );
ok( count( $GLOBALS['wl307_denials'] ) >= 1, 'at least one refusal logged' );
eq( fresh_price( $probe_id ), '100.00', 'bypass target price untouched' );
marker( 'bypass probes: REST single+batch, save, stock, meta, trash/delete all denied before persistence' );

// GET reads remain allowed for a restricted principal.
$get = rest_dispatch( 'GET', '/wc/v3/products/' . $probe_id );
ok( ! resp_is_error( $get ), 'REST product read allowed for restricted principal' );
eq( resp_status( $get ), 200, 'REST product read status 200' );

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
foreach ( $item_ids as $iid ) { eq( fresh_price( $iid ), '80.00', 'controlled price write inside frame applied ' . $iid ); }
eq( saves( $item_ids[0] ), 1, 'exactly one Woo save for the framed product' );
$grant_row = Grants::read_by_public_id( $grant['public_id'] );
eq( (int) $grant_row['budget_consumed'], count( $item_ids ), 'one budget unit per applied item' );
marker( 'inside frame: controlled price writes apply, one save each, one budget unit per item' );

// ---------------------------------------------------------------------------
// Non-interference: other principals and paths unchanged.
// ---------------------------------------------------------------------------
$manager_id = create_product( '100.00' );
$denials_before_manager = count( $GLOBALS['wl307_denials'] );
wp_set_current_user( $manager );
$mp = wc_get_product( $manager_id );
$mp->set_regular_price( '90.00' );
$mp->save(); // shop manager (not restricted) edits freely
eq( fresh_price( $manager_id ), '90.00', 'shop manager edit unaffected' );
eq( count( $GLOBALS['wl307_denials'] ), $denials_before_manager, 'no refusal logged for a non-restricted manager' );
marker( 'non-interference: human shop manager edit unaffected' );

// Guest checkout stock reduction (user_id = 0) and cron (user_id = 0).
$stock_id = create_stock_product( '10.00', 10 );
wp_set_current_user( 0 );
$stock_before = wc_get_product( $stock_id )->get_stock_quantity( 'edit' );
wc_update_product_stock( $stock_id, 2, 'decrease' );
eq( wc_get_product( $stock_id )->get_stock_quantity( 'edit' ), $stock_before - 2, 'guest stock reduction unaffected' );
marker( 'non-interference: guest/cron user_id=0 stock reduction unaffected' );

// Variable parent sync inside the frame: authorized only as an explicit extra product id.
wp_set_current_user( 1 ); // seed the variable fixtures as an operator
$parent = new \WC_Product_Variable();
$parent->set_name( 'WL307-parent-' . wp_generate_uuid4() );
$parent->set_status( 'publish' );
$parent_id = $parent->save();
$variation = new \WC_Product_Variation();
$variation->set_parent_id( $parent_id );
$variation->set_status( 'publish' );
$variation->set_regular_price( '100.00' );
$variation_id = $variation->save();
// The fixture's variation save queued its parent for WooCommerce's deferred
// sync at shutdown; flush it here while an operator is current so the test
// process does not leave a restricted principal as the shutdown writer.
\WC_Post_Data::do_deferred_product_sync();
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
$var_denials = array_values( array_filter( $GLOBALS['wl307_denials'], function ( $d ) use ( $variation_id, $parent_id ) { return in_array( (int) $d['object_id'], array( (int) $variation_id, (int) $parent_id ), true ); } ) );
eq( $var_denials, array(), 'no refusal for variation or parent sync inside the frame' );
marker( 'non-interference: variable parent sync inside frame succeeds with no extra budget unit' );

// `WC_Product_Variable::sync()` outside a frame is refused (direct PHP row).
wp_set_current_user( $actor );
$parent_before_sync = hash_rows( array( $parent_id ) );
try {
	\WC_Product_Variable::sync( wc_get_product( $parent_id ) );
	throw new RuntimeException( 'expected direct variable sync to be denied' );
} catch ( \WC_Data_Exception $e ) {
	eq( exc_code( $e ), deny_code(), 'out-of-frame variable sync denial code' );
} catch ( \RuntimeException $e ) {
	throw new RuntimeException( 'variable sync must throw WC_Data_Exception, got ' . $e->getMessage() );
}
assert_zero_changes( array( $parent_id ), $parent_before_sync, 'out-of-frame variable sync' );
marker( 'bypass probes: out-of-frame WC_Product_Variable::sync denied with zero rows' );

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
$race_before = hash_rows( $race_ids );
wp_set_current_user( $actor );
$actor_user->set_role( 'administrator' );
Worker::queue_job( (int) $race_job['id'] );
for ( $i = 0; $i < 3 && ! JState::is_terminal( Repo::read( (int) $race_job['id'] )['status'] ); ++$i ) {
	run_worker( array( 'job_id' => (int) $race_job['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
}
eq( fresh_price( $race_ids[0] ), '100.00', 'revoked grant item not applied' );
assert_zero_changes( $race_ids, $race_before, 'revoke race zero price change' );
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
wp_set_current_user( $issuer );
$fault_accepted = Grants::accept_plan( $fault_fixture['plan'], $fault_grant['public_id'], $issuer, 'id-307-fault' );
$fault_job = $fault_accepted['job'];
// Force a mutation-boundary failure: change the product to draft mid-flight so
// the item precondition conflicts and the item rolls back.
wp_set_current_user( 1 );
$fp = wc_get_product( $fault_id );
$fp->set_status( 'draft' );
$fp->save();
$fault_before = hash_rows( array( $fault_id ) );
wp_set_current_user( $actor );
$actor_user->set_role( 'administrator' );
Worker::queue_job( (int) $fault_job['id'] );
run_worker( array( 'job_id' => (int) $fault_job['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
ok( Guard::current_frame() === null, 'no frame survives a rolled-back item' );
eq( fresh_price( $fault_id ), '100.00', 'failed item price untouched' );
assert_zero_changes( array( $fault_id ), $fault_before, 'forced error zero change' );
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
$kill_before = hash_rows( $kill_ids );
wp_set_current_user( $actor );
$actor_user->set_role( 'administrator' );
Worker::queue_job( (int) $kill_job['id'] );
for ( $i = 0; $i < 3 && ! JState::is_terminal( Repo::read( (int) $kill_job['id'] )['status'] ); ++$i ) {
	run_worker( array( 'job_id' => (int) $kill_job['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
}
eq( fresh_price( $kill_ids[0] ), '100.00', 'kill switch denies item with zero saves' );
assert_zero_changes( $kill_ids, $kill_before, 'kill switch zero change' );
// Missing option fails closed.
delete_option( GSchema::DISABLE_OPTION );
ok( Grants::is_disabled() === true, 'missing kill-switch option fails closed' );
update_option( GSchema::DISABLE_OPTION, '0', false );
ok( Grants::is_disabled() === false, 'explicit 0 re-enables' );
marker( 'kill-switch: missing/malformed option fails closed, explicit 0 enables' );

marker( '#307 perimeter guard complete' );
