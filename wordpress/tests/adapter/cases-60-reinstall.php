<?php
// #60 repair: after the actual uninstall, a fresh WordPress process must not
// recover old local authorization, budget or production evidence.
require_once WP_PLUGIN_DIR . '/commitcap/commitcap.php';
require_once __DIR__ . '/helpers.php';
if ( ! function_exists( 'activate_plugin' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

use CommitCap\Admin_Page;
use CommitCap\Certified_Operation as Operation;
use CommitCap\Last_Outcome;
use CommitCap\Operation_Config as Config;
use CommitCap\Product_Status;

$host = getenv( 'CC_ENGINE_HOST' );
cc87_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), '#60 reinstall pinned host' );
$normal = $GLOBALS['wpdb'];
$normal->suppress_errors( true );
$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
$options_table = (string) $normal->options;
$operation = Operation::redirection_5_5_2_bulk_disable();

// Fresh process: the uninstalled local options must still be absent.
foreach ( array( 'commitcap_version', Config::STATE_OPTION, Last_Outcome::OPTION, 'commitcap_operation_budget_redirection_5_5_2_bulk_disable' ) as $owned_option ) {
	$remaining = (string) $root->get_var( $root->prepare( "SELECT COUNT(*) FROM `$options_table` WHERE option_name = %s", $owned_option ) );
	cc87_assert( '0' === $remaining, 'reinstall process saw surviving option: ' . $owned_option );
}

// Existing default semantics: absent config is disabled with no budget.
$state = Config::read( $operation );
cc87_assert( 'absent' === $state['state'] && false === $state['enabled'] && null === $state['logical_budget'], 'reinstall config default shape' );
cc87_assert( null === Last_Outcome::read(), 'reinstall recovered a last outcome' );

$snapshot = Product_Status::snapshot();
cc87_assert( false === $snapshot['enabled'] && null === $snapshot['logical_budget'] && null === $snapshot['last_outcome'], 'reinstall snapshot recovered old local state' );
cc87_assert( 'READY' !== $snapshot['operation_status'], 'reinstall must not auto-enable the operation' );
cc87_assert( 'READY' === $snapshot['demo_status'], 'surviving trusted demo DB policy is independent of local config' );

wp_set_current_user( 1 );
ob_start();
Admin_Page::render();
$html = ob_get_clean();
cc87_assert( false !== strpos( $html, 'No validated certified outcome has been recorded locally.' ), 'reinstall Admin did not show an empty outcome' );
cc87_assert( false === strpos( $html, 'COMMITTED' ) && false === strpos( $html, 'Budget L=' ) && false === strpos( $html, 'enabled: yes' ), 'reinstall Admin displayed old outcome, budget or enabled state' );
cc87_assert( false !== strpos( $html, 'enabled: no' ) && false !== strpos( $html, 'L=not set' ), 'reinstall Admin did not show the default disabled state' );
echo "#60 $host: reinstall defaults disabled/null with no old evidence in Admin/status\n";

// Re-activation recreates only plugin metadata, never old authorization.
\CommitCap\Lifecycle::activate();
cc87_assert( '0.1.0' === get_option( 'commitcap_version' ), 're-activation did not recreate plugin metadata' );
$state = Config::read( $operation );
cc87_assert( 'absent' === $state['state'] && false === $state['enabled'] && null === $state['logical_budget'], 're-activation restored old config' );
cc87_assert( null === Last_Outcome::read(), 're-activation restored old evidence' );
echo "#60 $host: re-activation writes metadata only; config stays absent, outcome stays null: PASS\n";
