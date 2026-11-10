<?php
/**
 * Read prices and the Protection report after the disposable HTTP steps.
 * No network. Writes one JSON object to WL327_CHECK_OUT.
 */
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "check.php must run under wp eval-file\n" );
	exit( 1 );
}

$out = getenv( 'WL327_CHECK_OUT' );
$mode = getenv( 'WL327_CHECK_MODE' );
if ( ! is_string( $out ) || '' === $out || ( 'before' !== $mode && 'after' !== $mode ) ) {
	fwrite( STDERR, "WL327_CHECK_OUT and WL327_CHECK_MODE=before|after are required\n" );
	exit( 1 );
}

$permitted_id = (int) getenv( 'WL327_PERMITTED_ID' );
$denied_id = (int) getenv( 'WL327_DENIED_ID' );
$actor_id = (int) getenv( 'WL327_ACTOR_ID' );
if ( $permitted_id < 1 || $denied_id < 1 || $actor_id < 1 ) {
	fwrite( STDERR, "product and actor ids are required\n" );
	exit( 1 );
}

/**
 * @return array<string, mixed>
 */
function wl327_snapshot( $product_id ) {
	global $wpdb;
	$post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID=%d", $product_id ), ARRAY_A );
	$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_key, meta_id", $product_id ), ARRAY_A );
	$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
	$lookup = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$lookup_table} WHERE product_id=%d", $product_id ), ARRAY_A );
	$regular = '';
	if ( is_array( $meta ) ) {
		foreach ( $meta as $row ) {
			if ( '_regular_price' === ( $row['meta_key'] ?? '' ) ) {
				$regular = (string) $row['meta_value'];
			}
		}
	}
	return array(
		'id' => (int) $product_id,
		'regular_price' => $regular,
		'post' => is_array( $post ) ? $post : null,
		'meta' => is_array( $meta ) ? $meta : array(),
		'lookup' => is_array( $lookup ) ? $lookup : null,
	);
}

if ( 'before' === $mode ) {
	$payload = array(
		'permitted' => wl327_snapshot( $permitted_id ),
		'denied' => wl327_snapshot( $denied_id ),
	);
	if ( false === file_put_contents( $out, wp_json_encode( $payload ) ) ) {
		fwrite( STDERR, "could not write the before snapshot\n" );
		exit( 1 );
	}
	fwrite( STDOUT, "before snapshot written\n" );
	return;
}

$before_path = getenv( 'WL327_BEFORE' );
$before = is_string( $before_path ) ? json_decode( (string) file_get_contents( $before_path ), true ) : null;
if ( ! is_array( $before ) || ! isset( $before['denied'] ) ) {
	fwrite( STDERR, "before snapshot is missing\n" );
	exit( 1 );
}

$calls = 0;
add_filter(
	'pre_http_request',
	static function ( $pre ) use ( &$calls ) {
		++$calls;
		return $pre;
	},
	0
);
$report = WriteLeash\Protection_Report_Query::load( 7, 1 );
if ( 0 !== $calls ) {
	fwrite( STDERR, "protection report made {$calls} HTTP requests\n" );
	exit( 1 );
}

$permitted = wl327_snapshot( $permitted_id );
$denied = wl327_snapshot( $denied_id );
$errors = array();
if ( '30.00' !== $permitted['regular_price'] ) {
	$errors[] = 'permitted price is ' . $permitted['regular_price'];
}
if ( '25.00' !== $denied['regular_price'] ) {
	$errors[] = 'denied price is ' . $denied['regular_price'];
}
if ( $denied != $before['denied'] ) {
	$errors[] = 'denied product row changed';
}
$rest = (int) ( $report['surfaces']['rest'] ?? 0 );
if ( (int) $report['blocked'] < 1 || $rest < 1 ) {
	$errors[] = 'report did not count a REST denial';
}
$matched = false;
foreach ( $report['rows'] as $row ) {
	if ( 'rest' === ( $row['surface'] ?? '' ) && (int) ( $row['actor_id'] ?? 0 ) === $actor_id && 'writeleash_perimeter_denied' === ( $row['reason'] ?? '' ) ) {
		$matched = true;
	}
}
if ( ! $matched ) {
	$errors[] = 'report rows have no REST denial for the actor';
}
if ( array() !== $errors ) {
	fwrite( STDERR, implode( '; ', $errors ) . "\n" );
	exit( 1 );
}

$payload = array(
	'permitted_regular_price' => $permitted['regular_price'],
	'denied_regular_price' => $denied['regular_price'],
	'denied_unchanged' => true,
	'http_requests_during_report' => $calls,
	'report' => array(
		'window_days' => (int) $report['window_days'],
		'blocked' => (int) $report['blocked'],
		'surfaces' => $report['surfaces'],
		'log_enabled' => (bool) $report['log_enabled'],
		'active_grant_count' => (int) $report['active_grant_count'],
		'rows' => $report['rows'],
		'grants' => $report['grants'],
	),
);
if ( false === file_put_contents( $out, wp_json_encode( $payload ) ) ) {
	fwrite( STDERR, "could not write the check file\n" );
	exit( 1 );
}
fwrite( STDOUT, "check passed blocked={$report['blocked']} rest={$rest}\n" );
