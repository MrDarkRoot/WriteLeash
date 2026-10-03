<?php
// Repository-only synthetic shop setup / independent price observer.
// Run via WP-CLI eval-file on a NEW disposable WordPress installation.
// No job/item/plan/journal state writes and no rendering overrides.
$mode = (string) getenv( 'WL122_MODE' );
if ( 'seed' === $mode ) {
    if ( get_option( 'wl122_fixture_ids' ) ) { throw new RuntimeException( 'Fixture already seeded; use a new disposable site.' ); }
    wp_set_current_user( 1 );
    wp_update_user( array( 'ID' => 1, 'display_name' => 'Demo Manager', 'first_name' => 'Demo', 'last_name' => 'Manager' ) );
    update_option( 'woocommerce_currency', 'USD' );
    update_option( 'woocommerce_price_num_decimals', '2' );
    update_option( 'woocommerce_store_address', '' );
    update_option( 'woocommerce_store_city', '' );
    update_option( 'woocommerce_onboarding_profile', array( 'completed' => true, 'skipped' => true ) );
    delete_transient( '_wc_activation_redirect' );
    $names = array( 'Canvas Tote', 'Ceramic Mug', 'Linen Apron', 'Desk Organizer', 'Cotton Throw', 'Oak Serving Board', 'Reading Lamp', 'Woven Basket', 'Wool Blanket', 'Side Table', 'Lounge Chair', 'Writing Desk' );
    $prices = array( '18.00', '24.00', '36.00', '45.00', '60.00', '72.00', '90.00', '110.00', '125.00', '150.00', '180.00', '210.00' );
    $ids = array();
    foreach ( $names as $i => $name ) {
        $p = new WC_Product_Simple();
        $p->set_name( $name );
        $p->set_sku( sprintf( 'DEMO-%02d', $i + 1 ) );
        $p->set_status( 'publish' );
        $p->set_regular_price( $prices[$i] );
        $p->save();
        $ids[] = $p->get_id();
    }
    update_option( 'wl122_fixture_ids', $ids );
    echo wp_json_encode( array( 'ids' => $ids, 'prices' => $prices ) );
} elseif ( 'edit' === $mode ) {
    $ids = get_option( 'wl122_fixture_ids' );
    $p = wc_get_product( $ids[0] );
    if ( '18.00' !== $p->get_regular_price() ) { throw new RuntimeException( 'Unexpected fixture price before later edit.' ); }
    $p->set_regular_price( '21.00' );
    $p->save();
    echo 'Later stored regular price edit: 18.00 -> 21.00';
} elseif ( 'observe' === $mode ) {
    global $wpdb;
    $ids = get_option( 'wl122_fixture_ids' );
    $out = array();
    foreach ( $ids as $id ) {
        clean_post_cache( $id );
        $out[] = array( 'product_id' => $id, 'regular_price' => wc_get_product( $id )->get_regular_price( 'edit' ), 'stored_regular_price' => $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_regular_price'", $id ) ) );
    }
    echo wp_json_encode( $out );
} else { throw new RuntimeException( 'Use WL122_MODE=seed|edit|observe' ); }
