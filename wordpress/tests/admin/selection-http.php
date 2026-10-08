<?php
// #167 extends the existing authenticated HTTP harness; real filter_input/nonce/session transport.
$http_home167 = admin_get( $bulk_url );
$http_form167 = forms_for_action( $http_home167['body'], 'writeleash_free_preview' )[0];
$http_ajax167 = array( 'action' => 'writeleash_free_discovery', 'nonce' => $http_form167['discovery_nonce'], 'term' => $tag, 'page' => '1', 'kind' => 'products' );
$http_found167 = http_request( 'GET', admin_url_abs( '/wp-admin/admin-ajax.php' ), $http_ajax167 );
$http_data167 = json_decode( $http_found167['body'], true );
beq( $http_found167['code'], 200, 'discovery is authenticated real HTTP GET' );
bok( true === $http_data167['success'] && count( $http_data167['data']['results'] ) <= 20, 'HTTP discovery bounded with correct JSON shape' );
$http_bad167 = http_request( 'GET', admin_url_abs( '/wp-admin/admin-ajax.php' ), array_merge( $http_ajax167, array( 'nonce' => 'bad' ) ) );
beq( $http_bad167['code'], 403, 'HTTP invalid discovery nonce refused' );
$http_post167 = http_post( admin_url_abs( '/wp-admin/admin-ajax.php' ), $http_ajax167 );
beq( $http_post167['code'], 403, 'HTTP discovery POST cannot create work' );
$http_inputs167 = array_merge( $http_form167, array( 'product_ids' => array_map( 'strval', array_slice( $ids, 0, 2 ) ), 'selector' => 'ids', 'operation' => 'SET', 'product_search' => $tag, 'selection_action' => 'update-products', 'amount' => 'invalid-amount' ) );
unset( $http_inputs167['product_ids[]'] );
$http_update167 = http_post( $bulk_url, $http_inputs167 );
beq( $http_update167['code'], 200, 'native selection is a read-only same-page POST' );
bok( str_contains( $http_update167['body'], 'value="invalid-amount"' ), 'native POST retains operation input' );
a11y_check( $http_update167['body'], 'native selection' );
unset( $http_inputs167['selection_action'] );
$http_invalid167 = http_post( admin_url_abs( '/wp-admin/admin-post.php' ), $http_inputs167 );
$http_recovery167 = admin_get( (string) $http_invalid167['location'] );
bok( str_contains( $http_recovery167['body'], 'value="invalid-amount"' ) && str_contains( $http_recovery167['body'], 'Remove ' ), 'real preview PRG validation retains bounded session-scoped selection/configuration' );
$http_inputs167['amount'] = '80.00';
$http_preview167 = http_post( admin_url_abs( '/wp-admin/admin-post.php' ), $http_inputs167 );
bok( preg_match( '/wl_view=preview&wl_job=([0-9a-f-]{36})/', (string) $http_preview167['location'], $http_match167 ) === 1, 'picker POST imports explicit IDs into saved preview' );
$http_saved167 = admin_get( (string) $http_preview167['location'] );
bok( str_contains( $http_saved167['body'], '2 planned changes' ), 'two selected IDs, not all discovery matches' );
$http_rows167 = static function ( string $html ): array {
 $dom = new DOMDocument(); libxml_use_internal_errors( true ); $dom->loadHTML( '<?xml encoding="utf-8"?>' . $html ); libxml_clear_errors();
 $rows = array();
 foreach ( ( new DOMXPath( $dom ) )->query( '(//*[@id="wpbody-content"]//table)[1]/tbody/tr' ) as $row ) {
  $cells = array(); foreach ( $row->getElementsByTagName( 'td' ) as $cell ) { $cells[] = trim( $cell->textContent ); } $rows[] = $cells;
 }
 return $rows;
};
$http_original_rows167 = $http_rows167( $http_saved167['body'] );
beq( count( $http_original_rows167 ), 2, 'saved picker preview renders two product rows' );
foreach ( $http_original_rows167 as $row ) {
 beq( $row[1], '$100.00 USD', 'saved expected price after prior Undo' );
 beq( $row[2], '$80.00 USD', 'saved absolute target' );
}
login_session();
$http_recent167 = admin_get( $bulk_url );
bok( str_contains( $http_recent167['body'], 'Continue review' ) && str_contains( $http_recent167['body'], 'wl_view=preview' ), 'fresh authenticated session has continuation link' );
$http_status167 = admin_get( admin_url_abs( '/wp-admin/admin.php?page=writeleash-bulk-prices&wl_view=job&wl_job=' . $http_match167[1] ) );
bok( str_contains( $http_status167['body'], 'Continue review' ), 'direct HTTP status has continuation' );
$http_reopen167 = admin_get( (string) $http_preview167['location'] );
beq( $http_rows167( $http_reopen167['body'] ), $http_original_rows167, 'saved review retains exact original rows and price strings after fresh login' );
$http_products167 = admin_get( admin_url_abs( '/wp-admin/edit.php?post_type=product' ) );
bok( ! str_contains( $http_products167['body'], 'free-selection.js' ) && ! str_contains( $http_products167['body'], 'free-selection.css' ), 'selection assets never enqueued on stock Products screen' );
global $wl111_jar;
$http_jar167 = $wl111_jar; $wl111_jar = array();
$http_anon167 = http_request( 'GET', admin_url_abs( '/wp-admin/admin-ajax.php' ), $http_ajax167 );
bok( 200 !== $http_anon167['code'] && ! str_contains( $http_anon167['body'], '"results"' ), 'anonymous request cannot reuse nonce/discover products' );
$wl111_jar = $http_jar167;
echo "#167 real HTTP discovery/native selection/session recovery/saved-review continuity: PASS\n";

// #206 actual filter_input transport, including no-JavaScript native count.
// eval-file scope: import the database handle like every sibling fixture.
global $wpdb;
$http_root206 = wp_insert_term( $tag . '-206-root', 'product_cat' )['term_id'];
$http_child206 = wp_insert_term( $tag . '-206-child', 'product_cat', array( 'parent' => $http_root206 ) )['term_id'];
wp_set_object_terms( $ids[0], array( $http_root206 ), 'product_cat' );
wp_set_object_terms( $ids[1], array( $http_child206 ), 'product_cat' );
$http_home206 = admin_get( $bulk_url );
$http_form206 = forms_for_action( $http_home206['body'], 'writeleash_free_preview' )[0];
$http_post206 = array_merge( $http_form206, array( 'selector' => 'category', 'category' => (string) $http_root206, 'include_subcategories' => '1', 'selection_action' => 'count-targets', 'amount' => 'invalid-amount' ) );
unset( $http_post206['product_ids[]'] );
$http_jobs206 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WriteLeash\Job_Schema::jobs_table( $wpdb ) );
$http_count206 = http_post( $bulk_url, $http_post206 );
beq( $http_count206['code'], 200, '#206 native count real POST' );
bok( str_contains( $http_count206['body'], 'At last check: 2 deduplicated price targets' ), '#206 HTTP whitelist carries descendant toggle into count' );
bok( str_contains( $http_count206['body'], 'name="include_subcategories" type="checkbox" value="1" checked' ), '#206 toggle retained on no-JS count render' );
beq( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WriteLeash\Job_Schema::jobs_table( $wpdb ) ), $http_jobs206, '#206 HTTP count creates no job' );
a11y_check( $http_count206['body'], '#206 native count' );
unset( $http_post206['selection_action'] );
$http_post206['amount'] = '80.00';
$http_preview206 = http_post( admin_url_abs( '/wp-admin/admin-post.php' ), $http_post206 );
bok( preg_match( '/wl_view=preview&wl_job=([0-9a-f-]{36})/', (string) $http_preview206['location'], $http_match206 ) === 1, '#206 HTTP Preview imports descendant scope' );
$http_job206 = WriteLeash\Job_Repository::read_by_public_id( $http_match206[1] );
$http_plan206 = WriteLeash\Job_Repository::hydrate_plan( $http_job206 );
beq( $http_plan206->data()['resolved_product_ids'], array( $ids[0], $ids[1] ), '#206 HTTP Preview independently freezes both member IDs' );
beq( $http_plan206->data()['selection']['include_children'], true, '#206 HTTP retained hashed category scope' );
bok( str_contains( admin_get( (string) $http_preview206['location'] )['body'], 'direct members and all nested subcategories' ), '#206 HTTP saved review scope visible' );
echo "#206 real HTTP category toggle/count/Preview/no-JS/accessibility: PASS\n";
