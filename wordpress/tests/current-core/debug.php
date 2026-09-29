<?php
// #63 current-core WP_DEBUG product slice on the current WordPress stable.
require_once WP_PLUGIN_DIR . '/commitcap/commitcap.php';
require_once __DIR__ . '/../adapter/helpers.php';

use CommitCap\Admin_Page;
use CommitCap\Certified_Operation as Operation;
use CommitCap\Operation_Config as Config;

$host = getenv( 'CC_ENGINE_HOST' );
cc87_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), '#63 pinned host' );
cc87_assert( defined( 'WP_DEBUG' ) && WP_DEBUG, '#63 debug slice requires WP_DEBUG' );

$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
wp_set_current_user( 1 );
$operation = Operation::redirection_5_5_2_bulk_disable();
$state = Config::read( $operation );
cc87_assert( true === $state['enabled'] && 5 === $state['logical_budget'], '#63 debug fixture state' );

cc87_seed_bulk( $root, 6 );
$denied = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
$fresh = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$fresh->suppress_errors( true );
cc87_assert( 409 === $denied->get_status() && array( 6, 0 ) === cc87_counts( $fresh ), '#63 debug logical denial independently unchanged' );

$post = array(
	'commitcap_task' => 'budget',
	'_wpnonce'       => wp_create_nonce( 'commitcap_budget' ),
	'logical_budget' => '10',
);
cc87_assert( 'OK' === Admin_Page::process( $post )['status'], '#63 debug Admin handler budget update' );

$safe = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
$fresh = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$fresh->suppress_errors( true );
cc87_assert( 200 === $safe->get_status() && array( 6, 6 ) === cc87_counts( $fresh ), '#63 debug safe operation independently durable' );

ob_start();
Admin_Page::render();
$html = ob_get_clean();
cc87_assert( false !== strpos( $html, 'COMMITTED' ) && false === strpos( $html, 'cc63_runtime_secret' ) && false === strpos( $html, 'disposable_root_password' ), '#63 debug Admin rendered bounded, secret-free outcome' );
echo "#63 current-core $host WP_DEBUG: real REST DENIED/COMMITTED, fresh observers, Admin handler/render, no secret PASS\n";
