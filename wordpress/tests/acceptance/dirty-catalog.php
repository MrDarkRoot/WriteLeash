<?php
// #181/#183 dirty-catalog leg: realistic legacy storage corruption must not
// stop clean siblings. Products with a malformed stored regular/sale price or
// an unreadable Woo read are skipped at preview with typed reasons. Duplicate
// `_regular_price` rows and a missing `_price` are only visible at the write
// boundary and become typed needs-attention outcomes with zero overwrite.
require __DIR__ . '/http.php';
use WriteLeash\Free_Admin as A;
use WriteLeash\Free_Support_Contract as S;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Price_Cache_Verifier as V;
use WriteLeash\Price_Decimal as D;
use WriteLeash\Price_Operation as O;
use WriteLeash\Undo_Repository as U;

global $wpdb, $wl112_cookies;
$wl112_cookies = array();
wp_set_current_user( 1 );
$report = array( 'git_sha' => getenv( 'WL112_SHA' ), 'wp' => get_bloginfo( 'version' ), 'woo' => WC_VERSION, 'php' => PHP_VERSION, 'db' => $wpdb->get_var( 'SELECT VERSION()' ), 'cache' => wp_using_ext_object_cache() ? 'persistent' : 'default' );
function dc_report( array $report ): void {
    file_put_contents( '/evidence/' . DB_HOST . '-dirty-catalog.json', json_encode( $report, JSON_PRETTY_PRINT ) . "\n" );
}
function dc_product( string $name, string $regular, string $sale = '' ): int {
    $p = new WC_Product_Simple();
    $p->set_name( $name ); $p->set_status( 'publish' ); $p->set_regular_price( $regular );
    if ( '' !== $sale ) { $p->set_sale_price( $sale ); }
    $p->save();
    return (int) $p->get_id();
}
function dc_meta_count( int $id, string $key ): int {
    return count( get_post_meta( $id, $key, false ) );
}
try {
    $clean = dc_product( 'WL181-clean', '100.00' );
    $bad_regular = dc_product( 'WL181-bad-regular', '100.00' );
    $bad_sale = dc_product( 'WL181-bad-sale', '100.00' );
    $dup_regular = dc_product( 'WL181-dup-regular', '100.00' );
    $missing_price = dc_product( 'WL181-missing-price', '100.00' );
    $unreadable = dc_product( 'WL181-unreadable', '100.00' );
    // Legacy corruption is written through the public metadata API only.
    update_post_meta( $bad_regular, '_regular_price', 'not-a-price' );
    V::invalidate( $bad_regular );
    update_post_meta( $bad_sale, '_sale_price', 'not-a-price' );
    V::invalidate( $bad_sale );
    add_post_meta( $dup_regular, '_regular_price', '90.00' );
    V::invalidate( $dup_regular );
    delete_post_meta( $missing_price, '_price' );
    V::invalidate( $missing_price );
    wl112_assert( 2 === dc_meta_count( $dup_regular, '_regular_price' ), 'duplicate meta fixture' );
    wl112_assert( 0 === dc_meta_count( $missing_price, '_price' ), 'missing _price fixture' );
    V::invalidate( $unreadable );
    update_option( 'wl112_unreadable_product', $unreadable, false );

    wl112_login();
    $base = 'http://127.0.0.1:8080/wp-admin/admin.php?page=writeleash-bulk-prices';
    $home = wl112_get( $base );
    $fields = wl112_form( $home['body'], A::ACTION_PREVIEW );
    $fields = array_merge( $fields, array( 'selector' => 'ids', 'ids' => implode( ',', array( $clean, $bad_regular, $bad_sale, $dup_regular, $missing_price, $unreadable ) ), 'category' => '', 'picker_present' => '0', 'operation' => O::SET, 'amount' => '80', 'max_products' => (string) S::MAX_JOB_PRODUCTS, 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '10' ) );
    unset( $fields['block_zero'] );
    $post = wl112_post( $fields );
    wl112_assert( (bool) preg_match( '/wl_job=([0-9a-f-]{36})/', $post['location'], $m ), 'dirty preview refused' );
    $job = Repo::read_by_public_id( $m[1] );
    $plan = Repo::hydrate_plan( $job );
    $preview = wl112_get( $post['location'] );
    wl112_assert( 6 === $plan->summary()['selected'] && 3 === $plan->summary()['changing'] && 3 === $plan->summary()['unsupported'], 'dirty catalog selection must stay complete' );
    wl112_assert( false !== strpos( $preview['body'], 'Selected 6 products: 3 planned changes · 0 already at the target price · 3 skipped at preview.' ), 'dirty preview summary' );
    wl112_assert( 'CHANGING' === $plan->item( $clean )->data()['result'], 'the clean sibling still plans' );
    wl112_assert( 'invalid_price' === $plan->item( $bad_regular )->data()['eligibility']['reason'], 'malformed regular price typed at preview' );
    wl112_assert( 'invalid_price' === $plan->item( $bad_sale )->data()['eligibility']['reason'], 'malformed sale price typed at preview' );
    wl112_assert( null === $plan->item( $bad_regular )->data()['planned_regular_price'], 'malformed price is never guessed' );
    wl112_assert( 'unreadable_product_data' === $plan->item( $unreadable )->data()['eligibility']['reason'], 'unreadable Woo read typed at preview' );
    wl112_assert( false === $plan->item( $unreadable )->data()['snapshot']['exists'] && true === $plan->item( $unreadable )->data()['snapshot']['unreadable'], 'unreadable snapshot keeps explicit unknown state' );
    wl112_assert( 'CHANGING' === $plan->item( $dup_regular )->data()['result'] && 'CHANGING' === $plan->item( $missing_price )->data()['result'], 'write-boundary corruption is only visible at apply' );
    $report['preview'] = 'PASS: 3 clean/plan-visible products planned; malformed regular, malformed sale and unreadable read skipped with typed reasons';

    update_option( 'wl112_scheduler_down', true, false );
    $approval = wl112_post( wl112_form( $preview['body'], A::ACTION_APPROVE ) );
    delete_option( 'wl112_scheduler_down' );
    $url = $approval['location'];
    $progress = wl112_get( $url );
    for ( $i = 0; $i < 6; ++$i ) {
        $status = Repo::read( (int) $job['id'] )['status'];
        if ( in_array( $status, array( 'COMPLETED', 'COMPLETED_WITH_ISSUES' ), true ) ) { break; }
        wl112_assert( ! in_array( $status, array( 'NEEDS_REVIEW', 'CANCELLED' ), true ), 'dirty-catalog apply failed unexpectedly' );
        $progress = wl112_get( $url );
        wl112_post( wl112_form( $progress['body'], A::ACTION_RESUME ) );
    }
    $counts = Repo::counts( (int) $job['id'] );
    wl112_assert( 'COMPLETED_WITH_ISSUES' === Repo::read( (int) $job['id'] )['status'], 'dirty outcomes must end in a truthful mixed state' );
    wl112_assert( 1 === $counts['applied'] && 2 === $counts['failed'] && 3 === $counts['unsupported'] && 0 === $counts['pending'] && 0 === $counts['conflict'], 'dirty apply counts' );
    $spine = ''; foreach ( U::history_items( (int) $job['id'], null, null, 0, 50 )['items'] as $item ) { $spine .= $item['product_id'] . ':' . $item['apply_state'] . ':' . $item['apply_reason'] . "\n"; }
    wl112_assert( false !== strpos( $spine, $clean . ':APPLIED:WOO_CRUD_VERIFIED' ), 'clean sibling must apply' );
    wl112_assert( false !== strpos( $spine, $dup_regular . ':FAILED:UNSUPPORTED_PRODUCT_STATE' ), 'duplicate meta must need attention, not overwrite' );
    wl112_assert( false !== strpos( $spine, $missing_price . ':FAILED:UNSUPPORTED_PRODUCT_STATE' ), 'missing _price must need attention, not overwrite' );
    $progress = wl112_get( $url );
    wl112_assert( false !== strpos( $progress['body'], 'Finished with products needing attention' ), 'mixed dirty state label' );
    wl112_assert( false !== strpos( $progress['body'], 'missing, duplicated, or malformed' ), 'dirty reason copy must explain the saved-price damage' );

    wp_cache_flush_runtime();
    wl112_assert( D::parse( get_post_meta( $clean, '_regular_price', true ) ) === D::parse( '80.00' ), 'clean product changed' );
    wl112_assert( 'not-a-price' === get_post_meta( $bad_regular, '_regular_price', true ), 'malformed regular price must not be overwritten' );
    wl112_assert( 'not-a-price' === get_post_meta( $bad_sale, '_sale_price', true ), 'malformed sale price must not be overwritten' );
    $dup_rows = get_post_meta( $dup_regular, '_regular_price', false );
    wl112_assert( 2 === count( $dup_rows ) && '100.00' === $dup_rows[0] && '90.00' === $dup_rows[1], 'duplicate meta rows must be preserved' );
    wl112_assert( 0 === dc_meta_count( $missing_price, '_price' ), 'missing _price must stay missing' );
    // The unreadable probe throws for wc_get_product() while armed; read the raw
    // committed meta through the public metadata API instead.
    wl112_assert( D::parse( get_post_meta( $unreadable, '_regular_price', true ) ) === D::parse( '100.00' ), 'unreadable product must be untouched' );
    $report['apply'] = 'PASS: clean applied at 80; duplicate-meta and missing-_price products failed closed with typed reasons and zero overwrite';

    $op = null;
    for ( $i = 0; $i < 4; ++$i ) {
        $op = U::read_operation_by_job( (int) $job['id'] );
        if ( is_array( $op ) && WriteLeash\Undo_State::is_terminal( $op['status'] ) ) { break; }
        $progress = wl112_get( $url );
        wl112_post( wl112_form( $progress['body'], A::ACTION_UNDO ) );
    }
    $op = U::read_operation_by_job( (int) $job['id'] );
    wl112_assert( 'UNDO_COMPLETED' === $op['status'] && 1 === (int) $op['undone'] && 0 === (int) $op['undo_conflict'], 'only the confirmed clean change may restore' );
    wp_cache_flush_runtime();
    wl112_assert( D::parse( get_post_meta( $clean, '_regular_price', true ) ) === D::parse( '100.00' ), 'clean product must restore' );
    wl112_assert( 'not-a-price' === get_post_meta( $bad_regular, '_regular_price', true ) && 0 === dc_meta_count( $missing_price, '_price' ), 'Undo must not touch dirty products' );
    $report['undo'] = 'PASS: only the clean applied product restored; dirty products untouched';
    $report['outcome'] = 'PASS'; dc_report( $report );
} catch ( Throwable $error ) {
    $report['outcome'] = 'FAIL'; $report['error'] = $error->getMessage(); dc_report( $report ); throw $error;
} finally {
    delete_option( 'wl112_unreadable_product' );
}
