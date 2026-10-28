<?php
/**
 * Canonical product-row snapshot for the #324 before/after check.
 *
 * Hashes every product and variation row in wp_posts, the postmeta for those
 * rows, and wp_wc_product_meta_lookup. A restricted-write denial passes only
 * when this hash is unchanged. Writes JSON to WL324_OUT.
 */
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "observe.php must run under wp eval-file\n" );
	exit( 1 );
}

$out = getenv( 'WL324_OUT' );
if ( ! is_string( $out ) || '' === $out ) {
	fwrite( STDERR, "WL324_OUT is required\n" );
	exit( 1 );
}

global $wpdb;

/**
 * @param mixed $value
 * @return mixed
 */
function wl324_canon( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( array_is_list( $value ) ) {
		return array_map( 'wl324_canon', $value );
	}
	ksort( $value );
	$sorted = array();
	foreach ( $value as $key => $item ) {
		$sorted[ $key ] = wl324_canon( $item );
	}
	return $sorted;
}

$posts = $wpdb->get_results(
	"SELECT * FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') ORDER BY ID ASC",
	ARRAY_A
);
if ( ! is_array( $posts ) ) {
	fwrite( STDERR, "posts query failed: {$wpdb->last_error}\n" );
	exit( 1 );
}
$ids = array_map( static function ( $row ) {
	return (int) $row['ID'];
}, $posts );

$meta = array();
if ( $ids ) {
	$in   = implode( ',', $ids );
	$meta = $wpdb->get_results(
		"SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) ORDER BY meta_id ASC",
		ARRAY_A
	);
	if ( ! is_array( $meta ) ) {
		fwrite( STDERR, "postmeta query failed: {$wpdb->last_error}\n" );
		exit( 1 );
	}
}

$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
$lookup       = $wpdb->get_results( "SELECT * FROM {$lookup_table} ORDER BY product_id ASC", ARRAY_A );
if ( ! is_array( $lookup ) ) {
	fwrite( STDERR, "lookup query failed: {$wpdb->last_error}\n" );
	exit( 1 );
}

$focus_id = (int) getenv( 'WL324_PRODUCT_ID' );
$focus    = array(
	'post'   => null,
	'prices' => array(),
	'lookup' => null,
);
foreach ( $posts as $row ) {
	if ( (int) $row['ID'] === $focus_id ) {
		$focus['post'] = $row;
	}
}
foreach ( $meta as $row ) {
	if ( (int) $row['post_id'] === $focus_id && in_array( $row['meta_key'], array( '_regular_price', '_price', '_sale_price' ), true ) ) {
		$focus['prices'][ $row['meta_key'] ] = $row['meta_value'];
	}
}
foreach ( $lookup as $row ) {
	if ( (int) $row['product_id'] === $focus_id ) {
		$focus['lookup'] = $row;
	}
}

$body = array(
	'product_id' => $focus_id,
	'counts'     => array(
		'posts'  => count( $posts ),
		'meta'   => count( $meta ),
		'lookup' => count( $lookup ),
	),
	'focus'  => $focus,
	'posts'  => $posts,
	'meta'   => $meta,
	'lookup' => $lookup,
);
$canonical = wp_json_encode( wl324_canon( $body ) );
$body['sha256'] = hash( 'sha256', (string) $canonical );
file_put_contents( $out, wp_json_encode( $body ) );
fwrite( STDOUT, $body['sha256'] . "\n" );
