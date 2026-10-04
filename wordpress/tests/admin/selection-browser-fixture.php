<?php
// #167 disposable browser fixture/independent observer. Never use on a store.
$mode = (string) getenv( 'WL167_MODE' );
$file = (string) getenv( 'WL167_FIXTURE' );
if ( ! $file || ! in_array( $mode, array( 'seed', 'observe', 'edit' ), true ) ) { throw new RuntimeException( 'Explicit #167 fixture file/mode required.' ); }
if ( 'seed' === $mode ) {
 wp_set_current_user( 1 );
 delete_transient( '_wc_activation_redirect' ); // Fixture onboarding is not the merchant journey.
 $password = wp_generate_password( 32, false );
 $manager = wp_insert_user( array( 'user_login' => 'wl167_browser_manager', 'user_pass' => $password, 'role' => 'shop_manager' ) );
 if ( is_wp_error( $manager ) ) { throw new RuntimeException( 'Use a fresh #167 fixture.' ); }
 $parent = wp_insert_term( 'WL167 Browser Collection', 'product_cat' );
 $child = wp_insert_term( 'WL167 Browser Collection', 'product_cat', array( 'parent' => $parent['term_id'] ) );
 $products = array();
 foreach ( array( array( 'WL167 Browser Café <script>alert("167")</script>', 'WL167-SEARCH-ONE', $parent['term_id'] ), array( 'WL167 Browser Café <script>alert("167")</script>', '', $child['term_id'] ), array( 'WL167 Browser Long ' . str_repeat( 'Long ', 30 ), 'WL167-SEARCH-TWO', $parent['term_id'] ) ) as $values ) {
  $product = new WC_Product_Simple(); $product->set_name( $values[0] ); $product->set_sku( $values[1] ); $product->set_status( 'publish' ); $product->set_regular_price( '100.00' ); $product->set_category_ids( array( $values[2] ) ); $product->save(); $products[] = $product->get_id();
 }
 $fixture = array( 'username' => 'wl167_browser_manager', 'password' => $password, 'manager' => $manager, 'products' => $products, 'parent' => $parent['term_id'], 'child' => $child['term_id'] );
 file_put_contents( $file, wp_json_encode( $fixture ) ); chmod( $file, 0600 );
 update_option( 'wl167_browser_saves', 0, false );
 $mu = ABSPATH . 'wp-content/mu-plugins'; if ( ! is_dir( $mu ) ) { mkdir( $mu ); }
 file_put_contents( $mu . '/wl167-observer.php', '<?php add_action("woocommerce_before_product_object_save", static function () { update_option("wl167_browser_saves", (int) get_option("wl167_browser_saves", 0) + 1, false); }); add_filter("action_scheduler_allow_async_request_runner", "__return_false");' );
 echo "#167 browser fixture seeded\n";
} else {
 $fixture = json_decode( file_get_contents( $file ), true );
 if ( 'edit' === $mode ) { $product = wc_get_product( $fixture['products'][0] ); $product->set_regular_price( '120.00' ); $product->save(); }
 global $wpdb;
 $prices = array();
 foreach ( $fixture['products'] as $id ) {
  WriteLeash\Price_Cache_Verifier::invalidate( $id );
  $prices[$id] = array( 'woo' => wc_get_product( $id )->get_regular_price( 'edit' ), 'stored' => $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM %i WHERE post_id=%d AND meta_key='_regular_price'", $wpdb->postmeta, $id ) ) );
 }
 $jobs = $wpdb->get_results( $wpdb->prepare( 'SELECT public_id,plan_id,plan_hash,plan_json,status FROM %i WHERE creator_id=%d ORDER BY id DESC', WriteLeash\Job_Schema::jobs_table( $wpdb ), $fixture['manager'] ), ARRAY_A );
 echo wp_json_encode( array( 'prices' => $prices, 'jobs' => $jobs, 'saves' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, 'wl167_browser_saves' ) ) ) );
}
