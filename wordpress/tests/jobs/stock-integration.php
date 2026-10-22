<?php
// #310 product_stock asset through the existing job/journal/grant engine.
// Container gate, same fixture as #109/#298/#307. Proves bounded absolute
// stock writes, zero-write refusals, checkout-race CONFLICT, variation parent
// sync, idempotency, and the crash matrix.
require __DIR__ . '/../durable/hook-torture.php';
use WriteLeash\Agent_Jobs_Rest as AgentRest;
use WriteLeash\Change_Plan;
use WriteLeash\Grant_Repository as Grants;
use WriteLeash\Grant_Schema as GSchema;
use WriteLeash\Job_Item_State as IState;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Job_State as JState;
use WriteLeash\Job_Worker as Worker;
use WriteLeash\Price_Apply_Journal as Journal;
use WriteLeash\Price_Cache_Verifier as Verifier;
use WriteLeash\Price_Selection_Spec as Selection;
use WriteLeash\Price_Store_Context;
use WriteLeash\Product_Price_Selector as Selector;
use WriteLeash\Stock_Operation as StockOp;

global $wpdb, $work, $assertions, $hook_log;
$assertions = 0;
$work = sys_get_temp_dir() . '/wl310-' . bin2hex( random_bytes( 5 ) );
mkdir( $work );
$hook_log = $work . '/hooks.log';
touch( $hook_log );
$GLOBALS['wl108_active'] = true;
$GLOBALS['wl108_log'] = $hook_log;
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wl108_hook_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, product_id bigint unsigned NOT NULL, hook varchar(100) NOT NULL) ENGINE=InnoDB" );

function eq( $actual, $expected, string $label ): void {
	global $assertions;
	++$assertions;
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
}
function ok( bool $condition, string $label ): void { eq( $condition, true, $label ); }
function marker( string $label ): void {
	global $assertions;
	echo "#310 $label: PASS ($assertions assertions)\n";
}
function user_id( string $login, string $role ): int {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) { return (int) $existing->ID; }
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 20 ), 'role' => $role, 'user_email' => $login . '@example.test' ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'user failed: ' . $login ); }
	return (int) $id;
}
function manual_status_product( string $status = 'instock' ): int {
	$previous = get_current_user_id();
	wp_set_current_user( 1 );
	$p = new WC_Product_Simple();
	$p->set_name( 'WL310-status-' . wp_generate_uuid4() );
	$p->set_status( 'publish' );
	$p->set_regular_price( '10.00' );
	$p->set_manage_stock( false );
	$p->set_stock_status( $status );
	$p->save();
	$id = $p->get_id();
	wp_set_current_user( $previous );
	return $id;
}
function stock_product( int $qty, string $status = 'instock' ): int {
	$previous = get_current_user_id();
	wp_set_current_user( 1 );
	$p = new WC_Product_Simple();
	$p->set_name( 'WL310-' . wp_generate_uuid4() );
	$p->set_status( 'publish' );
	$p->set_regular_price( '10.00' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $qty );
	$p->set_stock_status( $status );
	$p->save();
	$id = $p->get_id();
	wp_set_current_user( $previous );
	return $id;
}
function variation_pair( int $qty ): array {
	$previous = get_current_user_id();
	wp_set_current_user( 1 );
	$parent = new WC_Product_Variable();
	$parent->set_name( 'WL310-parent-' . wp_generate_uuid4() );
	$parent->set_status( 'publish' );
	$parent_id = $parent->save();
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent_id );
	$variation->set_status( 'publish' );
	$variation->set_regular_price( '12.00' );
	$variation->set_manage_stock( true );
	$variation->set_stock_quantity( $qty );
	$variation->set_stock_status( 'instock' );
	$variation_id = $variation->save();
	\WC_Post_Data::do_deferred_product_sync();
	wp_set_current_user( $previous );
	return array( $parent_id, $variation_id );
}
function qty_of( int $id ): string {
	Verifier::invalidate( $id );
	$p = wc_get_product( $id );
	$value = $p ? $p->get_stock_quantity( 'edit' ) : null;
	return null === $value ? '' : (string) $value;
}
function status_of( int $id ): string {
	Verifier::invalidate( $id );
	$p = wc_get_product( $id );
	return $p ? (string) $p->get_stock_status( 'edit' ) : '';
}
function hash_rows( array $ids ): string {
	global $wpdb;
	$parts = array();
	if ( $ids ) {
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$parts[] = $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_status,post_type FROM {$wpdb->posts} WHERE ID IN ($placeholders) ORDER BY ID", $ids ), ARRAY_A );
		$parts[] = $wpdb->get_results( $wpdb->prepare( "SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) AND meta_key IN ('_stock','_stock_status','_manage_stock','_regular_price','_price') ORDER BY post_id,meta_key,meta_id", $ids ), ARRAY_A );
		$parts[] = $wpdb->get_results( $wpdb->prepare( "SELECT product_id,stock_quantity,stock_status FROM {$wpdb->wc_product_meta_lookup} WHERE product_id IN ($placeholders) ORDER BY product_id", $ids ), ARRAY_A );
	}
	return hash( 'sha256', serialize( $parts ) );
}
function journal_row( string $plan_id, int $product_id ): ?array {
	$db = Verifier::observer();
	try { return Journal::read( $db, $plan_id, $product_id ); }
	finally { $db->close(); }
}
function limits( int $items ): array { return array( 'max_items' => $items, 'budget_seconds' => 20 ); }
function start_worker( array $spec ): array {
	global $work;
	$prefix = $work . '/proc-' . bin2hex( random_bytes( 5 ) );
	$spec = array_merge( array( 'result' => $prefix . '.result', 'barrier' => $prefix . '.barrier', 'release' => $prefix . '.release', 'started' => $prefix . '.started', 'hook_log' => $GLOBALS['hook_log'] ), $spec );
	file_put_contents( $prefix . '.json', json_encode( $spec ) );
	$proc = proc_open( array( 'wp', '--path=' . ABSPATH, 'eval-file', __DIR__ . '/worker.php', $prefix . '.json' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $prefix . '.stdout', 'w' ), 2 => array( 'file', $prefix . '.stderr', 'w' ) ), $pipes );
	if ( ! is_resource( $proc ) ) { throw new RuntimeException( 'worker spawn failed' ); }
	fclose( $pipes[0] );
	return array( 'proc' => $proc, 'spec' => $spec, 'prefix' => $prefix );
}
function await_file( string $file ): void {
	$deadline = microtime( true ) + 25;
	clearstatcache( true, $file );
	while ( ! is_file( $file ) || '' === (string) file_get_contents( $file ) ) {
		if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'missing barrier: ' . $file ); }
		usleep( 10000 );
		clearstatcache( true, $file );
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
function run_job( int $job_id ): array {
	Worker::queue_job( $job_id );
	$last = array();
	for ( $i = 0; $i < 6 && ! JState::is_terminal( (string) ( Repo::read( $job_id )['status'] ?? '' ) ); ++$i ) {
		$last = Worker::run( $job_id, limits( 5 ), true );
		if ( in_array( (string) ( $last['stop'] ?? '' ), array( 'JOB_NOT_FOUND', 'SCHEMA_UNAVAILABLE', 'DEPENDENCY_UNAVAILABLE', 'LEASE_HELD' ), true ) ) {
			throw new RuntimeException( 'worker stopped: ' . json_encode( $last ) );
		}
	}
	return $last;
}
function accept_stock( array $grant, int $issuer, int $actor, array $ids, string $type, string $field, string $input, string $key ): array {
	wp_set_current_user( $issuer );
	$selection = Selection::ids( $ids );
	$snapshots = Selector::resolve( $selection );
	$operation = new StockOp( $type, $field, $input, '1000', '0' );
	$plan = Change_Plan::create_stock( AgentRest::plan_id( (string) $grant['public_id'], $key ), gmdate( 'Y-m-d\TH:i:s\Z' ), $actor, Price_Store_Context::current(), $selection, $operation, $snapshots );
	return Grants::accept_plan( $plan, (string) $grant['public_id'], $issuer, $key );
}
function reservation_state( int $grant_db_id, int $job_id, int $product_id ): string {
	global $wpdb;
	$table = GSchema::reservations_table( $wpdb );
	return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM %i WHERE grant_db_id=%d AND job_id=%d AND product_id=%d', $table, $grant_db_id, $job_id, $product_id ) );
}

eq( get_bloginfo( 'version' ), '7.1.2', 'pinned WordPress' );
eq( WC_VERSION, '11.1.2', 'pinned WooCommerce' );
update_option( 'woocommerce_manage_stock', 'yes' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
$issuer = user_id( 'wl310-issuer', 'administrator' );
$actor = user_id( 'wl310-actor', 'administrator' );
ok( $issuer !== $actor, 'issuer and actor differ' );
wp_set_current_user( $issuer );
update_option( GSchema::DISABLE_OPTION, '0', false );

// Bounded SET / INCREASE_INT / DECREASE_INT reach APPLIED with journal evidence.
$set_id = stock_product( 10 );
$inc_id = stock_product( 4 );
$dec_id = stock_product( 9 );
$grant = Grants::create_grant( $issuer, $actor, 20, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
$accepted = accept_stock( $grant, $issuer, $actor, array( $set_id ), StockOp::SET, StockOp::FIELD_QUANTITY, '25', 'wl310-set' );
run_job( (int) $accepted['job']['id'] );
eq( Repo::read( (int) $accepted['job']['id'] )['status'], JState::COMPLETED, 'SET job completed' );
eq( qty_of( $set_id ), '25', 'SET wrote the frozen absolute quantity' );
eq( journal_row( $accepted['job']['plan_id'], $set_id )['state'], 'APPLIED', 'SET journal APPLIED' );
eq( reservation_state( (int) $grant['id'], (int) $accepted['job']['id'], $set_id ), 'CONSUMED', 'SET reservation consumed once' );
$inc = accept_stock( $grant, $issuer, $actor, array( $inc_id ), StockOp::INCREASE_INT, StockOp::FIELD_QUANTITY, '3', 'wl310-inc' );
run_job( (int) $inc['job']['id'] );
eq( qty_of( $inc_id ), '7', 'INCREASE_INT wrote expected+delta' );
eq( journal_row( $inc['job']['plan_id'], $inc_id )['state'], 'APPLIED', 'INCREASE journal APPLIED' );
$dec = accept_stock( $grant, $issuer, $actor, array( $dec_id ), StockOp::DECREASE_INT, StockOp::FIELD_QUANTITY, '4', 'wl310-dec' );
run_job( (int) $dec['job']['id'] );
eq( qty_of( $dec_id ), '5', 'DECREASE_INT wrote expected-delta' );
eq( (int) Grants::read_by_public_id( $grant['public_id'] )['budget_consumed'], 3, 'three APPLIED stock items consumed three units' );
marker( 'bounded SET/INCREASE/DECREASE reach APPLIED' );

// Floor and delta overflow refuse before any write.
$floor_id = stock_product( 2 );
$floor_before = hash_rows( array( $floor_id ) );
try {
	accept_stock( $grant, $issuer, $actor, array( $floor_id ), StockOp::DECREASE_INT, StockOp::FIELD_QUANTITY, '5', 'wl310-floor' );
	throw new RuntimeException( 'floor violation accepted' );
} catch ( \WriteLeash\Grant_Error $error ) {
	eq( $error->reason(), 'GRANT_SCOPE_DENIED', 'floor violation refused' );
}
eq( hash_rows( array( $floor_id ) ), $floor_before, 'floor violation zero writes' );
eq( qty_of( $floor_id ), '2', 'floor violation left the quantity' );
$overflow_id = stock_product( 1 );
$overflow_before = hash_rows( array( $overflow_id ) );
try {
	wp_set_current_user( $issuer );
	$selection = Selection::ids( array( $overflow_id ) );
	$operation = new StockOp( StockOp::INCREASE_INT, StockOp::FIELD_QUANTITY, '1001', '1000', '0' );
	$plan = Change_Plan::create_stock( 'wl310-overflow-' . wp_generate_uuid4(), gmdate( 'Y-m-d\TH:i:s\Z' ), $actor, Price_Store_Context::current(), $selection, $operation, Selector::resolve( $selection ) );
	Grants::accept_plan( $plan, (string) $grant['public_id'], $issuer, 'wl310-overflow' );
	throw new RuntimeException( 'delta overflow accepted' );
} catch ( \WriteLeash\Grant_Error $error ) {
	eq( $error->reason(), 'GRANT_SCOPE_DENIED', 'delta overflow refused' );
}
eq( hash_rows( array( $overflow_id ) ), $overflow_before, 'delta overflow zero writes' );
marker( 'floor and delta overflow refuse with zero writes' );

// Checkout race between Preview and Apply is CONFLICT, never an overwrite.
$race_id = stock_product( 10 );
$race = accept_stock( $grant, $issuer, $actor, array( $race_id ), StockOp::SET, StockOp::FIELD_QUANTITY, '40', 'wl310-race' );
wp_set_current_user( 1 );
wc_update_product_stock( $race_id, 2, 'decrease' );
eq( qty_of( $race_id ), '8', 'checkout reduction landed before Apply' );
run_job( (int) $race['job']['id'] );
eq( qty_of( $race_id ), '8', 'CONFLICT did not overwrite the raced quantity' );
eq( journal_row( $race['job']['plan_id'], $race_id )['state'], 'CONFLICT', 'checkout race journal CONFLICT' );
eq( reservation_state( (int) $grant['id'], (int) $race['job']['id'], $race_id ), 'RELEASED', 'conflict released the reservation' );
marker( 'checkout race is CONFLICT with zero overwrite' );

// Variation parent stock-status sync inside the frame.
list( $parent_id, $variation_id ) = variation_pair( 6 );
wp_set_current_user( $issuer );
$status_grant = Grants::create_grant( $issuer, $actor, 5, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
// Quantity 0 makes Woo derive outofstock on save; the parent sync must
// observe that derived status inside the same frame.
$status_job = accept_stock( $status_grant, $issuer, $actor, array( $variation_id ), StockOp::SET, StockOp::FIELD_QUANTITY, '0', 'wl310-var' );
run_job( (int) $status_job['job']['id'] );
eq( qty_of( $variation_id ), '0', 'variation quantity SET applied' );
eq( status_of( $variation_id ), 'outofstock', 'Woo derived the variation status from the quantity' );
eq( status_of( $parent_id ), 'outofstock', 'parent stock status synced from the child' );
global $wpdb;
$parent_lookup = $wpdb->get_var( $wpdb->prepare( "SELECT stock_status FROM {$wpdb->wc_product_meta_lookup} WHERE product_id=%d", $parent_id ) );
eq( (string) $parent_lookup, 'outofstock', 'parent lookup stock_status matches the sync' );
eq( journal_row( $status_job['job']['plan_id'], $variation_id )['state'], 'APPLIED', 'status journal APPLIED' );
eq( (int) Grants::read_by_public_id( $status_grant['public_id'] )['budget_consumed'], 1, 'status item consumed one unit' );
marker( 'variation parent stock-status sync' );

$manual_id = manual_status_product( 'instock' );
wp_set_current_user( $issuer );
$manual = accept_stock( $grant, $issuer, $actor, array( $manual_id ), StockOp::SET, StockOp::FIELD_STATUS, 'onbackorder', 'wl310-manual-status' );
run_job( (int) $manual['job']['id'] );
eq( status_of( $manual_id ), 'onbackorder', 'manual stock_status SET applied' );
eq( journal_row( $manual['job']['plan_id'], $manual_id )['state'], 'APPLIED', 'manual status journal APPLIED' );
marker( 'manual stock_status SET' );

// Same-key replay returns the stored job; a different payload rejects.
$rest_id = stock_product( 3 );
wp_set_current_user( $issuer );
$body = array(
	'idempotency_key' => 'wl310-rest',
	'actor_id' => $actor,
	'grant_version' => (int) $grant['version'],
	'asset' => 'product_stock',
	'stock_field' => 'stock_quantity',
	'operation' => 'SET',
	'target' => '9',
	'product_ids' => array( $rest_id ),
);
$request = new WP_REST_Request( 'POST', '/writeleash/v1/grants/' . $grant['public_id'] . '/jobs' );
$request->set_header( 'Content-Type', 'application/json' );
$request->set_body( wp_json_encode( $body ) );
$first = rest_do_request( $request );
eq( $first->get_status(), 201, 'stock REST acceptance' );
$again = rest_do_request( $request );
eq( $again->get_status(), 200, 'same stock payload replays' );
eq( $again->get_data()['job']['public_id'], $first->get_data()['job']['public_id'], 'replay returns the stored job' );
$other = $body;
$other['target'] = '11';
$request->set_body( wp_json_encode( $other ) );
$mismatch = rest_do_request( $request );
eq( $mismatch->get_status(), 409, 'different stock payload rejected' );
eq( (string) ( $mismatch->get_data()['data']['reason'] ?? '' ), 'GRANT_IDEMPOTENCY_MISMATCH', 'idempotency mismatch reason' );
$rest_job = Repo::read( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . \WriteLeash\Job_Schema::jobs_table( $wpdb ) . ' WHERE public_id=%s', $first->get_data()['job']['public_id'] ) ) );
run_job( (int) $rest_job['id'] );
eq( qty_of( $rest_id ), '9', 'REST stock job applied the frozen target' );
marker( 'idempotency replay and mismatch' );

// REST scope-denial matrix for the new asset: every refusal is typed and writes nothing.
wp_set_current_user( $issuer );
$deny_id = stock_product( 4 );
$deny_before = hash_rows( array( $deny_id ) );
$deny = static function ( array $overrides, string $key ) use ( $grant, $issuer, $deny_id ): \WP_REST_Response {
	wp_set_current_user( $issuer );
	$body = array_merge( array(
		'idempotency_key' => $key,
		'actor_id' => (int) $grant['actor_id'],
		'grant_version' => (int) $grant['version'],
		'asset' => 'product_stock',
		'stock_field' => 'stock_quantity',
		'operation' => 'SET',
		'target' => '9',
		'product_ids' => array( $deny_id ),
	), $overrides );
	$request = new WP_REST_Request( 'POST', '/writeleash/v1/grants/' . $grant['public_id'] . '/jobs' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	return rest_do_request( $request );
};
$deny_reason = static function ( \WP_REST_Response $response ): string {
	$data = $response->get_data();
	return is_array( $data ) ? (string) ( $data['data']['reason'] ?? $data['reason'] ?? '' ) : '';
};
$managed_status = $deny( array( 'stock_field' => 'stock_status', 'target' => 'outofstock' ), 'wl310-deny-managed-status' );
eq( $managed_status->get_status(), 409, 'status SET on managed stock refused' );
eq( $deny_reason( $managed_status ), 'GRANT_SCOPE_DENIED', 'managed status refusal reason' );
$unchanged = $deny( array( 'target' => '4' ), 'wl310-deny-unchanged' );
eq( $unchanged->get_status(), 409, 'non-changing stock target refused' );
eq( $deny_reason( $unchanged ), 'GRANT_SCOPE_DENIED', 'non-changing stock refusal reason' );
$identity = $deny( array( 'actor_id' => $issuer ), 'wl310-deny-identity' );
eq( $identity->get_status(), 403, 'wrong actor refused' );
eq( $deny_reason( $identity ), 'GRANT_IDENTITY_INVALID', 'wrong actor refusal reason' );
$version = $deny( array( 'grant_version' => (int) $grant['version'] + 1 ), 'wl310-deny-version' );
eq( $version->get_status(), 409, 'wrong grant version refused' );
eq( $deny_reason( $version ), 'GRANT_VERSION_MISMATCH', 'wrong grant version reason' );
$bad_field = $deny( array( 'stock_field' => 'manage_stock' ), 'wl310-deny-field' );
eq( $bad_field->get_status(), 400, 'unknown stock field refused' );
eq( $deny_reason( $bad_field ), 'MALFORMED', 'unknown stock field reason' );
$bad_operation = $deny( array( 'operation' => 'INCREASE_PERCENT' ), 'wl310-deny-operation' );
eq( $bad_operation->get_status(), 400, 'percentage stock operation refused' );
eq( $deny_reason( $bad_operation ), 'UNSUPPORTED_OPERATION', 'percentage stock operation reason' );
$bad_target = $deny( array( 'target' => '-1' ), 'wl310-deny-target' );
eq( $bad_target->get_status(), 400, 'signed stock target refused' );
eq( $deny_reason( $bad_target ), 'MALFORMED', 'signed stock target reason' );
$price_vocabulary = $deny( array( 'price_field' => 'regular_price' ), 'wl310-deny-mixed' );
eq( $price_vocabulary->get_status(), 400, 'mixed price/stock vocabulary refused' );
wp_set_current_user( $issuer );
update_option( GSchema::DISABLE_OPTION, '1', false );
$disabled = $deny( array(), 'wl310-deny-disabled' );
eq( $disabled->get_status(), 403, 'stock acceptance refused while disabled' );
eq( $deny_reason( $disabled ), 'GRANT_DISABLED', 'disabled grant reason' );
update_option( GSchema::DISABLE_OPTION, '0', false );
eq( hash_rows( array( $deny_id ) ), $deny_before, 'REST scope denials wrote nothing' );
marker( 'REST scope-denial matrix' );

// Crash matrix: kill before commit rolls back; kill after commit reconciles once.
$crash_id = stock_product( 12 );
$crash = accept_stock( $grant, $issuer, $actor, array( $crash_id ), StockOp::SET, StockOp::FIELD_QUANTITY, '30', 'wl310-crash-before' );
Worker::queue_job( (int) $crash['job']['id'] );
$killed = start_worker( array( 'job_id' => (int) $crash['job']['id'], 'mode' => 'run', 'limits' => limits( 1 ), 'mutator_checkpoint' => 'AFTER_WOO_SAVE_BEFORE_JOURNAL', 'mutator_fault' => 'wait' ) );
await_file( $killed['spec']['barrier'] );
proc_terminate( $killed['proc'], 9 );
finish_worker( $killed, true );
eq( journal_row( $crash['job']['plan_id'], $crash_id )['state'], 'PENDING', 'pre-commit kill leaves PENDING' );
eq( qty_of( $crash_id ), '12', 'pre-commit kill rolled the stock write back' );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . \WriteLeash\Job_Schema::jobs_table( $wpdb ) . ' SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=%d', (int) $crash['job']['id'] ) );
run_job( (int) $crash['job']['id'] );
eq( qty_of( $crash_id ), '30', 'recovery applied the stock target once' );
eq( journal_row( $crash['job']['plan_id'], $crash_id )['state'], 'APPLIED', 'recovery journal APPLIED' );
eq( reservation_state( (int) $grant['id'], (int) $crash['job']['id'], $crash_id ), 'CONSUMED', 'recovery consumed once' );

$after_id = stock_product( 6 );
$after = accept_stock( $grant, $issuer, $actor, array( $after_id ), StockOp::SET, StockOp::FIELD_QUANTITY, '16', 'wl310-crash-after' );
Worker::queue_job( (int) $after['job']['id'] );
$killed = start_worker( array( 'job_id' => (int) $after['job']['id'], 'mode' => 'run', 'limits' => limits( 1 ), 'mutator_checkpoint' => 'AFTER_COMMIT_BEFORE_RESPONSE', 'mutator_fault' => 'wait' ) );
await_file( $killed['spec']['barrier'] );
eq( journal_row( $after['job']['plan_id'], $after_id )['state'], 'APPLIED', 'post-commit kill sees durable APPLIED' );
proc_terminate( $killed['proc'], 9 );
finish_worker( $killed, true );
$consumed_before = (int) Grants::read_by_public_id( $grant['public_id'] )['budget_consumed'];
$wpdb->query( $wpdb->prepare( 'UPDATE ' . \WriteLeash\Job_Schema::jobs_table( $wpdb ) . ' SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=%d', (int) $after['job']['id'] ) );
run_job( (int) $after['job']['id'] );
eq( qty_of( $after_id ), '16', 'post-commit recovery did not rewrite stock' );
eq( (int) Grants::read_by_public_id( $grant['public_id'] )['budget_consumed'], $consumed_before, 'post-commit recovery did not consume again' );
eq( Repo::items( (int) $after['job']['id'] )[0]['state'], IState::APPLIED, 'post-commit item reconciled APPLIED' );
marker( 'crash matrix' );

echo "#310 stock asset gate: PASS ($assertions assertions)\n";
