<?php
// #204 focused real WordPress/WooCommerce fixture. Run on both existing DB/cache profiles.
use WriteLeash\Free_Admin;
use WriteLeash\Job_Repository;
use WriteLeash\Job_Schema;
use WriteLeash\Job_Worker;
use WriteLeash\Undo_Repository;
use WriteLeash\Undo_Worker;

function wl204_assert( bool $truth, string $label ): void { if ( ! $truth ) { throw new RuntimeException( '#204 ' . $label ); } }
function wl204_post( array $job ): array {
	return array( 'job' => $job['public_id'], '_wpnonce' => wp_create_nonce( Free_Admin::ACTION_PROGRESS . '_' . $job['public_id'] ), 'offset' => '0', 'filter' => '' );
}
function wl204_read( array $post ): array {
	$queries = array(); $reads = array();
	$trace = static function ( $sql ) use ( &$queries ) { $queries[] = $sql; return $sql; };
	$product = static function ( $id ) use ( &$reads ) { $reads[] = $id; };
	add_filter( 'query', $trace ); add_action( 'woocommerce_product_read', $product );
	try { $result = Free_Admin::progress_snapshot( $post, 'POST' ); }
	finally { remove_filter( 'query', $trace ); remove_action( 'woocommerce_product_read', $product ); }
	foreach ( $queries as $sql ) { wl204_assert( ! preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|GRANT|REVOKE)\b/i', $sql ), 'poll executes no DML/DDL/DCL: ' . $sql ); }
	wl204_assert( array() === $reads, 'poll reads no live Woo products' );
	return $result;
}
wp_set_current_user( 1 );
$actor = wp_insert_user( array( 'user_login' => 'wl204-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$other = wp_insert_user( array( 'user_login' => 'wl204-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
wp_set_current_user( $actor );
$ids = array();
foreach ( array( '10.00', '11.00', '12.00' ) as $price ) {
	$product = new WC_Product_Simple(); $product->set_name( 'WL204 ' . $price ); $product->set_status( 'publish' ); $product->set_regular_price( $price ); $product->save(); $ids[] = $product->get_id();
}
$preview = Free_Admin::process_preview( array( '_wpnonce' => wp_create_nonce( Free_Admin::ACTION_PREVIEW ), 'selector' => 'ids', 'ids' => implode( ',', $ids ), 'operation' => 'SET', 'price_field' => 'regular_price', 'amount' => '15.00', 'max_products' => '100', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '100' ), 'POST' );
wl204_assert( 'OK' === $preview['status'], 'real preview accepted' );
$job = Job_Repository::read_by_public_id( $preview['public_id'] );
$post = wl204_post( $job );
wl204_assert( 'OK' === wl204_read( $post )['status'], 'owner can read saved preview without mutation' );
wl204_assert( 'FORBIDDEN' === Free_Admin::progress_snapshot( $post, 'GET' )['status'], 'GET refused' );
foreach ( array( array( '_wpnonce' => 'bad' ), array( 'offset' => '100001' ), array( 'offset' => '-1' ), array( 'filter' => 'anything' ) ) as $bad ) { wl204_assert( 'FORBIDDEN' === wl204_read( array_merge( $post, $bad ) )['status'], 'bad nonce or unbounded query refused' ); }
wp_set_current_user( $other );
wl204_assert( 'FORBIDDEN' === wl204_read( wl204_post( $job ) )['status'], 'cross-actor refused with valid own nonce' );
$other_preview = Free_Admin::process_preview( array( '_wpnonce' => wp_create_nonce( Free_Admin::ACTION_PREVIEW ), 'selector' => 'ids', 'ids' => implode( ',', $ids ), 'operation' => 'SET', 'price_field' => 'regular_price', 'amount' => '16.00', 'max_products' => '100', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '100' ), 'POST' );
wl204_assert( 'OK' === $other_preview['status'], 'second actor preview accepted' );
$other_job = Job_Repository::read_by_public_id( $other_preview['public_id'] );
wp_set_current_user( $actor );
// A guessed public ID presented with its genuinely valid job-bound nonce still
// reads through the audited poll path only: no DML/DDL/DCL and no product reads.
wl204_assert( 'FORBIDDEN' === wl204_read( wl204_post( $other_job ) )['status'], 'guessed public ID with a valid job-bound nonce refused' );
wp_set_current_user( 0 ); wl204_assert( 'FORBIDDEN' === wl204_read( $post )['status'], 'anonymous refused' );
wp_set_current_user( $actor );
$user = wp_get_current_user(); $user->add_cap( 'manage_woocommerce', false );
wl204_assert( 'FORBIDDEN' === wl204_read( $post )['status'], 'revoked capability refused' ); $user->remove_cap( 'manage_woocommerce' );
wl204_assert( 'OK' === Free_Admin::process_approve( array( 'job' => $job['public_id'], '_wpnonce' => wp_create_nonce( Free_Admin::ACTION_APPROVE . '_' . $job['plan_id'] ) ), 'POST' )['status'], 'exact approval accepted' );
$before = wl204_read( wl204_post( $job ) );
Job_Worker::run( (int) $job['id'], array( 'max_items' => 1, 'budget_seconds' => 20 ), true );
$partial = wl204_read( wl204_post( $job ) );
wl204_assert( $partial['counts']['applied'] > $before['counts']['applied'] && $partial['counts']['pending'] > 0, 'Apply advances from real saved worker outcomes' );
wl204_assert( $partial['counts'] === Job_Repository::counts( (int) $job['id'] ), 'polled Apply counters equal durable rows' );
// A newer supported edit is never described as success.
$product = wc_get_product( $ids[2] ); $product->set_regular_price( '99.00' ); $product->save();
for ( $n = 0; $n < 5; ++$n ) { Job_Worker::run( (int) $job['id'], array( 'max_items' => 3, 'budget_seconds' => 20 ), true ); }
$terminal = wl204_read( wl204_post( $job ) );
wl204_assert( 1 === $terminal['counts']['conflict'] && ! $terminal['poll'] && ! $terminal['resume_available'], 'partial terminal conflict remains distinct and stops polling' );
ob_start(); Free_Admin::render_view( 'job', $job['public_id'], 0 ); $html = ob_get_clean();
wl204_assert( str_contains( $html, 'Refresh saved progress' ) && str_contains( $html, 'Automatic updates require JavaScript' ), 'manual/no-JS refresh visible' );
wl204_assert( ! str_contains( $html, 'value="' . Free_Admin::ACTION_RESUME . '"' ), 'terminal no-JS view has no Resume form' );
$started = Undo_Repository::initiate( (int) $job['id'], $actor );
$operation = Undo_Repository::read_operation_by_job( (int) $job['id'] );
$undo_before = wl204_read( wl204_post( $job ) );
Undo_Worker::run( (int) $operation['id'], array( 'max_items' => 1, 'budget_seconds' => 20 ), true );
$undo_partial = wl204_read( wl204_post( $job ) );
wl204_assert( $undo_partial['undo_counts']['undone'] > $undo_before['undo_counts']['undone'], 'Undo advances from real saved outcomes' );
wl204_assert( $undo_partial['poll'], 'unfinished Undo continues polling after terminal Apply' );
for ( $n = 0; $n < 5; ++$n ) { Undo_Worker::run( (int) $operation['id'], array( 'max_items' => 3, 'budget_seconds' => 20 ), true ); }
$undo_terminal = wl204_read( wl204_post( $job ) ); wl204_assert( ! $undo_terminal['poll'] && ! $undo_terminal['undo_available'], 'terminal Undo stops polling and hides action' );
// Missing item evidence must fail visibly without certifying successful completion.
global $wpdb;
$table = Job_Schema::items_table( $wpdb );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE job_id=%d AND product_id=%d', $table, (int) $job['id'], $ids[0] ) );
$missing = wl204_read( wl204_post( $job ) );
wl204_assert( 'OK' === $missing['status'] && str_contains( $missing['label'], 'unavailable' ) && ! $missing['poll'] && ! $missing['resume_available'] && ! $missing['undo_available'], 'missing saved evidence never certifies success or actions' );
wp_set_current_user( 1 );
echo '#204 real Woo persisted Apply/Undo, actor/capability/session/nonce bounds, read-only SQL/product-read audit, conflict/terminal, no-JS and missing evidence PASS (' . ( getenv( 'WL111_CACHE' ) ?: 'default' ) . ")\n";
