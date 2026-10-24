<?php
// #311 restricted-principal boundary matrix (container gate).
// Runs inside the #109 fixture via wp eval-file:
//   WL109_CACHE=default WRITELEASH_SOURCE_SHA=<sha> wp --path=$site eval-file /opt/tests/jobs/matrix-integration.php
// Requires MySQL 8.0.44 / MariaDB 10.11.15, pinned WP 7.1.2 / PHP 8.2 / Woo 11.1.2,
// default or Redis object cache. One executable evidence suite for the
// restricted-principal boundary: every monitored write path is exercised as a
// restricted principal outside an Execution Frame and must deny before
// persistence with zero changed rows (full-row hashes of wp_posts + all
// postmeta + wc_product_meta_lookup). Non-restricted callers (guest checkout,
// user-0 background/cron, shop_manager, and the operator preview/approve/
// resume/undo flow) must keep working, and controlled paths (price and stock
// apply) must still apply inside a matching frame. Emits exactly one
// single-line MATRIX-JSON document on stdout and one per-run file, so CI can
// aggregate the four engine/cache combinations into a headline document.
require __DIR__ . '/../durable/hook-torture.php';

use WriteLeash\Agent_Jobs_Rest as AgentRest;
use WriteLeash\Change_Plan;
use WriteLeash\Free_Support_Contract;
use WriteLeash\Grant_Error;
use WriteLeash\Grant_Repository as Grants;
use WriteLeash\Grant_Schema as GSchema;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Job_Schema as Schema;
use WriteLeash\Job_State as JState;
use WriteLeash\Job_Worker as Worker;
use WriteLeash\Price_Cache_Verifier as Verifier;
use WriteLeash\Price_Decimal;
use WriteLeash\Price_Operation as Operation;
use WriteLeash\Price_Selection_Spec as Selection;
use WriteLeash\Price_Store_Context;
use WriteLeash\Product_Price_Selector as Selector;
use WriteLeash\Safety_Policy as Policy;
use WriteLeash\Stock_Operation as StockOp;
use WriteLeash\Undo_Repository as URepo;
use WriteLeash\Undo_Worker as UWorker;
use WriteLeash\Woo_Price_Planner as Planner;

// Every write surface #307 monitors. A path with no proving cell in this suite
// is reported NOT_TESTED with an explicit withheld claim, never as PASS.
const WL311_MONITORED_PATHS = array(
	'rest_create', 'rest_update', 'rest_delete', 'rest_batch', 'save_simple',
	'save_variation', 'variable_sync', 'stock_helper', 'meta_update', 'meta_add',
	'meta_delete', 'trash', 'delete_post', 'delete_product', 'stock_rest',
);

// The controlled positives: the same surfaces must still apply inside a
// matching Execution Frame.
const WL311_INFRAME_PATHS = array(
	'frame_price_apply', 'frame_stock_apply', 'frame_variation_price_sync',
	'frame_variation_stock_sync',
);

global $wpdb, $work, $assertions, $hook_log;
$assertions = 0;
$work = sys_get_temp_dir() . '/wl311-' . bin2hex( random_bytes( 5 ) );
mkdir( $work );
$hook_log = $work . '/hooks.log';
touch( $hook_log );
$GLOBALS['wl108_active'] = true;
$GLOBALS['wl108_log'] = $hook_log;
$GLOBALS['wl311_cells'] = array();
$GLOBALS['wl311_failed'] = array();
$GLOBALS['wl311_fatal'] = '';
$GLOBALS['wl311_denials'] = array();
$GLOBALS['wl311_sha'] = trim( (string) getenv( 'WRITELEASH_SOURCE_SHA' ) );
if ( 'UNKNOWN' === strtoupper( $GLOBALS['wl311_sha'] ) ) { $GLOBALS['wl311_sha'] = ''; }
$GLOBALS['wl311_engine'] = 'unknown';
$GLOBALS['wl311_engine_version'] = '';
$GLOBALS['wl311_cache'] = (string) ( getenv( 'WL109_CACHE' ) ?: 'unknown' );
$GLOBALS['wl311_undone'] = array();
add_action( 'writeleash_perimeter_denied', function ( $code, $where, $actor, $object_id, $kind, $detail ) {
	$GLOBALS['wl311_denials'][] = array( 'code' => (string) $code, 'where' => (string) $where, 'actor' => (int) $actor, 'object_id' => (int) $object_id, 'kind' => (string) $kind, 'detail' => (string) $detail );
}, 10, 6 );
@unlink( WP_CONTENT_DIR . '/debug.log' );

function eq( $actual, $expected, string $label ): void {
	global $assertions;
	++$assertions;
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
}
function price_eq( string $actual, string $expected, string $label ): void {
	eq( Price_Decimal::parse( $actual ), Price_Decimal::parse( $expected ), $label );
}
function ok( bool $condition, string $label ): void { eq( $condition, true, $label ); }
function marker( string $label ): void {
	global $assertions;
	echo "#311 $label: PASS ($assertions assertions)\n";
}
function deny_code( ?\Throwable $e = null ): string {
	if ( null === $e ) { return 'writeleash_perimeter_denied'; }
	if ( method_exists( $e, 'getErrorCode' ) ) { $code = $e->getErrorCode(); if ( $code ) { return (string) $code; } }
	return (string) $e->getCode();
}
function exc_code( \Throwable $e ): string { return deny_code( $e ); }
function resp_is_error( $response ): bool {
	if ( is_wp_error( $response ) ) { return true; }
	return $response instanceof \WP_REST_Response && $response->is_error();
}
function resp_status( $response ): int {
	if ( is_wp_error( $response ) ) { $data = $response->get_error_data(); return (int) ( is_array( $data ) ? ( $data['status'] ?? 0 ) : 0 ); }
	return $response instanceof \WP_REST_Response ? (int) $response->get_status() : 0;
}
function resp_code( $response ): string {
	if ( is_wp_error( $response ) ) { return (string) $response->get_error_code(); }
	if ( method_exists( $response, 'get_error_code' ) ) { return (string) ( $response->get_error_code() ?? '' ); }
	$data = $response instanceof \WP_REST_Response ? $response->get_data() : array();
	if ( is_array( $data ) && isset( $data['code'] ) ) { return (string) $data['code']; }
	return '';
}
function resp_reason( $response ): string {
	if ( is_wp_error( $response ) ) { $data = $response->get_error_data(); return is_array( $data ) ? (string) ( $data['reason'] ?? '' ) : ''; }
	$data = $response instanceof \WP_REST_Response ? $response->get_data() : array();
	if ( ! is_array( $data ) ) { return ''; }
	return (string) ( $data['data']['reason'] ?? $data['reason'] ?? '' );
}
function rest_dispatch( string $method, string $path, array $body = array(), array $query = array() ) {
	$request = new \WP_REST_Request( $method, $path );
	if ( $query ) { $request->set_query_params( $query ); }
	if ( $body ) {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
	}
	return rest_do_request( $request );
}
function denial_offset(): int { return count( $GLOBALS['wl311_denials'] ); }
function denials_since( int $offset ): array { return array_slice( $GLOBALS['wl311_denials'], $offset ); }
function assert_denial( array $denials, string $where, string $label ): void {
	foreach ( $denials as $d ) {
		if ( 'writeleash_perimeter_denied' === $d['code'] && $where === $d['where'] ) { return; }
	}
	throw new RuntimeException( $label . ': no perimeter denial at ' . $where . '; saw ' . json_encode( array_column( $denials, 'where' ) ) );
}
function assert_no_denials( array $denials, string $label ): void {
	if ( array() === $denials ) { return; }
	throw new RuntimeException( $label . ': unexpected denials ' . json_encode( array_column( $denials, 'where' ) ) );
}
function assert_no_stock_denial( array $denials, string $label ): void {
	foreach ( $denials as $d ) {
		if ( 'stock' === $d['kind'] ) { throw new RuntimeException( $label . ': the stock write itself was intercepted: ' . json_encode( $d ) ); }
	}
}
function price_state( int $id ): string {
	return raw_meta( $id, '_regular_price' ) . '|' . raw_meta( $id, '_price' ) . '|' . raw_meta( $id, '_sale_price' );
}
function snapshot( array $ids ): array {
	global $wpdb;
	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
	$parts = array( 'posts' => array(), 'postmeta' => array(), 'lookup' => array() );
	if ( $ids ) {
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		$parts['posts'] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID IN ($ph) ORDER BY ID", $ids ), ARRAY_A );
		$parts['postmeta'] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ($ph) ORDER BY meta_id", $ids ), ARRAY_A );
		$parts['lookup'] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$lookup} WHERE product_id IN ($ph) ORDER BY product_id", $ids ), ARRAY_A );
	}
	$tables = array();
	foreach ( $parts as $name => $rows ) { $tables[ $name ] = hash( 'sha256', serialize( $rows ) ) . ':' . count( $rows ); }
	return array( 'hash' => 'sha256:' . hash( 'sha256', serialize( $parts ) ), 'tables' => $tables, 'rows' => count( $parts['posts'] ) );
}
function prefix_snapshot( string $prefix ): array {
	global $wpdb;
	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s AND post_type IN ('product','product_variation') ORDER BY ID", $prefix . '%' ) ) );
	return snapshot( $ids );
}
function diff_tables( array $before, array $after ): string {
	$changed = array();
	foreach ( $after['tables'] as $name => $hash ) {
		if ( $hash !== ( $before['tables'][ $name ] ?? '' ) ) { $changed[] = $name; }
	}
	return $changed ? implode( '+', $changed ) : 'row-count';
}
function run_cell( string $id, string $path, string $expect, callable $fn, array $ids = array(), ?string $probe_prefix = null, ?string $durable_expect = null ): void {
	$before = $probe_prefix ? prefix_snapshot( $probe_prefix ) : snapshot( $ids );
	$offset = denial_offset();
	$out = array();
	$error = '';
	try {
		$out = (array) $fn();
	} catch ( \Throwable $e ) {
		$error = get_class( $e ) . '/' . deny_code( $e ) . ': ' . $e->getMessage();
	}
	wp_set_current_user( 1 );
	$after = $probe_prefix ? prefix_snapshot( $probe_prefix ) : snapshot( $ids );
	$denials = denials_since( $offset );
	$unchanged = $before['hash'] === $after['hash'];
	$wants_write = null === $durable_expect ? in_array( $expect, array( 'allow', 'apply', 'unleash' ), true ) : ( 'any' === $durable_expect ? null : 'change' === $durable_expect );
	$status = 'PASS';
	$reason = '';
	if ( '' !== $error ) {
		$status = 'FAIL';
		$reason = 'unexpected error: ' . $error;
	} elseif ( null !== $wants_write && $wants_write && $unchanged ) {
		$status = 'FAIL';
		$reason = 'expected a durable change; rows are unchanged';
	} elseif ( null !== $wants_write && ! $wants_write && ! $unchanged ) {
		$status = 'FAIL';
		$reason = 'expected the rows to stay unchanged; they changed: ' . diff_tables( $before, $after );
	}
	$record = array(
		'id' => $id,
		'path' => $path,
		'expect' => $expect,
		'status' => $status,
		'guard' => (string) ( $out['guard'] ?? '' ),
		'denials' => array_values( array_unique( array_map( static function ( $d ) { return $d['where']; }, $denials ) ) ),
		'durable' => $unchanged ? 'unchanged' : 'changed',
		'rows' => $after['rows'],
		'evidence' => 'sha256:' . hash( 'sha256', serialize( array( $id, $expect, $before['hash'], $after['hash'], $denials ) ) ),
		'reason' => $reason,
	);
	$GLOBALS['wl311_cells'][] = $record;
	printf( "#311 %-28s %-5s expect=%-8s durable=%-9s denials=[%s]\n", $id, $status, $expect, $record['durable'], implode( ',', $record['denials'] ) );
	if ( 'FAIL' === $status ) { $GLOBALS['wl311_failed'][] = $id . ' (' . $reason . ')'; }
}
function ensure_user( string $login, string $role ): int {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) { return (int) $existing->ID; }
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 20 ), 'role' => $role, 'user_email' => $login . '@example.test' ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'user creation failed: ' . $login ); }
	return (int) $id;
}
function seed_product( string $prefix, string $price, bool $manage_stock = false, int $quantity = 0 ): int {
	$previous = get_current_user_id();
	wp_set_current_user( 1 );
	$p = new WC_Product_Simple();
	$p->set_name( $prefix . wp_generate_uuid4() );
	$p->set_status( 'publish' );
	$p->set_regular_price( $price );
	if ( $manage_stock ) { $p->set_manage_stock( true ); $p->set_stock_quantity( $quantity ); $p->set_stock_status( 'instock' ); }
	$p->save();
	$id = (int) $p->get_id();
	wp_set_current_user( $previous );
	return $id;
}
function seed_variation( string $prefix, string $price ): array {
	$previous = get_current_user_id();
	wp_set_current_user( 1 );
	$parent = new WC_Product_Variable();
	$parent->set_name( $prefix . 'parent-' . wp_generate_uuid4() );
	$parent->set_status( 'publish' );
	$parent_id = (int) $parent->save();
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent_id );
	$variation->set_status( 'publish' );
	$variation->set_regular_price( $price );
	$variation_id = (int) $variation->save();
	\WC_Post_Data::do_deferred_product_sync();
	wp_set_current_user( $previous );
	return array( $parent_id, $variation_id );
}
function seed_stock_variation( string $prefix, int $quantity ): array {
	$previous = get_current_user_id();
	wp_set_current_user( 1 );
	$parent = new WC_Product_Variable();
	$parent->set_name( $prefix . 'parent-' . wp_generate_uuid4() );
	$parent->set_status( 'publish' );
	$parent_id = (int) $parent->save();
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent_id );
	$variation->set_status( 'publish' );
	$variation->set_regular_price( '12.00' );
	$variation->set_manage_stock( true );
	$variation->set_stock_quantity( $quantity );
	$variation->set_stock_status( 'instock' );
	$variation_id = (int) $variation->save();
	\WC_Post_Data::do_deferred_product_sync();
	wp_set_current_user( $previous );
	return array( $parent_id, $variation_id );
}
function fresh_price( int $id ): string {
	Verifier::invalidate( $id );
	$p = wc_get_product( $id );
	return $p ? (string) $p->get_regular_price( 'edit' ) : '';
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
function lookup_row( int $id ): array {
	global $wpdb;
	$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$lookup} WHERE product_id=%d", $id ), ARRAY_A );
	return is_array( $row ) ? $row : array();
}
function raw_meta( int $id, string $key ): string {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id DESC LIMIT 1", $id, $key ) );
}
function meta_count( int $id, string $key ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s", $id, $key ) );
}
function limits( int $items, int $seconds = 20 ): array { return array( 'max_items' => $items, 'budget_seconds' => $seconds ); }
function start_probe( array $spec, string $script ): array {
	global $work;
	$prefix = $work . '/probe-' . bin2hex( random_bytes( 5 ) );
	$spec = array_merge( array( 'result' => $prefix . '.result', 'hook_log' => $GLOBALS['hook_log'] ), $spec );
	file_put_contents( $prefix . '.json', (string) json_encode( $spec ) );
	$proc = proc_open( array( 'wp', '--path=' . ABSPATH, 'eval-file', __DIR__ . '/' . $script, $prefix . '.json' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $prefix . '.stdout', 'w' ), 2 => array( 'file', $prefix . '.stderr', 'w' ) ), $pipes );
	if ( ! is_resource( $proc ) ) { throw new RuntimeException( 'probe spawn failed: ' . $script ); }
	fclose( $pipes[0] );
	return array( 'proc' => $proc, 'spec' => $spec, 'prefix' => $prefix );
}
function finish_probe( array $probe ): array {
	$exit = proc_close( $probe['proc'] );
	if ( 0 !== $exit ) {
		throw new RuntimeException( 'probe exit ' . $exit . ': ' . file_get_contents( $probe['prefix'] . '.stdout' ) . file_get_contents( $probe['prefix'] . '.stderr' ) );
	}
	if ( ! is_file( $probe['spec']['result'] ) ) { throw new RuntimeException( 'probe wrote no result: ' . $probe['prefix'] ); }
	return (array) json_decode( (string) file_get_contents( $probe['spec']['result'] ), true );
}
function run_probe( array $spec, string $script ): array { return finish_probe( start_probe( $spec, $script ) ); }
function run_job_inline( int $job_id ): void {
	Worker::queue_job( $job_id );
	for ( $i = 0; $i < 6 && ! JState::is_terminal( (string) ( Repo::read( $job_id )['status'] ?? '' ) ); ++$i ) {
		$last = Worker::run( $job_id, limits( 5 ), true );
		if ( in_array( (string) ( $last['stop'] ?? '' ), array( 'JOB_NOT_FOUND', 'SCHEMA_UNAVAILABLE', 'DEPENDENCY_UNAVAILABLE', 'LEASE_HELD' ), true ) ) {
			throw new RuntimeException( 'worker stopped: ' . json_encode( $last ) );
		}
	}
}
function accept_price( array $grant, int $issuer, int $actor, array $ids, string $target, string $key ): array {
	wp_set_current_user( $actor );
	$plan = Planner::preview( Selection::ids( $ids ), new Operation( Operation::SET, $target ), new Policy( 1000, '100', '100', false, '100' ) );
	wp_set_current_user( $issuer );
	return Grants::accept_plan( $plan, (string) $grant['public_id'], $issuer, $key );
}
function accept_stock( array $grant, int $issuer, int $actor, array $ids, string $type, string $field, string $input, string $key ): array {
	wp_set_current_user( $issuer );
	$selection = Selection::ids( $ids );
	$snapshots = Selector::resolve( $selection );
	$operation = new StockOp( $type, $field, $input, '1000', '0' );
	$plan = Change_Plan::create_stock( AgentRest::plan_id( (string) $grant['public_id'], $key ), gmdate( 'Y-m-d\TH:i:s\Z' ), $actor, Price_Store_Context::current(), $selection, $operation, $snapshots );
	return Grants::accept_plan( $plan, (string) $grant['public_id'], $issuer, $key );
}
function expect_grant_error( string $reason, callable $fn, string $label ): void {
	try {
		$fn();
		throw new RuntimeException( $label . ': expected ' . $reason . ' but no error' );
	} catch ( Grant_Error $e ) {
		eq( $reason, $e->reason(), $label );
	}
}
function job_count_for_keys( array $keys ): int {
	global $wpdb;
	if ( array() === $keys ) { return 0; }
	$table = Schema::jobs_table( $wpdb );
	$ph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE grant_idempotency_key IN ($ph)", array_merge( array( $table ), $keys ) ) );
}
function matrix_counts( array $cells ): array {
	$counts = array( 'pass' => 0, 'fail' => 0, 'blocked' => 0, 'not_tested' => 0, 'total' => count( $cells ) );
	foreach ( $cells as $cell ) {
		$key = strtolower( (string) $cell['status'] );
		if ( isset( $counts[ $key ] ) ) { ++$counts[ $key ]; }
	}
	return $counts;
}
function emit_matrix_doc(): void {
	$cells = $GLOBALS['wl311_cells'];
	$covered = array_column( $cells, 'id' );
	$unsupported = array_values( array_diff( WL311_MONITORED_PATHS, $covered ) );
	foreach ( $unsupported as $missing ) {
		$cells[] = array( 'id' => $missing, 'path' => '(not exercised by this suite)', 'expect' => 'deny', 'status' => 'NOT_TESTED', 'guard' => '', 'denials' => array(), 'durable' => 'unknown', 'rows' => 0, 'evidence' => '', 'reason' => 'no proving test executed for this path; claim withheld' );
	}
	$missing_positives = array_values( array_diff( WL311_INFRAME_PATHS, $covered ) );
	foreach ( $missing_positives as $missing ) {
		$cells[] = array( 'id' => $missing, 'path' => '(in-frame positive not exercised by this suite)', 'expect' => 'apply', 'status' => 'NOT_TESTED', 'guard' => '', 'denials' => array(), 'durable' => 'unknown', 'rows' => 0, 'evidence' => '', 'reason' => 'no proving test executed for this in-frame positive; the claim that the path still applies inside a frame is withheld' );
	}
	$sha = (string) $GLOBALS['wl311_sha'];
	$blocked = '' === $sha;
	if ( $blocked ) {
		foreach ( $cells as $i => $cell ) {
			if ( 'NOT_TESTED' === $cell['status'] ) { continue; }
			$cells[ $i ]['observed_status'] = $cell['status'];
			$cells[ $i ]['status'] = 'BLOCKED';
			$cells[ $i ]['reason'] = 'WRITELEASH_SOURCE_SHA is not set; a result cannot be tied to a source revision';
		}
	}
	if ( '' !== $GLOBALS['wl311_fatal'] ) {
		if ( ! array_filter( $cells, static function ( $c ) { return 'FAIL' === $c['status']; } ) ) {
			$cells[] = array( 'id' => 'suite', 'path' => '(suite aborted)', 'expect' => 'deny', 'status' => 'FAIL', 'guard' => '', 'denials' => array(), 'durable' => 'unknown', 'rows' => 0, 'evidence' => '', 'reason' => 'suite aborted: ' . $GLOBALS['wl311_fatal'] );
		}
	}
	$counts = matrix_counts( $cells );
	$doc = array(
		'schema' => 1,
		'source_sha' => $blocked ? 'UNKNOWN' : $sha,
		'captured_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'environment' => array(
			'php' => PHP_VERSION,
			'wordpress' => get_bloginfo( 'version' ),
			'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'db_engine' => (string) $GLOBALS['wl311_engine'],
			'db_version' => (string) $GLOBALS['wl311_engine_version'],
			'cache_mode' => (string) $GLOBALS['wl311_cache'],
		),
		'combinations_ran' => array(
			array( 'db_engine' => (string) $GLOBALS['wl311_engine'], 'cache_mode' => (string) $GLOBALS['wl311_cache'], 'cells' => $counts['total'], 'summary' => $counts ),
		),
		'combinations_not_tested' => array(
			array( 'combination' => 'WP 6.7 / WC 9.x / PHP 8.3', 'reason' => 'not in the CI fixture image', 'claim_withheld' => 'no supported-range claim is made for this combination' ),
		),
		'cells' => $cells,
		'summary' => $counts,
	);
	$dir = (string) ( getenv( 'WL311_MATRIX_DIR' ) ?: '/tmp/wl311-matrix' );
	if ( ! is_dir( $dir ) ) { @mkdir( $dir, 0777, true ); }
	$file = $dir . '/' . $GLOBALS['wl311_engine'] . '-' . $GLOBALS['wl311_cache'] . '.json';
	file_put_contents( $file, (string) wp_json_encode( $doc, JSON_UNESCAPED_SLASHES ) );
	echo 'MATRIX-JSON: ' . wp_json_encode( $doc, JSON_UNESCAPED_SLASHES ) . "\n";
	echo '#311 summary: ' . json_encode( $counts ) . ' artifact=' . $file . "\n";
	if ( $blocked ) { exit( 2 ); }
	if ( $counts['fail'] > 0 || '' !== $GLOBALS['wl311_fatal'] ) { exit( 1 ); }
	exit( 0 );
}

try {
	wp_set_current_user( 1 );
	eq( '7.1.2', get_bloginfo( 'version' ), 'pinned WordPress' );
	eq( '11.1.2', WC_VERSION, 'pinned WooCommerce' );
	eq( Free_Support_Contract::woo_supported( WC_VERSION ), true, 'Woo inside the supported range' );
	update_option( 'woocommerce_currency', 'USD' );
	update_option( 'woocommerce_price_num_decimals', 2 );
	update_option( 'woocommerce_manage_stock', 'yes' );
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wl108_hook_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, product_id bigint unsigned NOT NULL, hook varchar(100) NOT NULL) ENGINE=InnoDB" );
	$raw = (string) $wpdb->get_var( 'SELECT VERSION()' );
	$GLOBALS['wl311_engine'] = str_contains( $raw, 'MariaDB' ) ? 'mariadb' : 'mysql';
	$GLOBALS['wl311_engine_version'] = (string) explode( '-', $raw )[0];
	echo '#311 environment: php=' . PHP_VERSION . ' wp=' . get_bloginfo( 'version' ) . ' wc=' . WC_VERSION . ' db=' . $GLOBALS['wl311_engine'] . '/' . $GLOBALS['wl311_engine_version'] . ' cache=' . $GLOBALS['wl311_cache'] . ' sha=' . ( '' === $GLOBALS['wl311_sha'] ? 'UNKNOWN' : $GLOBALS['wl311_sha'] ) . "\n";
	eq( Schema::ready( $wpdb ), true, 'job schema ready' );
	eq( 'active', (string) get_option( 'writeleash_runner_state' ), 'runner authority is active' );
	GSchema::install();
	eq( GSchema::ready( $wpdb ), true, 'grant schema ready' );
	wp_cache_delete( GSchema::DISABLE_OPTION, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	if ( Grants::is_disabled() ) { Grants::enable( 1 ); }
	eq( false, Grants::is_disabled(), 'grant kill-switch starts enabled' );

	$issuer = ensure_user( 'wl311-issuer', 'administrator' );
	$actor = ensure_user( 'wl311-actor', 'administrator' );
	$approver = ensure_user( 'wl311-approver', 'administrator' );
	$manager = ensure_user( 'wl311-manager', 'shop_manager' );
	$actor2 = ensure_user( 'wl311-actor-removed', 'administrator' );
	eq( 5, count( array_unique( array( $issuer, $actor, $approver, $manager, $actor2 ) ) ), 'five distinct identities' );
	wp_set_current_user( $issuer );
	$grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
	ok( Grants::is_restricted_actor( $actor ), 'standing grant restricts the actor' );
	ok( ! Grants::is_restricted_actor( $manager ), 'shop_manager is not a restricted principal' );
	ok( ! Grants::is_restricted_actor( $issuer ), 'issuer is not a restricted principal' );
	marker( 'fixture, identities and standing grant' );

	// -----------------------------------------------------------------------
	// A. Restricted principal, outside any frame: every monitored write path
	//    denies before persistence and leaves the durable rows untouched.
	// -----------------------------------------------------------------------
	$rest_create_prefix = 'WL311-rest-create-';
	run_cell( 'rest_create', 'Woo REST v3 create (POST /wc/v3/products)', 'deny', function () use ( $actor, $rest_create_prefix ) {
		wp_set_current_user( $actor );
		$offset = denial_offset();
		$resp = rest_dispatch( 'POST', '/wc/v3/products', array( 'name' => $rest_create_prefix . wp_generate_uuid4(), 'regular_price' => '51.00', 'status' => 'publish' ) );
		eq( 403, resp_status( $resp ), 'REST create refused with 403' );
		eq( deny_code(), resp_code( $resp ), 'REST create refused with the perimeter code' );
		assert_denial( denials_since( $offset ), 'rest', 'REST create denial recorded at rest' );
		return array( 'guard' => 'writeleash_perimeter_denied@rest' );
	}, array(), $rest_create_prefix );

	$rest_update_id = seed_product( 'WL311-rest-update-', '50.00' );
	run_cell( 'rest_update', 'Woo REST v3 update (PUT /wc/v3/products/{id})', 'deny', function () use ( $actor, $rest_update_id ) {
		wp_set_current_user( $actor );
		$offset = denial_offset();
		$resp = rest_dispatch( 'PUT', '/wc/v3/products/' . $rest_update_id, array( 'regular_price' => '61.00' ) );
		eq( 403, resp_status( $resp ), 'REST update refused with 403' );
		eq( deny_code(), resp_code( $resp ), 'REST update refused with the perimeter code' );
		assert_denial( denials_since( $offset ), 'rest', 'REST update denial recorded at rest' );
		eq( '50.00', fresh_price( $rest_update_id ), 'REST update left the price untouched' );
		return array( 'guard' => 'writeleash_perimeter_denied@rest' );
	}, array( $rest_update_id ) );

	$rest_delete_id = seed_product( 'WL311-rest-delete-', '50.00' );
	run_cell( 'rest_delete', 'Woo REST v3 delete (DELETE /wc/v3/products/{id}?force=true)', 'deny', function () use ( $actor, $rest_delete_id ) {
		wp_set_current_user( $actor );
		$offset = denial_offset();
		$resp = rest_dispatch( 'DELETE', '/wc/v3/products/' . $rest_delete_id, array(), array( 'force' => true ) );
		eq( 403, resp_status( $resp ), 'REST delete refused with 403' );
		eq( deny_code(), resp_code( $resp ), 'REST delete refused with the perimeter code' );
		assert_denial( denials_since( $offset ), 'rest', 'REST delete denial recorded at rest' );
		eq( 'publish', get_post_status( $rest_delete_id ), 'REST delete left the product published' );
		return array( 'guard' => 'writeleash_perimeter_denied@rest' );
	}, array( $rest_delete_id ) );

	$rest_batch_id = seed_product( 'WL311-rest-batch-', '50.00' );
	$rest_batch_prefix = 'WL311-rest-batch-create-';
	run_cell( 'rest_batch', 'Woo REST v3 batch (POST /wc/v3/products/batch: update + create)', 'deny', function () use ( $actor, $rest_batch_id, $rest_batch_prefix ) {
		wp_set_current_user( $actor );
		$offset = denial_offset();
		$resp = rest_dispatch( 'POST', '/wc/v3/products/batch', array(
			'update' => array( array( 'id' => $rest_batch_id, 'regular_price' => '63.00' ) ),
			'create' => array( array( 'name' => $rest_batch_prefix . wp_generate_uuid4(), 'regular_price' => '64.00' ) ),
		) );
		eq( 403, resp_status( $resp ), 'REST batch refused with 403' );
		eq( deny_code(), resp_code( $resp ), 'REST batch refused with the perimeter code' );
		assert_denial( denials_since( $offset ), 'rest', 'REST batch denial recorded at rest' );
		eq( '50.00', fresh_price( $rest_batch_id ), 'REST batch left the update target untouched' );
		return array( 'guard' => 'writeleash_perimeter_denied@rest' );
	}, array( $rest_batch_id ) );
	$batch_leftovers = prefix_snapshot( $rest_batch_prefix );
	eq( 0, $batch_leftovers['rows'], 'REST batch created no product row' );

	$save_simple_id = seed_product( 'WL311-save-simple-', '50.00' );
	run_cell( 'save_simple', 'WC_Product_Simple::save() from PHP outside any frame', 'deny', function () use ( $actor, $save_simple_id ) {
		wp_set_current_user( $actor );
		$offset = denial_offset();
		$p = wc_get_product( $save_simple_id );
		$p->set_regular_price( '52.00' );
		try {
			$p->save();
			throw new RuntimeException( 'expected the direct save to be denied' );
		} catch ( \WC_Data_Exception $e ) {
			eq( deny_code(), exc_code( $e ), 'direct save denial code' );
		}
		assert_denial( denials_since( $offset ), 'product_save', 'direct save denial recorded at product_save' );
		eq( '50.00', raw_meta( $save_simple_id, '_regular_price' ), 'direct save left the durable price untouched' );
		return array( 'guard' => 'woocommerce_before_product_object_save' );
	}, array( $save_simple_id ) );

	list( $var_parent, $var_child ) = seed_variation( 'WL311-save-variation-', '60.00' );
	run_cell( 'save_variation', 'WC_Product_Variation::save() from PHP outside any frame (Woo 11.1.2 fires woocommerce_before_product_object_save for every product type)', 'deny', function () use ( $actor, $var_child ) {
		wp_set_current_user( $actor );
		$offset = denial_offset();
		$v = wc_get_product( $var_child );
		$v->set_regular_price( '62.00' );
		try {
			$v->save();
			throw new RuntimeException( 'expected the variation save to be denied' );
		} catch ( \WC_Data_Exception $e ) {
			eq( deny_code(), exc_code( $e ), 'variation save denial code' );
		}
		assert_denial( denials_since( $offset ), 'product_save', 'variation save denial recorded at product_save' );
		eq( '60.00', raw_meta( $var_child, '_regular_price' ), 'variation save left the durable price untouched' );
		return array( 'guard' => 'woocommerce_before_product_object_save (Woo 11.1.2 emits no product_variation_object_save action)' );
	}, array( $var_parent, $var_child ) );

	run_cell( 'variable_sync', 'WC_Product_Variable::sync() from PHP outside any frame', 'deny', function () use ( $actor, $var_parent ) {
		wp_set_current_user( $actor );
		$offset = denial_offset();
		try {
			\WC_Product_Variable::sync( wc_get_product( $var_parent ) );
			throw new RuntimeException( 'expected the direct variable sync to be denied' );
		} catch ( \WC_Data_Exception $e ) {
			eq( deny_code(), exc_code( $e ), 'variable sync denial code' );
		}
		assert_denial( denials_since( $offset ), 'product_save', 'variable sync denial recorded at the parent save' );
		return array( 'guard' => 'woocommerce_before_product_object_save (sync parent save)' );
	}, array( $var_parent, $var_child ) );

	$stock_helper_id = seed_product( 'WL311-stock-helper-', '10.00', true, 10 );
	run_cell( 'stock_helper', 'wc_update_product_stock() helper outside any frame', 'deny', function () use ( $actor, $stock_helper_id ) {
		wp_set_current_user( $actor );
		$offset = denial_offset();
		try {
			wc_update_product_stock( $stock_helper_id, 2, 'decrease' );
			throw new RuntimeException( 'expected the stock helper to be denied' );
		} catch ( \WC_Data_Exception $e ) {
			eq( deny_code(), exc_code( $e ), 'stock helper denial code' );
		}
		assert_denial( denials_since( $offset ), 'product_set_stock', 'stock helper denial recorded at product_set_stock' );
		eq( '10', qty_of( $stock_helper_id ), 'stock helper left the durable quantity untouched' );
		return array( 'guard' => 'woocommerce_product_before_set_stock + woocommerce_update_product_stock_query' );
	}, array( $stock_helper_id ) );

	$meta_update_id = seed_product( 'WL311-meta-update-', '50.00', true, 10 );
	run_cell( 'meta_update', 'update_post_meta() on price/stock keys', 'deny', function () use ( $actor, $meta_update_id ) {
		wp_set_current_user( $actor );
		$offset = denial_offset();
		$keys = array( '_regular_price' => '70.00', '_sale_price' => '71.00', '_price' => '72.00', '_stock' => '999', '_stock_status' => 'outofstock' );
		foreach ( $keys as $key => $value ) { ok( false === update_post_meta( $meta_update_id, $key, $value ), 'update_post_meta denied for ' . $key ); }
		assert_denial( denials_since( $offset ), 'update_post_metadata', 'meta update denial recorded at update_post_metadata' );
		eq( '50.00', raw_meta( $meta_update_id, '_regular_price' ), 'meta update left the durable price untouched' );
		return array( 'guard' => 'update_post_metadata' );
	}, array( $meta_update_id ) );

	$meta_add_id = seed_product( 'WL311-meta-add-', '50.00', true, 10 );
	run_cell( 'meta_add', 'add_post_meta() for every protected price/stock key', 'deny', function () use ( $actor, $meta_add_id ) {
		wp_set_current_user( $actor );
		$offset = denial_offset();
		$keys = array( '_regular_price' => '70.00', '_sale_price' => '80.00', '_price' => '72.00', '_sale_price_dates_from' => '2026-01-01', '_sale_price_dates_to' => '2026-12-31', '_stock' => '999', '_stock_status' => 'outofstock' );
		$counts = array();
		foreach ( $keys as $key => $value ) { $counts[ $key ] = meta_count( $meta_add_id, $key ); }
		foreach ( $keys as $key => $value ) { ok( false === add_post_meta( $meta_add_id, $key, $value, false ), 'add_post_meta denied for ' . $key ); }
		assert_denial( denials_since( $offset ), 'add_post_metadata', 'meta add denial recorded at add_post_metadata' );
		eq( '50.00', raw_meta( $meta_add_id, '_regular_price' ), 'meta add left the durable price untouched' );
		eq( '10', raw_meta( $meta_add_id, '_stock' ), 'meta add left the durable stock untouched' );
		foreach ( $keys as $key => $value ) { eq( $counts[ $key ], meta_count( $meta_add_id, $key ), 'meta add wrote no extra row for ' . $key ); }
		return array( 'guard' => 'add_post_metadata' );
	}, array( $meta_add_id ) );

	$meta_delete_id = seed_product( 'WL311-meta-delete-', '50.00', true, 10 );
	run_cell( 'meta_delete', 'delete_post_meta() for every protected price/stock key', 'deny', function () use ( $actor, $meta_delete_id ) {
		wp_set_current_user( $actor );
		$offset = denial_offset();
		$keys = array( '_regular_price', '_sale_price', '_price', '_sale_price_dates_from', '_sale_price_dates_to', '_stock', '_stock_status' );
		$counts = array();
		foreach ( $keys as $key ) { $counts[ $key ] = meta_count( $meta_delete_id, $key ); }
		foreach ( $keys as $key ) { ok( false === delete_post_meta( $meta_delete_id, $key ), 'delete_post_meta denied for ' . $key ); }
		assert_denial( denials_since( $offset ), 'delete_post_metadata', 'meta delete denial recorded at delete_post_metadata' );
		eq( '10', raw_meta( $meta_delete_id, '_stock' ), 'meta delete left the durable stock row untouched' );
		eq( '50.00', raw_meta( $meta_delete_id, '_regular_price' ), 'meta delete left the durable price row untouched' );
		eq( 'instock', raw_meta( $meta_delete_id, '_stock_status' ), 'meta delete left the durable stock status untouched' );
		foreach ( $keys as $key ) { eq( $counts[ $key ], meta_count( $meta_delete_id, $key ), 'meta delete removed no row for ' . $key ); }
		return array( 'guard' => 'delete_post_metadata' );
	}, array( $meta_delete_id ) );

	$trash_id = seed_product( 'WL311-trash-', '50.00' );
	run_cell( 'trash', 'wp_trash_post() on a product', 'deny', function () use ( $actor, $trash_id ) {
		wp_set_current_user( $actor );
		$offset = denial_offset();
		try {
			wp_trash_post( $trash_id );
			throw new RuntimeException( 'expected the trash to be denied' );
		} catch ( \WC_Data_Exception $e ) {
			eq( deny_code(), exc_code( $e ), 'trash denial code' );
		}
		assert_denial( denials_since( $offset ), 'pre_trash_post', 'trash denial recorded at pre_trash_post' );
		eq( 'publish', get_post_status( $trash_id ), 'trash left the product published' );
		return array( 'guard' => 'pre_trash_post' );
	}, array( $trash_id ) );

	$delete_post_id = seed_product( 'WL311-delete-post-', '50.00' );
	run_cell( 'delete_post', 'wp_delete_post() with force=true on a product', 'deny', function () use ( $actor, $delete_post_id ) {
		wp_set_current_user( $actor );
		$offset = denial_offset();
		try {
			wp_delete_post( $delete_post_id, true );
			throw new RuntimeException( 'expected the delete to be denied' );
		} catch ( \WC_Data_Exception $e ) {
			eq( deny_code(), exc_code( $e ), 'delete_post denial code' );
		}
		assert_denial( denials_since( $offset ), 'pre_delete_post', 'delete_post denial recorded at pre_delete_post' );
		eq( 'publish', get_post_status( $delete_post_id ), 'delete_post left the product published' );
		return array( 'guard' => 'pre_delete_post' );
	}, array( $delete_post_id ) );

	$delete_product_id = seed_product( 'WL311-delete-product-', '50.00' );
	run_cell( 'delete_product', 'WC_Product::delete() from PHP', 'deny', function () use ( $actor, $delete_product_id ) {
		wp_set_current_user( $actor );
		$offset = denial_offset();
		try {
			wc_get_product( $delete_product_id )->delete( true );
			throw new RuntimeException( 'expected the WC_Product::delete to be denied' );
		} catch ( \WC_Data_Exception $e ) {
			eq( deny_code(), exc_code( $e ), 'WC_Product::delete denial code' );
		}
		assert_denial( denials_since( $offset ), 'pre_delete_post', 'WC_Product::delete denial recorded at pre_delete_post' );
		eq( 'publish', get_post_status( $delete_product_id ), 'WC_Product::delete left the product published' );
		return array( 'guard' => 'pre_delete_post' );
	}, array( $delete_product_id ) );

	$stock_rest_id = seed_product( 'WL311-stock-rest-', '10.00', true, 10 );
	run_cell( 'stock_rest', 'restricted actor submitting a stock job through the WriteLeash REST route', 'deny', function () use ( $actor, $grant, $stock_rest_id ) {
		$key = 'wl311-stock-rest-' . bin2hex( random_bytes( 4 ) );
		wp_set_current_user( $actor );
		$resp = rest_dispatch( 'POST', '/writeleash/v1/grants/' . $grant['public_id'] . '/jobs', array(
			'idempotency_key' => $key,
			'actor_id' => (int) $grant['actor_id'],
			'grant_version' => (int) $grant['version'],
			'asset' => 'product_stock',
			'stock_field' => 'stock_quantity',
			'operation' => 'SET',
			'target' => '4',
			'product_ids' => array( $stock_rest_id ),
		) );
		eq( 403, resp_status( $resp ), 'restricted submitter refused with 403' );
		eq( 'PERMISSION_DENIED', resp_reason( $resp ), 'restricted submitter refused as PERMISSION_DENIED' );
		eq( 0, job_count_for_keys( array( $key ) ), 'restricted submission created no job row' );
		eq( '10', qty_of( $stock_rest_id ), 'restricted submission left the stock untouched' );
		return array( 'guard' => 'Agent_Jobs_Rest issuer binding' );
	}, array( $stock_rest_id ) );
	marker( 'deny matrix: 15 monitored write paths refused with zero durable change' );

	// -----------------------------------------------------------------------
	// B. Non-interference: the paths that must keep working.
	// -----------------------------------------------------------------------
	$guest_id = seed_product( 'WL311-ni-guest-', '10.00', true, 10 );
	run_cell( 'ni_guest_checkout', 'guest checkout (wc_create_order + wc_maybe_reduce_stock_levels, user 0)', 'allow', function () use ( $guest_id ) {
		wp_set_current_user( 0 );
		$offset = denial_offset();
		$before = (int) qty_of( $guest_id );
		$order = wc_create_order();
		$order->add_product( wc_get_product( $guest_id ), 2 );
		$order->calculate_totals();
		$order->save();
		wc_maybe_reduce_stock_levels( $order->get_id() );
		eq( 0, get_current_user_id(), 'guest checkout ran as user 0' );
		eq( (string) ( $before - 2 ), qty_of( $guest_id ), 'guest checkout reduced stock by 2' );
		assert_no_denials( denials_since( $offset ), 'guest checkout was never intercepted' );
		return array( 'guard' => 'n/a (guest write path unaffected)' );
	}, array( $guest_id ) );

	$cron_id = seed_product( 'WL311-ni-cron-', '10.00', true, 10 );
	run_cell( 'ni_cron', 'user-0 background write in a separate process (Action Scheduler / WP-Cron shape)', 'allow', function () use ( $cron_id ) {
		$res = run_probe( array( 'product_id' => $cron_id, 'qty' => 3 ), 'matrix-cron-worker.php' );
		eq( 0, (int) $res['user_id'], 'background process ran as user 0' );
		eq( true, (bool) $res['applied'], 'background stock write applied' );
		eq( (string) ( (int) $res['before'] - 3 ), (string) $res['after'], 'background write reduced stock by 3' );
		return array( 'guard' => 'n/a (user-0 background path unaffected)' );
	}, array( $cron_id ) );

	$manager_id = seed_product( 'WL311-ni-manager-', '100.00' );
	run_cell( 'ni_manager', 'shop_manager human edit outside any frame', 'allow', function () use ( $manager, $manager_id ) {
		wp_set_current_user( $manager );
		$offset = denial_offset();
		$p = wc_get_product( $manager_id );
		$p->set_regular_price( '90.00' );
		$p->save();
		eq( '90.00', fresh_price( $manager_id ), 'manager edit applied' );
		assert_no_denials( denials_since( $offset ), 'non-restricted manager was never intercepted' );
		return array( 'guard' => 'n/a (non-restricted principal)' );
	}, array( $manager_id ) );

	$op_ids = array( seed_product( 'WL311-ni-op-a-', '100.00' ), seed_product( 'WL311-ni-op-b-', '100.00' ) );
	sort( $op_ids, SORT_NUMERIC );
	run_cell( 'ni_preview_approve_resume_undo', 'operator preview -> approve -> bounded run -> REST resume -> undo (price restored to 100.00)', 'allow', function () use ( $op_ids, $issuer, $approver ) {
		$offset = denial_offset();
		wp_set_current_user( $issuer );
		$plan = Planner::preview( Selection::ids( $op_ids ), new Operation( Operation::SET, '90.00' ), new Policy( 1000, '100', '100', false, '100' ) );
		$created = Repo::create_from_plan( $plan, $issuer );
		eq( JState::PLANNED, (string) $created['status'], 'preview persisted a PLANNED human job' );
		wp_set_current_user( $approver );
		$approved = Repo::approve( (int) $created['id'], $approver );
		eq( JState::READY, (string) $approved['status'], 'approval moved the job to READY' );
		Worker::queue_job( (int) $created['id'] );
		Worker::run( (int) $created['id'], limits( 1 ), true );
		$mid = Repo::read( (int) $created['id'] );
		ok( ! JState::is_terminal( (string) $mid['status'] ), 'bounded run left work for the resume endpoint' );
		$resume = rest_dispatch( 'POST', '/writeleash/v1/jobs/' . $created['public_id'] . '/resume' );
		eq( 200, resp_status( $resume ), 'manual resume returned 200' );
		$done = Repo::read( (int) $created['id'] );
		eq( 'COMPLETED', (string) $done['status'], 'manual resume completed the job' );
		foreach ( $op_ids as $iid ) { price_eq( '90.00', fresh_price( $iid ), 'approve + resume applied the price ' . $iid ); }
		$undo = URepo::initiate( (int) $created['id'], $issuer );
		$undo_id = (int) $undo['id'];
		for ( $i = 0; $i < 6 && ! in_array( (string) ( URepo::read_operation_by_job( (int) $created['id'] )['status'] ?? '' ), array( 'UNDO_COMPLETED', 'UNDO_COMPLETED_WITH_ISSUES' ), true ); ++$i ) {
			UWorker::run( $undo_id, limits( 5 ), true );
		}
		eq( 'UNDO_COMPLETED', (string) ( URepo::read_operation_by_job( (int) $created['id'] )['status'] ?? '' ), 'undo completed' );
		foreach ( $op_ids as $iid ) { price_eq( '100.00', fresh_price( $iid ), 'undo restored the price ' . $iid ); }
		assert_no_denials( denials_since( $offset ), 'operator lifecycle was never intercepted' );
		return array( 'guard' => 'n/a (operator lifecycle unaffected)' );
	}, $op_ids, null, 'any' );
	marker( 'non-interference: guest, user-0 background, shop_manager and the operator lifecycle unaffected' );

	// -----------------------------------------------------------------------
	// C. Inside the Execution Frame: controlled paths still apply.
	// -----------------------------------------------------------------------
	$frame_price_id = seed_product( 'WL311-frame-price-', '100.00' );
	run_cell( 'frame_price_apply', 'grant price job applying inside its Execution Frame', 'apply', function () use ( $grant, $issuer, $actor, $frame_price_id ) {
		$offset = denial_offset();
		$accepted = accept_price( $grant, $issuer, $actor, array( $frame_price_id ), '80.00', 'wl311-frame-price' );
		run_job_inline( (int) $accepted['job']['id'] );
		eq( 'COMPLETED', (string) ( Repo::read( (int) $accepted['job']['id'] )['status'] ?? '' ), 'frame price job completed' );
		eq( '80.00', fresh_price( $frame_price_id ), 'frame price apply wrote the frozen target' );
		assert_no_denials( denials_since( $offset ), 'in-frame price write was never intercepted' );
		return array( 'guard' => 'Execution_Frame open (price)' );
	}, array( $frame_price_id ) );

	$frame_stock_id = seed_product( 'WL311-frame-stock-', '10.00', true, 10 );
	run_cell( 'frame_stock_apply', 'grant stock job applying inside its Execution Frame', 'apply', function () use ( $grant, $issuer, $actor, $frame_stock_id ) {
		$offset = denial_offset();
		$price_before = price_state( $frame_stock_id );
		$accepted = accept_stock( $grant, $issuer, $actor, array( $frame_stock_id ), 'SET', 'stock_quantity', '4', 'wl311-frame-stock' );
		run_job_inline( (int) $accepted['job']['id'] );
		eq( 'COMPLETED', (string) ( Repo::read( (int) $accepted['job']['id'] )['status'] ?? '' ), 'frame stock job completed' );
		eq( '4', qty_of( $frame_stock_id ), 'frame stock apply wrote the frozen target' );
		eq( $price_before, price_state( $frame_stock_id ), 'in-frame stock apply left the durable price untouched' );
		// A stock frame authorizes the stock kind only. Woo's own save still asks
		// to drop empty price rows and the guard refuses those writes; the
		// refusal must not have changed the price, and the stock write itself
		// must never have been intercepted.
		assert_no_stock_denial( denials_since( $offset ), 'in-frame stock write was never intercepted' );
		return array( 'guard' => 'Execution_Frame open (stock); price-meta housekeeping refused by design' );
	}, array( $frame_stock_id ) );

	// The variable-parent rows are the two in-frame positives #311 calls out:
	// the parent's price range must follow the variation, and the parent's
	// stock status must follow the variation. Both writes happen on the parent
	// while the frame only names the variation, so they prove the frame
	// covers the parent too.
	list( $var_price_parent, $var_price_id ) = seed_variation( 'WL311-frame-var-price-', '60.00' );
	run_cell( 'frame_variation_price_sync', 'grant price job on a variation syncing the parent price range inside the frame', 'apply', function () use ( $grant, $issuer, $actor, $var_price_id, $var_price_parent ) {
		$offset = denial_offset();
		$accepted = accept_price( $grant, $issuer, $actor, array( $var_price_id ), '50.00', 'wl311-frame-var-price' );
		run_job_inline( (int) $accepted['job']['id'] );
		eq( 'COMPLETED', (string) ( Repo::read( (int) $accepted['job']['id'] )['status'] ?? '' ), 'frame variation price job completed' );
		price_eq( '50.00', fresh_price( $var_price_id ), 'frame variation price apply wrote the frozen target' );
		price_eq( '50.00', raw_meta( $var_price_parent, '_price' ), 'parent _price followed the variation inside the frame' );
		$lookup = lookup_row( $var_price_parent );
		price_eq( '50.00', (string) ( $lookup['min_price'] ?? '' ), 'parent lookup min_price followed the variation' );
		price_eq( '50.00', (string) ( $lookup['max_price'] ?? '' ), 'parent lookup max_price followed the variation' );
		assert_no_denials( denials_since( $offset ), 'in-frame variation price write was never intercepted' );
		return array( 'guard' => 'Execution_Frame open (price, parent covered)' );
	}, array( $var_price_id, $var_price_parent ) );

	list( $var_stock_parent, $var_stock_id ) = seed_stock_variation( 'WL311-frame-var-stock-', 10 );
	run_cell( 'frame_variation_stock_sync', 'grant stock job on a variation syncing the parent stock status inside the frame', 'apply', function () use ( $grant, $issuer, $actor, $var_stock_id, $var_stock_parent ) {
		$offset = denial_offset();
		$price_before = price_state( $var_stock_id ) . '|' . price_state( $var_stock_parent );
		$accepted = accept_stock( $grant, $issuer, $actor, array( $var_stock_id ), 'SET', 'stock_quantity', '0', 'wl311-frame-var-stock' );
		run_job_inline( (int) $accepted['job']['id'] );
		eq( 'COMPLETED', (string) ( Repo::read( (int) $accepted['job']['id'] )['status'] ?? '' ), 'frame variation stock job completed' );
		eq( '0', qty_of( $var_stock_id ), 'frame variation stock apply wrote the frozen target' );
		eq( 'outofstock', status_of( $var_stock_id ), 'Woo derived the variation status from the quantity' );
		eq( 'outofstock', status_of( $var_stock_parent ), 'parent stock status followed the variation inside the frame' );
		eq( 'outofstock', (string) ( lookup_row( $var_stock_parent )['stock_status'] ?? '' ), 'parent lookup stock_status followed the variation' );
		eq( $price_before, price_state( $var_stock_id ) . '|' . price_state( $var_stock_parent ), 'in-frame variation stock apply left the durable price untouched' );
		assert_no_stock_denial( denials_since( $offset ), 'in-frame variation stock write was never intercepted' );
		return array( 'guard' => 'Execution_Frame open (stock, parent covered); price-meta housekeeping refused by design' );
	}, array( $var_stock_id, $var_stock_parent ) );
	marker( 'in-frame: price and stock apply still work, including the variable parent rows' );

	// -----------------------------------------------------------------------
	// D. Grant state: every non-acceptance state denies, removal unleashes.
	// -----------------------------------------------------------------------
	$disabled_id = seed_product( 'WL311-grant-disabled-', '100.00' );
	run_cell( 'grant_disabled', 'restricted actor while the global grant kill-switch is disabled', 'deny', function () use ( $grant, $issuer, $actor, $disabled_id ) {
		wp_set_current_user( $issuer );
		Grants::disable( $issuer );
		eq( true, Grants::is_disabled(), 'kill-switch is disabled' );
		try {
			wp_set_current_user( $actor );
			$plan = Planner::preview( Selection::ids( array( $disabled_id ) ), new Operation( Operation::SET, '70.00' ), new Policy( 1000, '100', '100', false, '100' ) );
			wp_set_current_user( $issuer );
			expect_grant_error( 'GRANT_DISABLED', static function () use ( $plan, $grant, $issuer ) { Grants::accept_plan( $plan, (string) $grant['public_id'], $issuer, 'wl311-disabled' ); }, 'disabled kill-switch denies acceptance' );
		} finally {
			wp_set_current_user( $issuer );
			Grants::enable( $issuer );
		}
		eq( false, Grants::is_disabled(), 'operator re-enabled grants' );
		ok( Grants::is_restricted_actor( $actor ), 'actor is still restricted after re-enable' );
		return array( 'guard' => 'GRANT_DISABLED' );
	}, array( $disabled_id ) );

	$revoked_id = seed_product( 'WL311-grant-revoked-', '100.00' );
	run_cell( 'grant_revoked', 'restricted actor whose grant was revoked', 'deny', function () use ( $issuer, $actor, $revoked_id ) {
		wp_set_current_user( $issuer );
		$grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
		Grants::revoke( (string) $grant['public_id'], $issuer );
		eq( true, Grants::is_restricted_actor( $actor ), 'revoked grant keeps the principal restricted' );
		wp_set_current_user( $actor );
		$plan = Planner::preview( Selection::ids( array( $revoked_id ) ), new Operation( Operation::SET, '70.00' ), new Policy( 1000, '100', '100', false, '100' ) );
		wp_set_current_user( $issuer );
		expect_grant_error( 'GRANT_REVOKED', static function () use ( $plan, $grant, $issuer ) { Grants::accept_plan( $plan, (string) $grant['public_id'], $issuer, 'wl311-revoked' ); }, 'revoked grant denies acceptance' );
		return array( 'guard' => 'GRANT_REVOKED' );
	}, array( $revoked_id ) );

	$expired_id = seed_product( 'WL311-grant-expired-', '100.00' );
	run_cell( 'grant_expired', 'restricted actor whose grant expired', 'deny', function () use ( $wpdb, $issuer, $actor, $expired_id ) {
		wp_set_current_user( $issuer );
		$grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE public_id=%s', GSchema::grants_table( $wpdb ), $grant['public_id'] ) );
		wp_set_current_user( $actor );
		$plan = Planner::preview( Selection::ids( array( $expired_id ) ), new Operation( Operation::SET, '70.00' ), new Policy( 1000, '100', '100', false, '100' ) );
		wp_set_current_user( $issuer );
		expect_grant_error( 'GRANT_EXPIRED', static function () use ( $plan, $grant, $issuer ) { Grants::accept_plan( $plan, (string) $grant['public_id'], $issuer, 'wl311-expired' ); }, 'expired grant denies acceptance' );
		return array( 'guard' => 'GRANT_EXPIRED' );
	}, array( $expired_id ) );

	$exhausted_id = seed_product( 'WL311-grant-exhausted-', '100.00' );
	run_cell( 'grant_exhausted', 'restricted actor whose grant budget is spent', 'deny', function () use ( $wpdb, $issuer, $actor, $exhausted_id ) {
		wp_set_current_user( $issuer );
		$grant = Grants::create_grant( $issuer, $actor, 1, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET budget_consumed=budget_max, budget_reserved=0 WHERE public_id=%s', GSchema::grants_table( $wpdb ), $grant['public_id'] ) );
		wp_set_current_user( $actor );
		$plan = Planner::preview( Selection::ids( array( $exhausted_id ) ), new Operation( Operation::SET, '70.00' ), new Policy( 1000, '100', '100', false, '100' ) );
		wp_set_current_user( $issuer );
		expect_grant_error( 'GRANT_BUDGET_EXHAUSTED', static function () use ( $plan, $grant, $issuer ) { Grants::accept_plan( $plan, (string) $grant['public_id'], $issuer, 'wl311-exhausted' ); }, 'exhausted budget denies acceptance' );
		return array( 'guard' => 'GRANT_BUDGET_EXHAUSTED' );
	}, array( $exhausted_id ) );

	$removed_id = seed_product( 'WL311-grant-removed-', '100.00' );
	run_cell( 'grant_removed', 'restriction removal by another principal unleashes the actor', 'unleash', function () use ( $issuer, $actor2, $removed_id ) {
		wp_set_current_user( $issuer );
		Grants::create_grant( $issuer, $actor2, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
		ok( Grants::is_restricted_actor( $actor2 ), 'the second actor is restricted by its own grant' );
		wp_set_current_user( $actor2 );
		$p = wc_get_product( $removed_id );
		$p->set_regular_price( '66.00' );
		try {
			$p->save();
			throw new RuntimeException( 'restricted actor wrote before removal' );
		} catch ( \WC_Data_Exception $e ) {
			eq( deny_code(), exc_code( $e ), 'before removal the actor is denied' );
		}
		eq( '100.00', raw_meta( $removed_id, '_regular_price' ), 'before removal nothing persisted' );
		wp_set_current_user( $issuer );
		$removed = Grants::remove_restriction( $actor2, $issuer );
		eq( $actor2, (int) $removed['actor_id'], 'removal targeted the actor' );
		eq( false, Grants::is_restricted_actor( $actor2 ), 'removal cleared the restricted predicate' );
		wp_set_current_user( $actor2 );
		$p2 = wc_get_product( $removed_id );
		$p2->set_regular_price( '66.00' );
		$p2->save();
		eq( '66.00', fresh_price( $removed_id ), 'after removal the same write applies' );
		return array( 'guard' => 'GRANT_RESTRICTION_REMOVED' );
	}, array( $removed_id ) );
	marker( 'grant state: disabled, revoked, expired and exhausted deny; removal unleashes' );
} catch ( \Throwable $e ) {
	$GLOBALS['wl311_fatal'] = get_class( $e ) . ': ' . $e->getMessage();
}

emit_matrix_doc();
