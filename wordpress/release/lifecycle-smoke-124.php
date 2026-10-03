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
if ( 'before' === $phase ) {
    $fixture['baseline_product_meta'] = get_post_meta( $fixture['product_id'] );
    $fixture['baseline_job'] = $job;
    update_option( 'artifact124_fixture', $fixture, false );
} else {
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
