<?php
// External #60 CI observer/operator only; root never enters normal product PHP.
require_once WP_PLUGIN_DIR . '/writeleash/writeleash.php';
require_once __DIR__ . '/helpers.php';

use WriteLeash\Certified_Operation;
use WriteLeash\Certified_Operation_Status;
use WriteLeash\Disposable_Demo;
use WriteLeash\Disposable_Demo_Setup;
use WriteLeash\Last_Outcome;
use WriteLeash\Provisioning_Plan;
use WriteLeash\Update_Engine;

$host = getenv( 'CC_ENGINE_HOST' );
$phase = getenv( 'CC60_PHASE' );
$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
$table = Disposable_Demo::table( (string) $GLOBALS['wpdb']->prefix );
if ( 'p_bad' === $phase || 'p_fix' === $phase ) {
	$engine = new Update_Engine( $root );
	$from = 'p_bad' === $phase ? 2000 : 1999;
	$to = 'p_bad' === $phase ? 1999 : 2000;
	$engine->remove_owned_policy( 'wp_redirection_items', $from, 'cc87_writer' );
	$engine->install_policy( 'wp_redirection_items', $to, 'cc87_writer' );
} elseif ( 'grant_bad' === $phase || 'grant_fix' === $phase ) {
	cc87_query( $root, ( 'grant_bad' === $phase ? 'GRANT' : 'REVOKE' ) . " INSERT ON wp_test.wp_redirection_items " . ( 'grant_bad' === $phase ? 'TO' : 'FROM' ) . " 'cc87_writer'@'%'" );
} elseif ( 'doctor_bad' === $phase ) {
	cc87_query( $root, 'DROP TRIGGER `' . Update_Engine::trigger_name( 'wp_redirection_items' ) . '`' );
} elseif ( 'doctor_fix' === $phase ) {
	Provisioning_Plan::add_target( 'wp_test', 'cc87_writer', '%', 'wp_redirection_items', 2000 )->apply( $root );
} elseif ( 'begin' === $phase ) {
	cc87_assert( array( 0, 0, 0, 0, 0, 0 ) === array_map( 'intval', $root->get_col( "SELECT value FROM `$table` ORDER BY id" ) ), 'CLI begins in A' );
	cc87_query( $root, 'SET GLOBAL general_log = 0' );
	cc87_query( $root, 'TRUNCATE TABLE mysql.general_log' );
	cc87_query( $root, "SET GLOBAL log_output = 'TABLE'" );
	cc87_query( $root, 'SET GLOBAL general_log = 1' );
	echo "#60 $host: CLI three-process uninterrupted trace started at A\n";
} elseif ( 'observe' === $phase ) {
	$expected = getenv( 'CC60_EXPECTED' );
	$values = array_map( 'intval', $root->get_col( "SELECT value FROM `$table` ORDER BY id" ) );
	cc87_assert( ( 'B' === $expected ? array( 1, 1, 1, 1, 1, 0 ) : array( 0, 0, 0, 0, 0, 0 ) ) === $values,
		'fresh separate-process CLI observer must see state ' . $expected );
	cc87_assert( 0 === (int) $root->get_var( "SELECT COUNT(*) FROM `$table` WHERE value = 2" ), 'CLI denied value not durable' );
	echo "#60 $host: fresh external CLI observer state $expected; denied value 2 absent: PASS\n";
} elseif ( 'end' === $phase ) {
	cc87_query( $root, 'SET GLOBAL general_log = 0' );
	$connects = $root->get_results( "SELECT thread_id, user_host FROM mysql.general_log WHERE command_type = 'Connect'", ARRAY_A );
	$writers = array();
	foreach ( (array) $connects as $connection ) {
		if ( false !== strpos( (string) $connection['user_host'], 'cc87_writer' ) ) {
			$writers[ (int) $connection['thread_id'] ] = true;
		}
	}
	$queries = $root->get_results( "SELECT thread_id, argument FROM mysql.general_log WHERE command_type = 'Query'", ARRAY_A );
	$safe = 0; $denied = 0; $other = 0; $privileged = 0; $used = array();
	foreach ( (array) $queries as $row ) {
		$sql = preg_replace( '/\s+/', ' ', trim( (string) $row['argument'] ) );
		$id = (int) $row['thread_id'];
		if ( preg_match( '/^UPDATE `?' . preg_quote( $table, '/' ) . '`? SET value = [01] WHERE id BETWEEN 1 AND 5 AND value = [01]$/', $sql ) ) {
			++$safe; $used[ $id ] = true;
			cc87_assert( isset( $writers[ $id ] ), 'CLI safe UPDATE must use runtime account' );
		} elseif ( preg_match( '/^UPDATE `?' . preg_quote( $table, '/' ) . '`? SET value = 2 WHERE id BETWEEN 1 AND 6$/', $sql ) ) {
			++$denied; $used[ $id ] = true;
			cc87_assert( isset( $writers[ $id ] ), 'CLI denied UPDATE must use runtime account' );
		} elseif ( preg_match( '/^(?:INSERT INTO|DELETE FROM|REPLACE INTO|TRUNCATE TABLE|UPDATE) `?' . preg_quote( $table, '/' ) . '`?(?=\s|$)/i', $sql ) &&
			! preg_match( '/^UPDATE `' . preg_quote( $table, '/' ) . '` SET `id` = `id` LIMIT 1$/', $sql ) ) {
			++$other;
		}
		$privileged += (int) (bool) preg_match( '/^(?:CREATE|ALTER|DROP|GRANT|REVOKE|TRUNCATE)\b/i', $sql );
		cc87_assert( ! preg_match( '/^UPDATE `?wp_redirection_items`?/i', $sql ), 'CLI demo never UPDATEs Redirection' );
	}
	cc87_assert( 3 === $safe && 3 === $denied && 3 === count( $used ) && 0 === $other && 0 === $privileged,
		"CLI 3-process trace: safe=$safe denied=$denied threads=" . count( $used ) . " unreviewed_demo=$other privileged=$privileged" );
	echo "#60 $host: three separate wp writeleash demo processes B/A/B; one safe + one denied restricted UPDATE each; zero demo INSERT/DELETE/DDL/DCL/reset: PASS\n";
} elseif ( 'cleanup' === $phase ) {
	$setup = new Disposable_Demo_Setup( $root, 'cc87_writer', '%' );
	cc87_assert( 'REMOVED' === $setup->cleanup()['status'], 'exact demo cleanup' );
	cc87_assert( 'ABSENT' === $setup->cleanup_status()['status'], 'demo absent after cleanup' );
	cc87_assert( array( 6, 6 ) === cc87_counts( $root ), 'Redirection data untouched by CLI demo/cleanup' );
	$runtime = Certified_Operation_Status::runtime_connection();
	cc87_assert( $runtime instanceof wpdb && 2000 === ( new Update_Engine( $runtime ) )->runtime_ceiling( 'wp_redirection_items' ), 'Redirection policy and shared runtime preserved' );
	cc87_assert( 'READY' === Certified_Operation_Status::check( Certified_Operation::redirection_5_5_2_bulk_disable(), '5.5.2', $runtime )['status'], 'production READY after demo cleanup' );
	cc87_assert( 'COMMITTED' === Last_Outcome::read()['outcome'], 'last real production outcome not overwritten by demo' );
	echo "#60 $host: exact demo cleanup, Redirection table/policy and shared account preserved: PASS\n";
} else {
	throw new RuntimeException( 'Unknown trusted CI fixture phase.' );
}
