<?php
// #168 real export transport: attachment, POST/nonce and actor boundaries.
$export_page168 = admin_get( $job_url );
$export_forms168 = forms_for_action( $export_page168['body'], 'writeleash_free_export' );
beq( count( $export_forms168 ), 1, 'one job export action' );
$export_http168 = http_post( admin_url_abs( '/wp-admin/admin-post.php' ), $export_forms168[0] );
beq( $export_http168['code'], 200, 'authorized CSV HTTP response' );
bok( str_contains( $export_http168['content_type'], 'text/csv' ), 'CSV MIME type' );
bok( str_contains( $export_http168['content_disposition'], 'attachment; filename="writeleash-' . $public_id . '.csv"' ), 'CSV exact authorized job attachment' );
bok( str_contains( $export_http168['body'], 'UNDONE' ) && str_contains( $export_http168['body'], 'UNDO_RESTORED' ), 'CSV includes saved Undo outcome' );
$export_get168 = http_request( 'GET', admin_url_abs( '/wp-admin/admin-post.php' ), $export_forms168[0] );
beq( $export_get168['code'], 400, 'CSV GET transport refused' );
$export_bad168 = http_post( admin_url_abs( '/wp-admin/admin-post.php' ), array_merge( $export_forms168[0], array( '_wpnonce' => 'bad' ) ) );
beq( $export_bad168['code'], 400, 'CSV bad nonce transport refused' );
bok( ! str_contains( $export_bad168['content_type'], 'text/csv' ), 'CSV refusal is not a download' );

// A second real cookie session cannot export the first operator's job, even
// though it has normal Woo capabilities and a nonce for its own export.
wp_set_current_user( 1 );
$other_name168 = 'wl168-http-' . wp_generate_uuid4();
$other_password168 = wp_generate_password( 32, false );
$other_id168 = wp_insert_user( array( 'user_login' => $other_name168, 'user_pass' => $other_password168, 'role' => 'shop_manager' ) );
$owned_product168 = new WC_Product_Simple(); $owned_product168->set_name( 'HTTP CSV owned Café' ); $owned_product168->set_status( 'publish' ); $owned_product168->set_regular_price( '18.00' ); $owned_product168->save();
global $wl111_jar;
$wl111_jar = array();
http_get( admin_url_abs( '/wp-login.php' ) );
http_post( admin_url_abs( '/wp-login.php' ), array( 'log' => $other_name168, 'pwd' => $other_password168, 'wp-submit' => 'Log In', 'testcookie' => '1' ) );
$other_home168 = admin_get( $bulk_url );
$other_preview_form168 = forms_for_action( $other_home168['body'], 'writeleash_free_preview' )[0];
$other_preview168 = http_post( admin_url_abs( '/wp-admin/admin-post.php' ), array_merge( $other_preview_form168, array( 'selector' => 'ids', 'ids' => (string) $owned_product168->get_id(), 'operation' => 'SET', 'amount' => '14.40', 'max_products' => '100', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '100', 'picker_present' => '0' ) ) );
bok( preg_match( '/wl_job=([0-9a-f-]{36})/', $other_preview168['location'], $other_match168 ) === 1, 'other Manager creates own job through HTTP' );
$other_job168 = admin_get( admin_url_abs( '/wp-admin/admin.php?page=writeleash-bulk-prices&wl_view=job&wl_job=' . $other_match168[1] ) );
$other_export_form168 = forms_for_action( $other_job168['body'], 'writeleash_free_export' )[0];
$other_csv168 = http_post( admin_url_abs( '/wp-admin/admin-post.php' ), $other_export_form168 );
beq( $other_csv168['code'], 200, 'second Manager can export own readable job' );
bok( str_contains( $other_csv168['body'], 'HTTP CSV owned Café' ), 'second Manager CSV contains own saved identity' );
$foreign_csv168 = http_post( admin_url_abs( '/wp-admin/admin-post.php' ), array_merge( $other_export_form168, array( 'job' => $public_id ) ) );
beq( $foreign_csv168['code'], 403, 'second Manager cannot export first operators job' );
bok( ! str_contains( $foreign_csv168['body'], $tag ) && ! str_contains( $foreign_csv168['content_type'], 'text/csv' ), 'forbidden CSV contains no job product identity' );
$foreign_view168 = admin_get( $job_url );
bok( str_contains( $foreign_view168['body'], 'No job is visible' ), 'second Manager cannot read first job' );
$wl111_jar = array();
$anonymous_csv168 = http_post( admin_url_abs( '/wp-admin/admin-post.php' ), $export_forms168[0] );
bok( ! str_contains( $anonymous_csv168['content_type'], 'text/csv' ) && ! str_contains( $anonymous_csv168['body'], $tag ), 'anonymous CSV download denied' );
login_session();
echo "#168 real HTTP per-job CSV attachment, POST/nonce, other-Manager and anonymous controls: PASS\n";
