<?php
// #179/#183 variable-product leg over the real Admin HTTP contract: a selected
// variable parent freezes its exact variation set at preview; a variation
// created later never enters that frozen plan; two variations Apply and Undo
// with the parent lookup range refreshed; and one conflicted variation never
// fails its sibling.
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
function vr_report( array $report ): void {
    file_put_contents( '/evidence/' . DB_HOST . '-variations.json', json_encode( $report, JSON_PRETTY_PRINT ) . "\n" );
}
function vr_lookup( int $parent_id ): array {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT min_price,max_price,onsale FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id=%d", $parent_id ), ARRAY_A );
    wl112_assert( is_array( $row ), 'parent lookup row missing' );
    return $row;
}
function vr_assert_range( int $parent_id, string $min, string $max ): void {
    $row = vr_lookup( $parent_id );
    wl112_assert( D::parse( (string) $row['min_price'] ) === D::parse( $min ), 'parent lookup min_price must reflect the applied variations' );
    wl112_assert( D::parse( (string) $row['max_price'] ) === D::parse( $max ), 'parent lookup max_price must reflect the applied variations' );
    wl112_assert( '0' === (string) $row['onsale'], 'a variable parent itself is never onsale' );
}
function vr_preview( array $fields ): array {
    $post = wl112_post( $fields );
    wl112_assert( (bool) preg_match( '/wl_job=([0-9a-f-]{36})/', $post['location'], $m ), 'variation preview refused' );
    $page = wl112_get( $post['location'] );
    return array( 'job' => Repo::read_by_public_id( $m[1] ), 'preview' => $page, 'url' => $post['location'] );
}
function vr_approve( string $preview_url ): string {
    update_option( 'wl112_scheduler_down', true, false );
    $page = wl112_get( $preview_url );
    $approval = wl112_post( wl112_form( $page['body'], A::ACTION_APPROVE ) );
    delete_option( 'wl112_scheduler_down' );
    return $approval['location'];
}
function vr_run_to_terminal( int $job_id, string $preview_url ): string {
    $url = vr_approve( $preview_url );
    for ( $i = 0; $i < 6; ++$i ) {
        $status = Repo::read( $job_id )['status'];
        if ( in_array( $status, array( 'COMPLETED', 'COMPLETED_WITH_ISSUES' ), true ) ) { return $url; }
        wl112_assert( ! in_array( $status, array( 'NEEDS_REVIEW', 'CANCELLED' ), true ), 'variation apply failed' );
        $progress = wl112_get( $url );
        wl112_post( wl112_form( $progress['body'], A::ACTION_RESUME ) );
    }
    throw new RuntimeException( 'variation job did not finish' );
}
function vr_undo_to_terminal( int $job_id, string $url ): array {
    $op = null;
    for ( $i = 0; $i < 6; ++$i ) {
        $op = U::read_operation_by_job( $job_id );
        if ( is_array( $op ) && in_array( $op['status'], array( 'UNDO_COMPLETED', 'UNDO_COMPLETED_WITH_ISSUES' ), true ) ) { return $op; }
        wl112_assert( ! in_array( is_array( $op ) ? $op['status'] : '', array( 'UNDO_NEEDS_REVIEW', 'UNDO_CANCELLED' ), true ), 'variation Undo failed' );
        $page = wl112_get( $url );
        wl112_post( wl112_form( $page['body'], A::ACTION_UNDO ) );
    }
    throw new RuntimeException( 'variation Undo did not finish' );
}
function vr_fields( string $body, array $overrides ): array {
    $fields = wl112_form( $body, A::ACTION_PREVIEW );
    return array_merge( $fields, $overrides );
}
try {
    // A real variable parent declares its variation attributes; Woo only
    // surfaces variation attribute values declared here.
    $parent = new WC_Product_Variable();
    $parent->set_name( 'WL179-variable' );
    $parent->set_status( 'publish' );
    $color = new WC_Product_Attribute();
    $color->set_id( 0 ); $color->set_name( 'Color' ); $color->set_options( array( 'Blue', 'Red', 'Green' ) ); $color->set_position( 0 ); $color->set_visible( true ); $color->set_variation( true );
    $size = new WC_Product_Attribute();
    $size->set_id( 0 ); $size->set_name( 'Size' ); $size->set_options( array( 'M', 'L', 'S' ) ); $size->set_position( 1 ); $size->set_visible( true ); $size->set_variation( true );
    $parent->set_attributes( array( $color, $size ) );
    $parent->save();
    $parent_id = (int) $parent->get_id();
    $first = new WC_Product_Variation();
    $first->set_parent_id( $parent_id ); $first->set_regular_price( '100.00' ); $first->set_attributes( array( 'color' => 'Blue', 'size' => 'M' ) ); $first->save();
    $second = new WC_Product_Variation();
    $second->set_parent_id( $parent_id ); $second->set_regular_price( '50.00' ); $second->set_attributes( array( 'color' => 'Red', 'size' => 'L' ) ); $second->save();
    $first_id = (int) $first->get_id();
    $second_id = (int) $second->get_id();
    $variation_ids = array( $first_id, $second_id );
    sort( $variation_ids, SORT_NUMERIC );
    // The authoritative parent range is certified after WriteLeash's own
    // variation transactions refresh it; a fixture-only variation save is not
    // assumed to have synced the parent lookup.

    wl112_login();
    $base = 'http://127.0.0.1:8080/wp-admin/admin.php?page=writeleash-bulk-prices';
    $home = wl112_get( $base );
    // Parent selection freezes the exact variations, never the parent itself.
    $fields = vr_fields( $home['body'], array( 'selector' => 'ids', 'ids' => (string) $parent_id, 'category' => '', 'picker_present' => '0', 'operation' => O::DECREASE_PERCENT, 'amount' => '20', 'max_products' => (string) S::MAX_JOB_PRODUCTS, 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '10' ) );
    unset( $fields['block_zero'] );
    $run = vr_preview( $fields );
    $plan = Repo::hydrate_plan( $run['job'] );
    wl112_assert( $variation_ids === $plan->data()['resolved_product_ids'], 'a selected variable parent must freeze its exact variation IDs' );
    wl112_assert( $variation_ids === $plan->data()['selection']['ids'], 'the frozen IDS selection holds only the resolved variations' );
    wl112_assert( in_array( $parent_id, $plan->data()['resolved_product_ids'], true ) === false, 'the parent itself is never executable' );
    wl112_assert( 2 === $plan->summary()['selected'] && 2 === $plan->summary()['changing'], 'both variations change 20%' );
    wl112_assert( false !== strpos( $run['preview']['body'], '2 variations' ), 'preview must speak in variations' );
    $report['parent_expansion'] = array( 'requested_parent' => $parent_id, 'resolved_variations' => $variation_ids, 'outcome' => 'PASS' );

    // A variation created after preview must never silently enter the frozen plan.
    $late = new WC_Product_Variation();
    $late->set_parent_id( $parent_id ); $late->set_regular_price( '60.00' ); $late->set_attributes( array( 'color' => 'Green', 'size' => 'S' ) ); $late->save();
    $late_id = (int) $late->get_id();
    $unpreviewed = false;
    try { $plan->item( $late_id ); } catch ( Throwable $error ) { $unpreviewed = true; }
    wl112_assert( $unpreviewed, 'a variation created after preview must stay unexecutable in the frozen plan' );
    $report['late_variation'] = array( 'variation' => $late_id, 'frozen_plan_untouched' => 'PASS' );

    $run_url = vr_run_to_terminal( (int) $run['job']['id'], $run['url'] );
    $counts = Repo::counts( (int) $run['job']['id'] );
    wl112_assert( 'COMPLETED' === Repo::read( (int) $run['job']['id'] )['status'] && 2 === $counts['applied'], 'two variations must apply cleanly' );
    $history = U::history_items( (int) $run['job']['id'], null, null, 0, 50 );
    wl112_assert( 2 === $history['total'], 'the late variation must not be part of the job' );
    wp_cache_flush_runtime();
    V::observe( $plan, $first_id );
    V::observe( $plan, $second_id );
    $late_product = wc_get_product( $late_id );
    wl112_assert( D::parse( $late_product->get_regular_price( 'edit' ) ) === D::parse( '60.00' ), 'the late variation price must be untouched' );
    vr_assert_range( $parent_id, '40.00', '80.00' );
    $report['apply'] = array( 'applied' => 2, 'parent_range' => '40.00-80.00', 'late_variation_untouched' => 'PASS' );

    // Undo the two variations and refresh the parent range back.
    $op = vr_undo_to_terminal( (int) $run['job']['id'], $run_url );
    wl112_assert( 'UNDO_COMPLETED' === $op['status'] && 2 === (int) $op['undone'], 'both variations must restore' );
    wp_cache_flush_runtime();
    $first_product = wc_get_product( $first_id );
    $second_product = wc_get_product( $second_id );
    wl112_assert( D::parse( $first_product->get_regular_price( 'edit' ) ) === D::parse( '100.00' ) && D::parse( $second_product->get_regular_price( 'edit' ) ) === D::parse( '50.00' ), 'variation Undo must restore the original prices' );
    vr_assert_range( $parent_id, '50.00', '100.00' );
    $report['undo'] = array( 'undone' => 2, 'parent_range' => '50.00-100.00', 'outcome' => 'PASS' );

    // One externally edited variation conflicts; its sibling still applies and
    // the refreshed parent range includes the preserved newer price.
    $home = wl112_get( $base );
    $fields = vr_fields( $home['body'], array( 'selector' => 'ids', 'ids' => implode( ',', $variation_ids ), 'category' => '', 'picker_present' => '0', 'operation' => O::DECREASE_PERCENT, 'amount' => '20', 'max_products' => (string) S::MAX_JOB_PRODUCTS, 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '10' ) );
    unset( $fields['block_zero'] );
    $run2 = vr_preview( $fields );
    update_option( 'wl112_scheduler_down', true, false );
    $approval2 = wl112_post( wl112_form( $run2['preview']['body'], A::ACTION_APPROVE ) );
    delete_option( 'wl112_scheduler_down' );
    $run2_url = $approval2['location'];
    V::invalidate( $second_id );
    $external = wc_get_product( $second_id );
    $external->set_regular_price( '200.00' );
    $external->save();
    for ( $i = 0; $i < 6; ++$i ) {
        $status = Repo::read( (int) $run2['job']['id'] )['status'];
        if ( in_array( $status, array( 'COMPLETED', 'COMPLETED_WITH_ISSUES' ), true ) ) { break; }
        wl112_assert( ! in_array( $status, array( 'NEEDS_REVIEW', 'CANCELLED' ), true ), 'conflicted sibling apply failed' );
        $progress = wl112_get( $run2_url );
        wl112_post( wl112_form( $progress['body'], A::ACTION_RESUME ) );
    }
    $counts2 = Repo::counts( (int) $run2['job']['id'] );
    wl112_assert( 'COMPLETED_WITH_ISSUES' === Repo::read( (int) $run2['job']['id'] )['status'] && 1 === $counts2['applied'] && 1 === $counts2['conflict'], 'one conflict must not fail its sibling' );
    wp_cache_flush_runtime();
    $first_after = wc_get_product( $first_id );
    $second_after = wc_get_product( $second_id );
    wl112_assert( D::parse( $first_after->get_regular_price( 'edit' ) ) === D::parse( '80.00' ), 'the sibling variation must still apply' );
    wl112_assert( D::parse( $second_after->get_regular_price( 'edit' ) ) === D::parse( '200.00' ), 'the externally edited variation must be preserved' );
    vr_assert_range( $parent_id, '60.00', '200.00' );
    $report['conflicted_sibling'] = array( 'applied' => 1, 'conflict' => 1, 'parent_range' => '60.00-200.00', 'outcome' => 'PASS' );
    $report['outcome'] = 'PASS'; vr_report( $report );
} catch ( Throwable $error ) {
    $report['outcome'] = 'FAIL'; $report['error'] = $error->getMessage(); vr_report( $report ); throw $error;
}
