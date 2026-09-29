<?php
// #87: a fresh PHP process where Redirection is deactivated. The adapter must
// refuse before any callback and leave the restricted connection untouched.
require_once WP_PLUGIN_DIR . '/commitcap/commitcap.php';
require_once __DIR__ . '/helpers.php';

use CommitCap\Redirection_Bulk_Disable as Adapter;

$host = getenv( 'CC_ENGINE_HOST' );
cc87_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), 'unknown fixture host' );
cc87_assert( ! defined( 'REDIRECTION_VERSION' ), 'Redirection constant still defined in missing-plugin process' );
cc87_assert( ! class_exists( 'Red_Item' ), 'Red_Item still loaded in missing-plugin process' );
cc87_assert( null === Adapter::detected_version(), 'missing plugin reported a version' );

$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
$normal    = $GLOBALS['wpdb'];
$normal_id = (int) $normal->get_var( 'SELECT CONNECTION_ID()' );
$runtime = new wpdb( 'cc87_writer', 'cc87_secret', 'wp_test', $host );
$runtime->suppress_errors( true );
$runtime->set_prefix( $normal->prefix );
$runtime_id = (int) $runtime->get_var( 'SELECT CONNECTION_ID()' );
cc87_assert( $runtime_id > 0, 'runtime connection' );

$before = cc87_counts( $root );
$adapter = new Adapter( $runtime, 5 );
list( $result, $statements ) = cc87_trace( $root, $runtime_id, function () use ( $runtime, $adapter ) {
	$runtime->query( "SELECT 'CC87_TRACE_START'" );
	$value = $adapter->run();
	$runtime->query( "SELECT 'CC87_TRACE_END'" );
	return $value;
} );
cc87_expect( $result, 'UNKNOWN', 'redirection_missing', null, '#87 missing plugin' );
cc87_assert( array() === $statements, 'missing-plugin run touched the restricted connection: ' . json_encode( $statements ) );
cc87_assert_normal( $normal, $normal_id, '#87 missing plugin' );
cc87_assert( $before === cc87_counts( $root ), 'missing-plugin run changed durable rows' );

echo "  missing Redirection plugin -> refused before callback: PASS\n";
