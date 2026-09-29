<?php
// #60 real Admin controller, REST evidence and demo surface on both engines.
require_once WP_PLUGIN_DIR . '/commitcap-for-wordpress/commitcap-for-wordpress.php';
require_once __DIR__ . '/helpers.php';
// WP-CLI warns on intentional admin-post redirects; keep the production
// callback unchanged and disable only the CLI fixture's redirect handler.
remove_filter( 'wp_redirect', 'WP_CLI\\Utils\\wp_redirect_handler' );
add_filter( 'wp_redirect', static function () { return ''; }, 1 );

use CommitCap\Admin_Page as Admin;
use CommitCap\Certified_Operation as Operation;
use CommitCap\Certified_Operation_Status as Status;
use CommitCap\Disposable_Demo as Demo;
use CommitCap\Disposable_Demo_Setup as Setup;
use CommitCap\Last_Outcome;
use CommitCap\Operation_Config as Config;
use CommitCap\Product_Status;
use CommitCap\Provisioning_Plan as Plan;
use CommitCap\Update_Engine as Engine;

$host = getenv( 'CC_ENGINE_HOST' );
cc87_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), '#60 pinned engine host' );
$normal = $GLOBALS['wpdb'];
$normal->suppress_errors( true );
$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
$runtime = new wpdb( 'cc87_writer', 'cc87_secret', 'wp_test', $host );
$runtime->suppress_errors( true );
$runtime->set_prefix( (string) $normal->prefix );
$runtime_id = (int) $runtime->get_var( 'SELECT CONNECTION_ID()' );
$normal_id = (int) $normal->get_var( 'SELECT CONNECTION_ID()' );
$operation = Operation::redirection_5_5_2_bulk_disable();
$table = Demo::table( (string) $normal->prefix );
$setup = new Setup( $root, 'cc87_writer', '%' );
$setup->setup();
$setup->reset(); // ONE initial seed for the three Admin demo button presses.
Config::reset( $operation );
delete_option( Last_Outcome::OPTION );
wp_set_current_user( 1 );
cc87_assert( current_user_can( 'manage_options' ), 'admin test user capability' );
cc87_assert( 0 < has_action( 'admin_post_commitcap_action', array( Admin::class, 'handle_post' ) ), 'direct action hook registered' );

function cc60_post( $task, $extra = array(), $nonce = null ) {
	return array_merge( array(
		'action' => 'commitcap_action',
		'commitcap_task' => $task,
		'_wpnonce' => null === $nonce ? wp_create_nonce( 'commitcap_' . $task ) : $nonce,
	), $extra );
}

/** Exercise the actual admin-post callback, not just a config service. */
function cc60_admin( $task, $extra = array() ) {
	$previous_post = $_POST;
	$previous_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
	$_POST = cc60_post( $task, $extra );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	try {
		return Admin::handle_post();
	} finally {
		$_POST = $previous_post;
		if ( null === $previous_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $previous_method;
		}
	}
}

function cc60_observe( $host ) {
	$observer = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
	$observer->suppress_errors( true );
	return cc87_counts( $observer ); // Fresh connection after the REST Guard path.
}

// No installer secrets on the page, notices, service snapshot or CLI snapshot.
if ( ! function_exists( 'add_management_page' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
do_action( 'admin_menu' );
global $submenu;
$tools_items = isset( $submenu['tools.php'] ) ? $submenu['tools.php'] : array();
cc87_assert( in_array( 'commitcap', array_column( $tools_items, 2 ), true ), 'Tools → CommitCap registered' );
$view = Product_Status::snapshot();
cc87_assert( 'DISABLED' === $view['operation_status'] && 'logical_budget_missing' === $view['preflight_reason'], 'operation and preflight separate before budget' );
cc87_assert( 'READY' === $view['demo_status'] && 'A' === $view['demo_state'], 'demo seeded and READY' );
cc87_assert( ( 'mysql' === $host ? 'MySQL' : 'MariaDB' ) === $view['database_family'] &&
	( 'mysql' === $host ? '8.0.44' : '10.11.15-MariaDB-ubu2204' ) === $view['database_version'], 'exact pinned family/version displayed' );
$config_before_render = get_option( Config::STATE_OPTION, null );
$rows_before_render = $root->get_results( "SELECT id, value FROM `$table` ORDER BY id", ARRAY_A );
ob_start();
Admin::render();
$html = ob_get_clean();
cc87_assert( $config_before_render === get_option( Config::STATE_OPTION, null ) &&
	$rows_before_render === $root->get_results( "SELECT id, value FROM `$table` ORDER BY id", ARRAY_A ), 'page GET/render did not change config or owned data' );
foreach ( array( 'Setup / readiness', 'Certified production operation', 'Recent local production outcome', 'Disposable demo', 'Redirection 5.5.2', 'Runtime/Doctor evidence', 'operation_disabled' ) as $copy ) {
	cc87_assert( false !== strpos( $html, $copy ), 'admin page missing ' . $copy );
}
foreach ( array( 'cc87_secret', 'disposable_root_password', 'CREATE USER', 'GRANT SELECT' ) as $secret ) {
	cc87_assert( false === strpos( $html, $secret ), 'admin HTML leaked secret/privileged setup' );
}
echo "#60 $host: Tools page, separate operation/Doctor status, setup guidance, secret-free HTML: PASS\n";

// Capability, nonce, method and malformed input all refuse before any write.
$subscriber_id = wp_create_user( 'cc60_subscriber', 'cc60_disposable_password', 'cc60@example.test' );
cc87_assert( is_int( $subscriber_id ) && $subscriber_id > 0, 'subscriber fixture created' );
( new WP_User( $subscriber_id ) )->set_role( 'subscriber' );
$config_before = get_option( Config::STATE_OPTION, null );
$demo_before = $root->get_results( "SELECT id, value FROM `$table` ORDER BY id", ARRAY_A );
wp_set_current_user( $subscriber_id );
foreach ( array( 'budget' => array( 'logical_budget' => '5' ), 'enable' => array(), 'disable' => array(), 'demo' => array() ) as $action => $extra ) {
	cc87_assert( 'FORBIDDEN' === Admin::process( cc60_post( $action, $extra ), 'POST', $runtime )['status'], "subscriber $action rejected" );
}
cc87_assert( 'FORBIDDEN' === Admin::handle_post()['status'], 'direct endpoint refuses subscriber' );
wp_set_current_user( 1 );
foreach ( array( 'budget' => array( 'logical_budget' => '5' ), 'enable' => array(), 'disable' => array(), 'demo' => array() ) as $action => $extra ) {
	$post = cc60_post( $action, $extra );
	unset( $post['_wpnonce'] );
	cc87_assert( 'invalid_nonce' === Admin::process( $post, 'POST', $runtime )['reason'], "missing nonce $action" );
	cc87_assert( 'invalid_nonce' === Admin::process( cc60_post( $action, $extra, 'not-a-nonce' ), 'POST', $runtime )['reason'], "invalid nonce $action" );
	cc87_assert( 'invalid_nonce' === Admin::process( cc60_post( $action, $extra, wp_create_nonce( 'commitcap_other' ) ), 'POST', $runtime )['reason'], "wrong-action nonce $action" );
	cc87_assert( 'post_required' === Admin::process( cc60_post( $action, $extra ), 'GET', $runtime )['reason'], "GET $action" );
}
$original_post = $_POST;
$original_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
$_POST = cc60_post( 'demo' );
$_SERVER['REQUEST_METHOD'] = 'GET';
cc87_assert( 'post_required' === Admin::handle_post()['reason'], 'direct GET admin-post denied' );
$_POST = $original_post;
if ( null === $original_method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $original_method; }
cc87_assert( $config_before === get_option( Config::STATE_OPTION, null ), 'rejected actions left config unchanged' );
cc87_assert( $demo_before === $root->get_results( "SELECT id, value FROM `$table` ORDER BY id", ARRAY_A ), 'rejected actions left demo unchanged' );
cc87_assert( false === get_transient( 'commitcap_notice_1' ), 'rejected direct request did not create an informational option' );
$_POST = cc60_post( 'demo', array(), 'invalid-nonce' );
$_SERVER['REQUEST_METHOD'] = 'POST';
cc87_assert( 'invalid_nonce' === Admin::handle_post()['reason'] && false === get_transient( 'commitcap_notice_1' ), 'direct invalid-nonce handler writes neither config nor notice' );
$_POST = $original_post;
if ( null === $original_method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $original_method; }
list( , $rejected_queries ) = cc87_trace_all( $root, static function () use ( $subscriber_id, $runtime ) {
	wp_set_current_user( $subscriber_id );
	Admin::process( cc60_post( 'demo' ), 'POST', $runtime );
	wp_set_current_user( 1 );
	Admin::process( cc60_post( 'demo', array(), 'invalid-nonce' ), 'POST', $runtime );
	return true;
} );
foreach ( $rejected_queries as $queries ) {
	foreach ( $queries as $sql ) {
		cc87_assert( ! preg_match( '/^UPDATE `?' . preg_quote( $table, '/' ) . '`?|^CALL commitcap_v01_/i', $sql ), 'rejected Admin action reached demo callback/Doctor' );
	}
}
echo "#60 $host: subscriber, direct endpoint, GET, missing/invalid/wrong-action nonces -> zero config/demo mutation: PASS\n";

cc87_assert( 'logical_budget_missing' === Admin::process( cc60_post( 'enable' ), 'POST', $runtime )['reason'] &&
	false === Config::read( $operation )['enabled'], 'enable without a budget refused before config mutation' );
$budget_ok = cc60_admin( 'budget', array( 'logical_budget' => '5' ) );
cc87_assert( 'OK' === $budget_ok['status'] && 5 === $budget_ok['logical_budget'], 'Admin saves canonical L=5' );
foreach ( array( '-1', '2001', '01', '+5', ' 5', '5 ', '1.5', '5x', null, array( 5 ), new stdClass() ) as $invalid ) {
	$before = get_option( Config::STATE_OPTION );
	$rejected_budget = Admin::process( cc60_post( 'budget', array( 'logical_budget' => $invalid ) ), 'POST', $runtime );
	cc87_assert( 'INVALID' === $rejected_budget['status'] && 'invalid_budget' === $rejected_budget['reason'], 'invalid budget rejected with actionable machine reason' );
	cc87_assert( $before === get_option( Config::STATE_OPTION ), 'invalid budget preserved previous option' );
}
cc87_assert( 'INVALID' === Admin::process( cc60_post( 'budget' ), 'POST', $runtime )['status'], 'missing budget rejected' );

// Real Admin handler L changes have no runtime/DDL/DCL authority.
$trigger = Engine::trigger_name( 'wp_redirection_items' );
$metadata = $root->get_row( $root->prepare( 'SELECT ACTION_STATEMENT, DEFINER, ACTION_TIMING FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s', $trigger ), ARRAY_A );
$grants = $root->get_results( "SHOW GRANTS FOR 'cc87_writer'@'%'", ARRAY_N );
foreach ( array( 5, 25, 0, 2000 ) as $budget ) {
	list( $changed, $threads ) = cc87_trace_all( $root, static function () use ( $budget ) {
		return cc60_admin( 'budget', array( 'logical_budget' => (string) $budget ) );
	} );
	cc87_assert( 'OK' === $changed['status'] && $budget === Config::read( $operation )['logical_budget'], 'Admin budget saved: ' . $budget );
	foreach ( $threads as $id => $queries ) {
		cc87_assert( $id !== $runtime_id, 'budget change must not use restricted runtime' );
		foreach ( $queries as $sql ) {
			cc87_assert( ! preg_match( '/^(CREATE|DROP|ALTER|GRANT|REVOKE|TRUNCATE)\b/i', $sql ), 'budget change issued privileged SQL: ' . $sql );
		}
	}
	cc87_assert( $metadata === $root->get_row( $root->prepare( 'SELECT ACTION_STATEMENT, DEFINER, ACTION_TIMING FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s', $trigger ), ARRAY_A ), 'Admin budget changed trigger' );
	cc87_assert( $grants === $root->get_results( "SHOW GRANTS FOR 'cc87_writer'@'%'", ARRAY_N ), 'Admin budget changed grants' );
	cc87_assert( 2000 === ( new Engine( $runtime ) )->runtime_ceiling( 'wp_redirection_items' ), 'Admin budget changed P=2000' );
}
cc87_assert( 'OK' === cc60_admin( 'budget', array( 'logical_budget' => '5' ) )['status'], 'restore L5 through Admin' );
echo "#60 $host: Admin strict L 5→25→0→2000, no DDL/DCL/runtime SQL, P=2000 and grants/trigger unchanged: PASS\n";

// All preflight refusals must leave enabled=false (including Doctor failure).
cc87_assert( 'DISABLED' === Status::check( $operation, '5.5.2', $runtime )['status'], 'still disabled before enable' );
$enabled_post = cc60_post( 'enable' );
cc87_assert( 'redirection_version_unsupported' === Admin::process( $enabled_post, 'POST', $runtime, '5.5.3' )['reason'], 'wrong version enable rejected' );
$bad_runtime = new wpdb( 'cc60_absent', 'no-such-credential', 'wp_test', $host );
$bad_runtime->suppress_errors( true );
cc87_assert( 'runtime_unavailable' === Admin::process( $enabled_post, 'POST', $bad_runtime )['reason'], 'runtime absent enable rejected' );
$unavailable = Product_Status::snapshot( $bad_runtime );
cc87_assert( false === $unavailable['runtime_available'] && 'NOT_READY' === $unavailable['preflight_status'] &&
	'runtime_unavailable' === $unavailable['preflight_reason'] && 'UNKNOWN' === $unavailable['doctor_state'], 'missing runtime presentation remains distinct and fail-closed' );
$engine = new Engine( $root );
$engine->remove_owned_policy( 'wp_redirection_items', 2000, 'cc87_writer' );
$engine->install_policy( 'wp_redirection_items', 1999, 'cc87_writer' );
cc87_assert( 'physical_ceiling_mismatch' === Admin::process( $enabled_post, 'POST', $runtime )['reason'], 'P mismatch enable rejected' );
$engine->remove_owned_policy( 'wp_redirection_items', 1999, 'cc87_writer' );
$engine->install_policy( 'wp_redirection_items', 2000, 'cc87_writer' );
cc87_query( $root, "GRANT INSERT ON wp_test.wp_redirection_items TO 'cc87_writer'@'%'" );
cc87_assert( 'target_privileges_mismatch' === Admin::process( $enabled_post, 'POST', $runtime )['reason'], 'extra target grant enable rejected' );
cc87_query( $root, "REVOKE INSERT ON wp_test.wp_redirection_items FROM 'cc87_writer'@'%'" );
cc87_query( $root, "DROP TRIGGER `$trigger`" );
cc87_assert( 'doctor_not_ready' === Admin::process( $enabled_post, 'POST', $runtime )['reason'], 'Doctor failure enable rejected' );
Plan::add_target( 'wp_test', 'cc87_writer', '%', 'wp_redirection_items', 2000 )->apply( $root );
cc87_assert( false === Config::read( $operation )['enabled'], 'failed preflights never persisted enabled' );
cc87_assert( 'OK' === cc60_admin( 'enable' )['status'] && true === Config::read( $operation )['enabled'], 'valid live preflight allows enable' );
cc87_assert( 'READY' === Product_Status::snapshot()['operation_status'] && 'PASS' === Product_Status::snapshot()['doctor_state'], 'operation READY and Doctor PASS are separate facts' );
cc87_assert( 'OK' === cc60_admin( 'disable' )['status'] && false === Config::read( $operation )['enabled'], 'Admin disables' );
cc87_seed_bulk( $root, 2 );
$disabled_response = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
cc87_assert( 503 === $disabled_response->get_status() && array( 2, 0 ) === cc60_observe( $host ), 'disabled global Disable fails closed' );
cc87_assert( 'OK' === cc60_admin( 'enable' )['status'], 're-enable after disabled REST' );
echo "#60 $host: enable preflight version/runtime/P/grants/Doctor failures, READY enable, disabled global REST fail-closed: PASS\n";

// Real authenticated REST path persists only last certified outcome after Guard.
cc87_seed_bulk( $root, 6 );
$denied_response = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
$denied = Last_Outcome::read();
cc87_assert( 409 === $denied_response->get_status() && array( 6, 0 ) === cc60_observe( $host ), 'real REST six-row denial, fresh observer unchanged: status=' . $denied_response->get_status() . ' data=' . json_encode( $denied_response->get_data() ) . ' counts=' . json_encode( cc60_observe( $host ) ) );
cc87_assert( is_array( $denied ) && 'DENIED' === $denied['outcome'] && 'logical_budget_exceeded' === $denied['reason'] &&
	5 === $denied['logical_budget'] && 2000 === $denied['physical_ceiling'] && 6 === $denied['attempted'] && 6 === $denied['consumed'] &&
	'logical' === $denied['denial_kind'] && $denied['transaction_rollback_attempted'] && null === $denied['guard_rollback_completed'] &&
	false === $denied['durability_verified_by_fresh_observer'], 'last DENIED production facts remain truthful' );
$autoload = $root->get_var( $root->prepare( 'SELECT autoload FROM wp_options WHERE option_name = %s', Last_Outcome::OPTION ) );
cc87_assert( in_array( $autoload, array( 'no', 'off' ), true ), 'single last-outcome option is not autoloaded: ' . (string) $autoload );
cc87_assert( 'DENIED' === Product_Status::snapshot()['last_outcome']['outcome'], 'shared Admin/CLI snapshot reads DENIED evidence' );
ob_start(); Admin::render(); $denied_html = ob_get_clean();
cc87_assert( false !== strpos( $denied_html, 'Rollback attempted: yes; rollback completion: unknown; independent fresh-observer durability verification: no.' ), 'Admin DENIED wording does not claim verified rollback' );
cc87_assert( false === strpos( $denied_html, 'cc87_secret' ) && false === strpos( $denied_html, 'disposable_root_password' ), 'DENIED notice secret-free' );
echo "#60 $host: real REST over-L DENIED, last outcome/Admin truthful, fresh observer unchanged: PASS\n";

// Cross-process CLI status must see the same DENIED record before COMMIT overwrites it.
$cli_command = 'wp --path=' . escapeshellarg( ABSPATH ) . ' commitcap status --format=json';
$cli_output = array(); $cli_code = -1;
exec( $cli_command . ' 2>&1', $cli_output, $cli_code );
$cli_denied = json_decode( implode( "\n", $cli_output ), true );
cc87_assert( 0 === $cli_code && is_array( $cli_denied ) && 'DENIED' === $cli_denied['last_outcome']['outcome'] &&
	6 === $cli_denied['last_outcome']['attempted'] && false === $cli_denied['last_outcome']['durability_verified_by_fresh_observer'], 'actual CLI status agrees with DENIED evidence: ' . implode( ' ', $cli_output ) );

cc87_assert( 'OK' === cc60_admin( 'budget', array( 'logical_budget' => '10' ) )['status'], 'Admin raises L10' );
$committed_response = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
$committed = Last_Outcome::read();
cc87_assert( 200 === $committed_response->get_status() && array( 6, 6 ) === cc60_observe( $host ), 'real REST six-row COMMIT, fresh observer durable' );
cc87_assert( is_array( $committed ) && 'COMMITTED' === $committed['outcome'] && 10 === $committed['logical_budget'] &&
	6 === $committed['consumed'] && 6 === $committed['affected_rows'] && false === $committed['durability_verified_by_fresh_observer'], 'last COMMITTED production facts remain truthful' );
ob_start(); Admin::render(); $committed_html = ob_get_clean();
cc87_assert( false !== strpos( $committed_html, 'COMMITTED' ) && false !== strpos( $committed_html, 'consumed: 6' ), 'Admin shows committed evidence' );
$cli_output = array(); $cli_code = -1;
exec( $cli_command . ' 2>&1', $cli_output, $cli_code );
$cli_committed = json_decode( implode( "\n", $cli_output ), true );
cc87_assert( 0 === $cli_code && is_array( $cli_committed ) && 'COMMITTED' === $cli_committed['last_outcome']['outcome'] &&
	6 === $cli_committed['last_outcome']['affected_rows'], 'CLI status agrees with COMMITTED evidence: ' . implode( ' ', $cli_output ) );

// Malformed stored evidence is ignored and cannot inject markup or affect READY.
update_option( Last_Outcome::OPTION, array( 'outcome' => '<script>alert(1)</script>' ), false );
ob_start(); Admin::render(); $invalid_html = ob_get_clean();
cc87_assert( null === Last_Outcome::read() && false === strpos( $invalid_html, '<script>alert(1)</script>' ) &&
	'READY' === Product_Status::snapshot()['operation_status'], 'malformed evidence ignored without changing readiness or rendering raw markup' );
$contradictory = $committed;
$contradictory['outcome'] = 'DENIED';
update_option( Last_Outcome::OPTION, $contradictory, false );
cc87_assert( null === Last_Outcome::read(), 'semantically contradictory evidence is not rendered as DENIED' );
update_option( Last_Outcome::OPTION, $committed, false ); // Restore validated real evidence after tamper test.
set_transient( 'commitcap_notice_1', array( 'status' => '<img src=x onerror=alert(1)>', 'reason' => '<script>alert(2)</script>' ), 120 );
ob_start(); Admin::render(); $escaped_notice = ob_get_clean();
cc87_assert( false === strpos( $escaped_notice, '<img src=x onerror=alert(1)>' ) && false === strpos( $escaped_notice, '<script>alert(2)</script>' ) &&
	false !== strpos( $escaped_notice, '&lt;img' ), 'Admin escapes untrusted notice text and never renders raw reason' );
echo "#60 $host: real REST COMMITTED, Admin/actual CLI status agree, malformed evidence ignored: PASS\n";

// The informational option is not in the Guard decision path. A failing
// storage hook must not change either real REST outcome or fresh durability.
$saved_record = Last_Outcome::read();
$save_attempts = 0;
$fail_save = static function ( $value ) use ( &$save_attempts ) {
	++$save_attempts;
	throw new RuntimeException( 'synthetic local evidence storage failure' );
};
add_filter( 'pre_update_option_' . Last_Outcome::OPTION, $fail_save );
cc87_assert( 'OK' === cc60_admin( 'budget', array( 'logical_budget' => '5' ) )['status'], 'storage failure setup L5' );
cc87_seed_bulk( $root, 6 );
$still_denied = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
cc87_assert( 409 === $still_denied->get_status() && array( 6, 0 ) === cc60_observe( $host ), 'failed evidence write does not turn DENIED into success' );
cc87_assert( 'OK' === cc60_admin( 'budget', array( 'logical_budget' => '10' ) )['status'], 'storage failure setup L10' );
$still_committed = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
cc87_assert( 200 === $still_committed->get_status() && array( 6, 6 ) === cc60_observe( $host ), 'failed evidence write does not turn COMMITTED into rollback' );
remove_filter( 'pre_update_option_' . Last_Outcome::OPTION, $fail_save );
cc87_assert( 2 === $save_attempts && $saved_record === Last_Outcome::read(), 'two failed local saves preserved prior informational record' );
echo "#60 $host: failing informational evidence save does not change real DENIED/COMMITTED outcomes: PASS\n";

// An item-scoped stock call must not overwrite the last certified outcome.
cc87_seed_bulk( $root, 6 );
$one_id = (int) $root->get_var( 'SELECT id FROM wp_redirection_items ORDER BY id LIMIT 1' );
$stock = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'items' => array( $one_id ) ) ) );
cc87_assert( 200 === $stock->get_status() && ! isset( $stock->get_data()['commitcap'] ) &&
	$saved_record === Last_Outcome::read(), 'item-scoped stock operation did not forge production evidence' );
cc87_seed_bulk( $root, 6 );
$filtered = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true, 'filterBy' => array( 'url' => 'cc87-bulk-0' ) ) ) );
cc87_assert( 200 === $filtered->get_status() && ! isset( $filtered->get_data()['commitcap'] ) &&
	array( 6, 1 ) === cc60_observe( $host ) && $saved_record === Last_Outcome::read(), 'filtered global Disable remains stock and leaves certified evidence unchanged' );
cc87_seed_bulk( $root, 6 );
$restored = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
cc87_assert( 200 === $restored->get_status() && array( 6, 6 ) === cc60_observe( $host ), 'restored Redirection six-row committed fixture for cleanup proof' );

// Three actual admin-post demo actions, no installer/reset in the trace window.
list( $admin_runs, $demo_threads ) = cc87_trace_all( $root, static function () use ( $host, $table, $root ) {
	$states = array();
	foreach ( array( 'B', 'A', 'B' ) as $index => $expected ) {
		$action = cc60_admin( 'demo' );
		cc87_assert( 'COMPLETE' === $action['status'] && 'logical' === $action['demo']['denied']['denial_kind'], 'Admin demo click ' . ( $index + 1 ) );
		$observer = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
		$observer->suppress_errors( true );
		$values = array_map( 'intval', $observer->get_col( "SELECT value FROM `$table` ORDER BY id" ) );
		cc87_assert( ( 'B' === $expected ? array( 1, 1, 1, 1, 1, 0 ) : array( 0, 0, 0, 0, 0, 0 ) ) === $values, 'Admin demo fresh observer ' . $expected );
		$states[] = $action['demo']['safe_state'];
	}
	return $states;
} );
cc87_assert( array( 'B', 'A', 'B' ) === $admin_runs, 'Admin three clicks without reset' );
$connections = $root->get_results( "SELECT thread_id, user_host FROM mysql.general_log WHERE command_type = 'Connect'", ARRAY_A );
$writer_threads = array();
foreach ( (array) $connections as $connection ) {
	if ( false !== strpos( (string) $connection['user_host'], 'cc87_writer' ) ) {
		$writer_threads[ (int) $connection['thread_id'] ] = true;
	}
}
$safe_updates = 0; $denied_updates = 0; $normal_demo_updates = 0; $privileged = 0; $used_threads = array();
foreach ( $demo_threads as $id => $queries ) {
	foreach ( $queries as $sql ) {
		if ( preg_match( '/^UPDATE `?' . preg_quote( $table, '/' ) . '`? SET value = [01] WHERE id BETWEEN 1 AND 5 AND value = [01]$/', $sql ) ) {
			++$safe_updates;
			$normal_demo_updates += (int) ( $id === $normal_id || ! isset( $writer_threads[ $id ] ) );
			$used_threads[ $id ] = true;
		}
		if ( preg_match( '/^UPDATE `?' . preg_quote( $table, '/' ) . '`? SET value = 2 WHERE id BETWEEN 1 AND 6$/', $sql ) ) {
			++$denied_updates;
			$normal_demo_updates += (int) ( $id === $normal_id || ! isset( $writer_threads[ $id ] ) );
			$used_threads[ $id ] = true;
		}
		$privileged += (int) (bool) preg_match( '/^(?:CREATE|ALTER|DROP|GRANT|REVOKE|TRUNCATE)\b|^(?:DELETE FROM|INSERT INTO) `?' . preg_quote( $table, '/' ) . '`?(?=\s|$)/i', $sql );
	}
}
cc87_assert( 3 === $safe_updates && 3 === $denied_updates && 3 === count( $used_threads ) && 0 === $normal_demo_updates && 0 === $privileged,
	'Admin three-run trace: only restricted SELECT/UPDATE, no demo reset/installer SQL (safe=' . $safe_updates . ' denied=' . $denied_updates . ' normal=' . $normal_demo_updates . ' privileged=' . $privileged . ')' );
ob_start(); Admin::render(); $demo_html = ob_get_clean();
cc87_assert( false !== strpos( $demo_html, 'CommitCap-owned disposable demo: safe leg 5 UPDATE row events COMMITTED; denied leg 6 UPDATE row events' ) &&
	false !== strpos( $demo_html, 'rollback attempted. Independent durable rollback is not verified by this request.' ), 'Admin demo notice is factual and synthetic' );
cc87_assert( false === strpos( $demo_html, 'cc87_secret' ) && false === strpos( $demo_html, 'disposable_root_password' ), 'Admin demo notice has no secrets' );
echo "#60 $host: Admin demo #1 B, #2 A, #3 B; fresh observers; zero trusted reset/DDL/DCL/INSERT/DELETE: PASS\n";

// Deliberate noncanonical data needs trusted recovery, never a web reset.
cc87_query( $root, "UPDATE `$table` SET value = 0 WHERE id = 3" );
$not_ready = Product_Status::snapshot();
cc87_assert( 'NOT_READY' === $not_ready['demo_status'] && 'trusted_reset_required' === $not_ready['demo_reason'], 'Admin noncanonical status' );
cc87_assert( 'NOT_READY' === cc60_admin( 'demo' )['status'], 'Admin demo noncanonical refusal' );
cc87_assert( 0 === (int) $root->get_var( "SELECT COUNT(*) FROM `$table` WHERE value = 2" ), 'Admin refusal caused no denied mutation' );
$cli_output = array(); $cli_code = -1;
exec( 'wp --path=' . escapeshellarg( ABSPATH ) . ' commitcap demo --format=json 2>&1', $cli_output, $cli_code );
$cli_invalid = json_decode( implode( "\n", $cli_output ), true );
cc87_assert( 0 !== $cli_code && is_array( $cli_invalid ) && 'trusted_reset_required' === $cli_invalid['reason'], 'actual CLI noncanonical nonzero/no reset: ' . implode( ' ', $cli_output ) );
$setup->reset(); // Test/operator recovery, outside ordinary Admin/CLI run windows.
cc87_assert( 'READY' === ( new Demo() )->status()['status'], 'trusted recovery restores demo READY' );
cc87_assert( 'OK' === cc60_admin( 'budget', array( 'logical_budget' => '5' ) )['status'], 'leave operation L5 for CLI' );
echo "#60 $host: Admin/CLI noncanonical refusal then explicit trusted fixture recovery: PASS\n";

// Leave fixture at A, with real operation READY, for three separate wp processes.
