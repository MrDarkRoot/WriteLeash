<?php
// Fresh process; works with WriteLeash absent after uninstall, using only WP/Woo.
global $wpdb;
$products = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') ORDER BY ID", ARRAY_A );
$state = array();
foreach ( $products as $row ) {
    $id = (int) $row['ID'];
    $meta = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id=%d ORDER BY meta_id', $wpdb->postmeta, $id ), ARRAY_A );
    $lookup = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE product_id=%d', $wpdb->prefix . 'wc_product_meta_lookup', $id ), ARRAY_A );
    $terms = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE object_id=%d ORDER BY term_taxonomy_id', $wpdb->term_relationships, $id ), ARRAY_A );
    $p = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
    $regular = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id=%d AND meta_key=%s LIMIT 1', $wpdb->postmeta, $id, '_regular_price' ) );
    if ( function_exists( 'wc_get_product' ) && ( ! $p || (string) $p->get_regular_price( 'edit' ) !== (string) $regular ) ) { throw new RuntimeException( 'KILL: lifecycle Woo/storage price divergence' ); }
    $state[$id] = array( $row, $meta, $lookup, $terms );
}
$durable = array();
$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'writeleash_' ) . '%' ) );
foreach ( $tables as $table ) { $durable[$table] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A ); }
$fixture = get_option( 'wl125_lifecycle' );
$actions = array();
if ( $fixture ) { foreach ( $fixture['actions'] as $name => $id ) { $actions[$name] = ActionScheduler::store()->get_status( $id ); } }
$runner = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, 'writeleash_runner_state' ) );
echo json_encode( array( 'woo_readback' => function_exists( 'wc_get_product' ) ? 'PASS' : 'REFUSED: Woo inactive', 'products' => count( $state ), 'product_tree_hash' => hash( 'sha256', serialize( $state ) ), 'durable_tree_hash' => hash( 'sha256', serialize( $durable ) ), 'durable_row_counts' => array_map( 'count', $durable ), 'runner' => $runner, 'actions' => $actions ) );
