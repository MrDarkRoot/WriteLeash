<?php
// Dedicated WP_DEBUG=true slice of the existing provisioned, certified fixture.
require_once WP_PLUGIN_DIR . '/writeleash/writeleash.php';
require_once __DIR__ . '/helpers.php';

$host = getenv( 'CC_ENGINE_HOST' );
cc87_assert( defined( 'WP_DEBUG' ) && WP_DEBUG, '#62 debug enabled in product request' );
$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
wp_set_current_user( 1 );
$operation = \WriteLeash\Certified_Operation::redirection_5_5_2_bulk_disable();
cc87_assert( \WriteLeash\Operation_Config::read( $operation )['enabled'], 'debug fixture enabled' );
cc87_seed_bulk( $root, 6 );
$denied = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
$observer = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
cc87_assert( 409 === $denied->get_status() && array( 6, 0 ) === cc87_counts( $observer ), 'debug REST logical denial independently unchanged' );
$post = array(
	'writeleash_task' => 'budget',
	'_wpnonce'       => wp_create_nonce( 'writeleash_budget' ),
	'logical_budget' => '10',
);
cc87_assert( 'OK' === \WriteLeash\Admin_Page::process( $post )['status'], 'debug Admin handler budget update' );
$safe = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
$fresh = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
cc87_assert( 200 === $safe->get_status() && array( 6, 6 ) === cc87_counts( $fresh ), 'debug REST safe operation independently durable' );
ob_start();
\WriteLeash\Admin_Page::render();
$html = ob_get_clean();
cc87_assert( false !== strpos( $html, 'COMMITTED' ) && false === strpos( $html, 'disposable_root_password' ), 'debug Admin rendered bounded outcome' );
cc87_assert( 'READY' === \WriteLeash\Product_Status::snapshot()['operation_status'], 'debug Doctor/readiness stays live' );
echo "#62 $host WP_DEBUG: real REST DENIED/COMMITTED, fresh observer, Admin handler/render and live READY PASS\n";
