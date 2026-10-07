<?php
// C124-001: observe the actual verification argument while delegating to real
// WordPress functions. Run in a fresh WP-CLI process with Woo/public plugin.
namespace WriteLeash;

function wp_unslash( $value ) {
	$GLOBALS['wl124_nonce_events'][] = array( 'unslash', $value );
	return \wp_unslash( $value );
}
function sanitize_text_field( $value ) {
	$GLOBALS['wl124_nonce_events'][] = array( 'sanitize', $value );
	return \sanitize_text_field( $value );
}
function wp_verify_nonce( $nonce, $action = -1 ) {
	$GLOBALS['wl124_nonce_events'][] = array( 'verify', $nonce, $action );
	return \wp_verify_nonce( $nonce, $action );
}

$assertions = 0;
$eq = static function ( $actual, $expected, string $label ) use ( &$assertions ): void {
	++$assertions;
	if ( $actual !== $expected ) { throw new \RuntimeException( 'C124-001: ' . $label ); }
};
// A slash before a nonce is not transport encoding: \0 unslashes to NUL.
// Synthetic controls exercise normalization only, never product authorization.
$eq( \wp_unslash( '\\' . '0abc' ), "\0abc", 'old zero-prefix fixture becomes NUL' );
foreach ( array( '0abc', 'aabc' ) as $synthetic ) {
	$eq( \sanitize_text_field( \wp_unslash( '\\<b>' . $synthetic . '</b>' ) ), $synthetic, 'wrapped fixture is prefix-independent' );
}
$actor = static function ( string $login, string $role ): int {
	$user = \get_user_by( 'login', $login );
	if ( $user ) { return $user->ID; }
	$id = \wp_insert_user( array( 'user_login' => $login, 'user_pass' => \wp_generate_password( 32 ), 'role' => $role ) );
	if ( \is_wp_error( $id ) ) { throw new \RuntimeException( 'C124-001 fixture user' ); }
	return $id;
};
$owner = $actor( 'wl124_nonce_owner', 'shop_manager' );
$other = $actor( 'wl124_nonce_other', 'shop_manager' );
$low = $actor( 'wl124_nonce_low', 'subscriber' );
\wp_set_current_user( $owner );
$product = new \WC_Product_Simple();
$product->set_name( 'C124-001 nonce negative control' );
$product->set_status( 'publish' );
$product->set_regular_price( '21.00' );
$id = $product->save();
$post = array( '_wpnonce' => \wp_create_nonce( Free_Admin::ACTION_PREVIEW ), 'selector' => 'ids', 'ids' => (string) $id, 'operation' => 'SET', 'amount' => '22.00', 'max_products' => '100', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '50', 'block_zero' => '1' );
$created = Free_Admin::process_preview( $post, 'POST' );
$eq( $created['status'], 'OK', 'owner positive preview' );
$job = Job_Repository::read( $created['job_id'] );
$baseline = $job;
$product_before = array( \get_post( $id, ARRAY_A ), \get_post_meta( $id ) );
global $wpdb;
$durable_snapshot = static function () use ( $wpdb ): array {
	$rows = array();
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'writeleash_' ) . '%' ) );
	foreach ( $tables as $table ) {
		$rows[ $table ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A );
	}
	$rows['runner'] = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, 'writeleash_runner_state' ) );
	return $rows;
};
$durable_before = $durable_snapshot();
$baseline_counts = array();
foreach ( array( 'writeleash_jobs', 'writeleash_job_items' ) as $suffix ) {
	$baseline_counts[ $suffix ] = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . $suffix ) );
}
\wp_set_current_user( $other );
$actions = array(
	'preview' => Free_Admin::ACTION_PREVIEW,
	'approve' => Free_Admin::ACTION_APPROVE . '_' . $job['plan_id'],
	'resume' => Free_Admin::ACTION_RESUME . '_' . $job['public_id'],
	'undo' => Free_Admin::ACTION_UNDO . '_' . $job['public_id'],
);
foreach ( $actions as $name => $action ) {
	$valid = \wp_create_nonce( $action );
	$base = array( 'job' => $job['public_id'], 'selector' => 'invalid_selector' );
	$method = 'process_' . $name;
	foreach ( array( 'valid' => $valid, 'invalid' => 'invalid_nonce', 'slashed' => '\\<b>' . $valid . '</b>', 'html' => '<b>' . $valid . '</b>', 'mixed' => "\\\"<b>sentinel</b>\\\"\n" ) as $case => $raw ) {
		$input = $base; $input['_wpnonce'] = $raw;
		$GLOBALS['wl124_nonce_events'] = array();
		$result = Free_Admin::$method( $input, 'POST' );
		$unslashed = \wp_unslash( $raw );
		$clean = \sanitize_text_field( $unslashed );
		if ( 'slashed' === $case ) {
			$eq( $unslashed, '<b>' . $valid . '</b>', $name . ' slashed deterministic transport value' );
			$eq( $clean, $valid, $name . ' slashed normalized valid nonce' );
		}
		$eq( $GLOBALS['wl124_nonce_events'], array( array( 'unslash', $raw ), array( 'sanitize', $unslashed ), array( 'verify', $clean, $action ) ), $name . '/' . $case . ' exact boundary and order' );
		$passed = in_array( $case, array( 'valid', 'slashed', 'html' ), true );
		$eq( $result['reason'], $passed ? ( 'preview' === $name ? 'invalid_selector' : 'not_authorized' ) : 'invalid_nonce', $name . '/' . $case . ' real nonce result' );
	}
	$GLOBALS['wl124_nonce_events'] = array();
	$eq( Free_Admin::$method( $base, 'POST' )['reason'], 'invalid_nonce', $name . ' absent nonce' );
	$eq( $GLOBALS['wl124_nonce_events'], array(), $name . ' absent nonce never verified' );
	foreach ( array( null, 42, true, array( $valid ), new \stdClass() ) as $raw ) {
		$input = $base; $input['_wpnonce'] = $raw;
		$GLOBALS['wl124_nonce_events'] = array();
		$eq( Free_Admin::$method( $input, 'POST' )['reason'], 'invalid_nonce', $name . ' missing/nonstring rejection' );
		$eq( $GLOBALS['wl124_nonce_events'], array(), $name . ' no coercion/verification of nonstring' );
	}
	$input = $base; $input['_wpnonce'] = $valid;
	$GLOBALS['wl124_nonce_events'] = array();
	$eq( Free_Admin::$method( $input, 'GET' )['reason'], 'post_required', $name . ' GET rejected' );
	$eq( $GLOBALS['wl124_nonce_events'], array(), $name . ' method before nonce' );
	\wp_set_current_user( $low );
	$GLOBALS['wl124_nonce_events'] = array();
	$eq( Free_Admin::$method( $input, 'POST' )['reason'], 'capability_required', $name . ' capability independent' );
	$eq( $GLOBALS['wl124_nonce_events'], array(), $name . ' capability before nonce' );
	\wp_set_current_user( $other );
}
// #194: the AJAX discovery nonce must cross the same real WP boundary.
\wp_set_current_user( $other );
$discovery_action = Product_Discovery::ACTION;
$discovery_nonce = \wp_create_nonce( $discovery_action );
$discovery_input = array( 'nonce' => $discovery_nonce, 'term' => '', 'page' => '1', 'kind' => 'products' );
foreach ( array( 'valid' => $discovery_nonce, 'invalid' => 'invalid_nonce', 'slashed' => '\\<b>' . $discovery_nonce . '</b>', 'html' => '<b>' . $discovery_nonce . '</b>' ) as $case => $raw ) {
	$GLOBALS['wl124_nonce_events'] = array();
	$result = Product_Discovery::request( array_merge( $discovery_input, array( 'nonce' => $raw ) ), 'GET' );
	$unslashed = \wp_unslash( $raw );
	$clean = \sanitize_text_field( $unslashed );
	$eq( $GLOBALS['wl124_nonce_events'], array( array( 'unslash', $raw ), array( 'sanitize', $unslashed ), array( 'verify', $clean, $discovery_action ) ), 'discovery/' . $case . ' exact boundary and order' );
	$eq( $result['status'], 'invalid' === $case ? 'INVALID' : 'OK', 'discovery/' . $case . ' real nonce result' );
}
foreach ( array( null, 42, true, array( $discovery_nonce ), new \stdClass() ) as $raw ) {
	$GLOBALS['wl124_nonce_events'] = array();
	$eq( Product_Discovery::request( array_merge( $discovery_input, array( 'nonce' => $raw ) ), 'GET' )['reason'], 'invalid_nonce', 'discovery nonstring rejection' );
	$eq( $GLOBALS['wl124_nonce_events'], array(), 'discovery nonstring never verified' );
}
$GLOBALS['wl124_nonce_events'] = array();
$eq( Product_Discovery::request( $discovery_input, 'POST' )['reason'], 'invalid_nonce', 'discovery method before nonce' );
$eq( $GLOBALS['wl124_nonce_events'], array(), 'discovery wrong method never verified' );
\wp_set_current_user( $owner );
$eq( Product_Discovery::request( $discovery_input, 'GET' )['reason'], 'invalid_nonce', 'discovery cross-actor nonce rejected' );
\wp_set_current_user( $low );
$GLOBALS['wl124_nonce_events'] = array();
$eq( Product_Discovery::request( $discovery_input, 'GET' )['reason'], 'permission_denied', 'discovery capability independent of nonce' );
$eq( $GLOBALS['wl124_nonce_events'], array(), 'discovery capability before nonce' );
\wp_set_current_user( 0 );
$eq( Product_Discovery::request( $discovery_input, 'GET' )['reason'], 'permission_denied', 'discovery anonymous request rejected' );
\wp_set_current_user( $other );
$eq( $durable_snapshot(), $durable_before, 'all durable table rows and runner unchanged' );
$eq( Job_Repository::read( $job['id'] ), $baseline, 'rejected requests preserve durable job' );
foreach ( $baseline_counts as $suffix => $count ) {
	$eq( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . $suffix ) ), $count, 'rejected requests create no job/item' );
}
Price_Cache_Verifier::invalidate( $id );
$eq( array( \get_post( $id, ARRAY_A ), \get_post_meta( $id ) ), $product_before, 'rejected requests preserve full product/post/meta' );
$eq( \wc_get_product( $id )->get_regular_price( 'edit' ), '21.00', 'rejected requests preserve product' );
echo 'C124-001/#194 Admin and discovery, real WP normalization/verification, capability/method/cross-actor negatives, unchanged job/product PASS (' . $assertions . " assertions)\n";
