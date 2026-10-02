<?php
// First-use refusal with real DB CREATE denial. Root is an operator-only
// disposable lab controller; it is not in wp-config or product requests.
require __DIR__ . '/http.php';
global $wl112_cookies;
$wl112_cookies = array();
wp_set_current_user( 1 );
$p = new WC_Product_Simple();
$p->set_name( 'WL112-no-create' ); $p->set_status( 'publish' ); $p->set_regular_price( '100.00' ); $p->save();
delete_transient( '_wc_activation_redirect' );
wl112_login();
$base = 'http://127.0.0.1:8080/wp-admin/admin.php?page=writeleash-bulk-prices';
$home = wl112_get( $base );
$form = wl112_form( $home['body'], 'writeleash_free_preview' );
$form = array_merge( $form, array( 'selector' => 'ids', 'ids' => (string) $p->get_id(), 'operation' => 'SET', 'amount' => '80', 'max_products' => '100', 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '10' ) );
unset( $form['block_zero'] );
$root = new mysqli( DB_HOST, 'root', 'disposable_root_password', DB_NAME );
try {
    if ( ! $root->query( "REVOKE CREATE ON wp_test.* FROM 'wp_test'@'%'" ) ) { throw new RuntimeException( 'fixture revoke failed' ); }
    $post = wl112_post( $form );
    wl112_assert( false === strpos( $post['location'], 'wl_job=' ), 'KILL: no-CREATE silently degraded' );
    $page = wl112_get( $post['location'] );
    wl112_assert( false !== strpos( $page['body'], 'INVALID' ), 'no-CREATE not explicit' );
    $db = WriteLeash\Price_Cache_Verifier::observer();
    try { WriteLeash\Price_Cache_Verifier::matches( WriteLeash\Price_Cache_Verifier::storage( $db, $p->get_id() ), '100' ); }
    finally { $db->close(); }
    file_put_contents( '/evidence/' . DB_HOST . '-no-create.json', json_encode( array( 'git_sha' => getenv( 'WL112_SHA' ), 'php' => PHP_VERSION, 'wp' => get_bloginfo( 'version' ), 'woo' => WC_VERSION, 'outcome' => 'PASS', 'result' => 'Real CREATE denied: explicit Admin INVALID, no job, regular/active/lookup unchanged at 100; no degraded mutation path' ), JSON_PRETTY_PRINT ) . "\n" );
} finally {
    $root->query( "GRANT CREATE ON wp_test.* TO 'wp_test'@'%'" );
    $root->close();
}
