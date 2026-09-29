<?php
// #63 current-stable product gate. Focused on the certified real operation on
// the current WordPress stable core fixture; the full adversarial matrix
// remains pinned to the WordPress 6.8.3 baseline (#62) and is not duplicated.
require_once WP_PLUGIN_DIR . '/commitcap/commitcap.php';
require_once __DIR__ . '/../adapter/helpers.php';

use CommitCap\Admin_Page;
use CommitCap\Certified_Operation as Operation;
use CommitCap\Certified_Operation_Status as Status;
use CommitCap\Operation_Config as Config;
use CommitCap\Product_Status;
use CommitCap\Provisioning_Plan as Plan;
use CommitCap\Redirection_Bulk_Disable as Adapter;

define( 'CC63_RUNTIME_USER', 'cc63_writer' );
$host = getenv( 'CC_ENGINE_HOST' );
cc87_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), '#63 pinned host' );

$wp_version = \CommitCap\Environment::wordpress_version();
cc87_assert( '7.1.2' === $wp_version, '#63 expected the current-stable WordPress 7.1.2 fixture, saw ' . $wp_version );
cc87_assert( '5.5.2' === Adapter::detected_version(), '#63 expected Redirection 5.5.2' );
echo "#63 current-core $host: WordPress $wp_version + Redirection 5.5.2 fixture PASS\n";

$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
$normal = $GLOBALS['wpdb'];
$normal->suppress_errors( true );
$normal_id = (int) $normal->get_var( 'SELECT CONNECTION_ID()' );
$runtime = new wpdb( CC63_RUNTIME_USER, 'cc63_runtime_secret', 'wp_test', $host );
$runtime->suppress_errors( true );
$runtime->set_prefix( (string) $normal->prefix );
$runtime_id = (int) $runtime->get_var( 'SELECT CONNECTION_ID()' );
cc87_assert( $runtime_id > 0 && $runtime_id !== $normal_id, '#63 distinct restricted runtime identity' );

$operation = Operation::redirection_5_5_2_bulk_disable();

// The documented operator script provisioned this account in CI; verify the
// canonical helper, five routines, exact grants and P=2000 trigger.
$provisioned = Plan::verify( array( 'wp_redirection_items' => 2000 ), $runtime );
cc87_assert( 'PASS' === $provisioned['overall'], '#63 operator-provisioned runtime not READY: ' . json_encode( $provisioned ) );
echo "#63 current-core $host: operator-script provisioning verified (helper, 5 routines, exact grants, P=2000 trigger): PASS\n";

// Doctor/readiness and the product's Admin-visible status.
Config::reset( $operation );
Config::set_logical_budget( $operation, 10 );
Config::set_enabled( $operation, true );
$status = Status::check( $operation, '5.5.2', $runtime );
cc87_assert( 'READY' === $status['status'], '#63 Doctor/status not READY: ' . json_encode( array( 'status' => $status['status'], 'reason' => $status['reason'] ) ) );
cc87_assert( 'PASS' === ( $status['doctor']['overall'] ?? null ), '#63 Doctor overall not PASS' );
$snapshot = Product_Status::snapshot();
cc87_assert( 'READY' === $snapshot['operation_status'] && 'PASS' === $snapshot['doctor_state'], '#63 product snapshot not READY/PASS' );
wp_set_current_user( 1 );
ob_start();
Admin_Page::render();
$admin = ob_get_clean();
cc87_assert( false !== strpos( $admin, 'READY' ) && false !== strpos( $admin, 'Redirection detected: 5.5.2' ), '#63 Admin page did not show the READY certified operation' );
cc87_assert( false === strpos( $admin, 'cc63_runtime_secret' ) && false === strpos( $admin, 'disposable_root_password' ), '#63 Admin HTML leaked credentials' );
echo "#63 current-core $host: Doctor READY, product/Admin status READY, restricted runtime available: PASS\n";

// Safe real REST request on the current-stable fixture: 6 rows, L=10.
Config::set_logical_budget( $operation, 10 );
cc87_seed_bulk( $root, 6 );
list( $response, $threads ) = cc87_trace_all( $root, static function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 200 === $response->get_status(), '#63 safe status: ' . $response->get_status() . ' ' . json_encode( $body ) );
cc87_assert( 'COMMITTED' === ( $body['commitcap']['outcome'] ?? null ), '#63 safe outcome: ' . json_encode( $body ) );
cc87_assert( 6 === ( $body['commitcap']['consumed'] ?? null ), '#63 safe consumed' );
list( $safe_updates, $safe_threads ) = cc87_disable_updates( $threads );
cc87_assert( 1 === $safe_updates && 1 === count( $safe_threads ) && ! in_array( $normal_id, $safe_threads, true ), '#63 safe target UPDATE not restricted-only' );
$safe_user = (string) $root->get_var( $root->prepare( "SELECT user_host FROM mysql.general_log WHERE thread_id = %d AND command_type = 'Connect'", $safe_threads[0] ) );
cc87_assert( false !== strpos( $safe_user, CC63_RUNTIME_USER ), '#63 safe UPDATE ran as unexpected identity: ' . $safe_user );
$fresh = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$fresh->suppress_errors( true );
cc87_assert( array( 6, 6 ) === cc87_counts( $fresh ), '#63 safe fresh observer' );
echo "#63 current-core $host safe: HTTP 200 COMMITTED consumed=6 fresh-observer=6-disabled normal-UPDATE=0 restricted-UPDATE=1 PASS\n";

// Logical denial through the real REST route: 6 rows, L=5.
Config::set_logical_budget( $operation, 5 );
cc87_seed_bulk( $root, 6 );
list( $response, $threads ) = cc87_trace_all( $root, static function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 409 === $response->get_status() && 'commitcap_budget_denied' === ( $body['code'] ?? null ), '#63 denial response: ' . json_encode( $body ) );
cc87_assert( 'logical' === ( $body['data']['denial_kind'] ?? null ), '#63 denial kind: ' . json_encode( $body ) );
list( $denied_updates, $denied_threads ) = cc87_disable_updates( $threads );
cc87_assert( 1 === $denied_updates && ! in_array( $normal_id, $denied_threads, true ), '#63 denied target UPDATE not restricted-only' );
$fresh = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$fresh->suppress_errors( true );
cc87_assert( array( 6, 0 ) === cc87_counts( $fresh ), '#63 denied fresh observer' );
echo "#63 current-core $host over-L: HTTP 409 logical DENIED consumed=6 fresh-observer=6-enabled normal-UPDATE=0 restricted-UPDATE=1 PASS\n";

// Leave L=5 enabled for the WP_DEBUG product slice.
$state = Config::read( $operation );
cc87_assert( 5 === $state['logical_budget'] && true === $state['enabled'], '#63 fixture state before debug slice' );
