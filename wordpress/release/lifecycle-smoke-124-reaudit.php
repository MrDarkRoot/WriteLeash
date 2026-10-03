<?php
// Repository-only lifecycle observer. Reads fixture state; never writes product SQL.
$check = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
global $wpdb;
$fixture = get_option( 'artifact124_fixture' );
$check( is_array( $fixture ), 'missing fixture' );
$product = wc_get_product( $fixture['product_id'] );
$check( $product && '21.00' === $product->get_regular_price( 'edit' ) && 'publish' === $product->get_status(), 'product loss or price mutation' );
$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $wpdb->prefix . 'writeleash_jobs', $fixture['job_id'] ), ARRAY_A );
$check( $job && 'PLANNED' === $job['status'], 'durable job lost' );
$state = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, 'writeleash_runner_state' ) );
$phase = $args[0] ?? 'before';
$all_products = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') ORDER BY ID", ARRAY_A );
$product_ids = array_map( static fn( $row ) => (int) $row['ID'], $all_products );
$product_data = array();
foreach ( $product_ids as $product_id ) {
    $product_data[ $product_id ] = array(
        'post' => get_post( $product_id, ARRAY_A ),
        'meta' => get_post_meta( $product_id ),
        'lookup' => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE product_id=%d', $wpdb->wc_product_meta_lookup, $product_id ), ARRAY_A ),
        'terms' => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE object_id=%d ORDER BY term_taxonomy_id', $wpdb->term_relationships, $product_id ), ARRAY_A ),
    );
}
$durable_data = array();
$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'writeleash_' ) . '%' ) );
foreach ( $tables as $table ) { $durable_data[ $table ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A ); }

if ( 'before' === $phase ) {
    $fixture['baseline_product_meta'] = get_post_meta( $fixture['product_id'] );
    $fixture['baseline_job'] = $job;
    $fixture['all_product_data'] = $product_data;
    $fixture['all_durable_data'] = $durable_data;
    update_option( 'artifact124_fixture', $fixture, false );
} else {
    $check( $fixture['all_product_data'] === $product_data, 'all Woo posts/meta/lookup/terms changed' );
    $check( $fixture['all_durable_data'] === $durable_data, 'durable evidence drift' );
    $check( $fixture['baseline_product_meta'] === get_post_meta( $fixture['product_id'] ), 'product meta changed' );
    $check( $fixture['baseline_job'] === $job, 'job evidence changed' );
    $check( 'pending' === ActionScheduler::store()->get_status( $fixture['unrelated_action_id'] ), 'unrelated action cleaned' );
    $check( 'canceled' === ActionScheduler::store()->get_status( $fixture['owned_action_id'] ), 'owned action not cleaned' );
}
if ( in_array( $phase, array( 'before', 'reactivated' ), true ) ) { $check( 'active' === $state, 'runner inactive' ); }
if ( 'deactivated' === $phase ) { $check( 'deactivated' === $state, 'runner not inactive' ); }
if ( 'uninstalled' === $phase ) {
    $check( null === $state, 'runner option retained' );
    $check( ! is_dir( WP_PLUGIN_DIR . '/writeleash' ), 'plugin directory retained' );
}
echo 'Lifecycle ' . $phase . ': product/meta/job preserved; runner state and owned-only cleanup PASS' . "\n";
