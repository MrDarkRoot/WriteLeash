<?php
// #119/#177 repair: real HTTP refusal plus direct typed-controller assertions.
// The Free supported ceiling now equals the engineering selector maximum.
require __DIR__ . '/http.php';
use WriteLeash\Free_Admin as A;
use WriteLeash\Free_Support_Contract as S;
use WriteLeash\Job_Repository as R;
use WriteLeash\Price_Cache_Verifier as V;

global $wpdb, $wl112_cookies;
$wl112_cookies = array();
wp_set_current_user( 1 );
$report = array( 'git_sha' => getenv( 'WL112_SHA' ), 'wp' => get_bloginfo( 'version' ), 'woo' => WC_VERSION, 'php' => PHP_VERSION, 'db' => $wpdb->get_var( 'SELECT VERSION()' ), 'cache' => wp_using_ext_object_cache() ? 'persistent' : 'default', 'engineering_selector_max' => WriteLeash\Product_Price_Selector::MAX_SELECTED, 'free_admin_max' => S::MAX_JOB_PRODUCTS );
function sb_save( array $report ): void {
    file_put_contents( '/evidence/' . DB_HOST . '-support-boundaries.json', json_encode( $report, JSON_PRETTY_PRINT ) . "\n" );
}
try {
    wl112_assert( 1000 === $report['engineering_selector_max'] && 1000 === $report['free_admin_max'], 'Free ceiling must match the engineering selector maximum' );
    $term = wp_insert_term( 'WL119-' . wp_generate_uuid4(), 'product_cat' );
    wl112_assert( ! is_wp_error( $term ), 'boundary fixture category' );
    $ids = array();
    for ( $i = 0; $i < 1001; ++$i ) {
        $p = new WC_Product_Simple(); $p->set_name( 'WL119-' . $i ); $p->set_status( 'publish' ); $p->set_regular_price( 0 === $i ? '100.00' : '80.00' ); $p->set_category_ids( array( (int) $term['term_id'] ) ); $p->save(); $ids[] = $p->get_id();
    }
    wl112_login();
    $base = 'http://127.0.0.1:8080/wp-admin/admin.php?page=writeleash-bulk-prices';
    $home = wl112_get( $base );
    $fields = wl112_form( $home['body'], A::ACTION_PREVIEW );
    wl112_assert( '1000' === $fields['max_products'], 'UI default must be the supported maximum' );
    wl112_assert( (bool) preg_match( '/name="max_products"[^>]*max="1000"/', $home['body'] ), 'UI maximum must be 1000' );
    $fields = array_merge( $fields, array( 'picker_present' => '0', 'selector' => 'ids', 'ids' => implode( ',', $ids ), 'operation' => 'SET', 'amount' => '80', 'max_products' => '1000', 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '10' ) );
    unset( $fields['block_zero'] );
    foreach ( array( 'ids', 'category' ) as $selector ) {
        $post = $fields; $post['selector'] = $selector; $post['category'] = (string) $term['term_id'];
        $post['_wpnonce'] = wl112_nonce( A::ACTION_PREVIEW );
        $before = wl112_evidence_rows();
        $typed = A::process_preview( $post, 'POST' );
        wl112_assert( 'supported_job_limit_exceeded' === $typed['reason'], '1001 typed refusal ' . $selector );
        if ( 'ids' === $selector ) { wl112_assert( 1001 === $typed['selected_count'], '1001 explicit typed selected count' ); }
        $response = wl112_post( $post );
        $page = wl112_get( $response['location'] );
        wl112_assert( false === strpos( $response['location'], 'wl_job=' ), '1001 created a job ' . $selector );
        if ( 'ids' === $selector ) { wl112_assert( false !== strpos( $page['body'], '1001 products were selected.' ), '1001 visible refusal ' . $selector ); }
        else { wl112_assert( false !== strpos( $page['body'], 'supports up to 1000 products per job' ), '1001 visible refusal ' . $selector ); }
        wl112_assert( $before === wl112_evidence_rows(), '1001 created job/journal ' . $selector );
        $report['1001_' . $selector] = array( 'reason' => $typed['reason'], 'between' => $before, 'after' => wl112_evidence_rows() );
    }
    // A lower safety policy cannot make a 1001-selected request a supported job.
    $post['max_products'] = '0';
    wl112_assert( 'supported_job_limit_exceeded' === A::process_preview( $post, 'POST' )['reason'], 'support cap must be independent of lower safety policy' );
    $policy = $fields; $policy['ids'] = (string) $ids[0]; $policy['max_products'] = '1001';
    $before = wl112_evidence_rows();
    wl112_assert( 'invalid_product_limit' === A::process_preview( $policy, 'POST' )['reason'], 'max_products=1001 rejected server-side' );
    $response = wl112_post( $policy ); wl112_get( $response['location'] );
    wl112_assert( $before === wl112_evidence_rows(), 'invalid policy created evidence' );
    $policy['max_products'] = '1000';
    wl112_assert( 1000 === A::build_policy( $policy )->data()['max_products_changed'], 'max_products=1000 valid' );
    $report['safety_policy'] = 'PASS: 1001 rejected, 1000 valid, lower policy cannot raise the selection boundary';

    // At-ceiling explicit preview through the real Admin path: 1000 items freeze
    // exactly, and approval must consume the frozen population, never a stale counter.
    $fields['ids'] = implode( ',', array_slice( $ids, 0, 1000 ) );
    $fields['_wpnonce'] = wl112_nonce( A::ACTION_PREVIEW );
    $response = wl112_post( $fields );
    wl112_assert( (bool) preg_match( '/wl_job=([0-9a-f-]{36})/', $response['location'], $match ), '1000 explicit preview failed' );
    $page = wl112_get( $response['location'] );
    $job = R::read_by_public_id( $match[1] );
    wl112_assert( 1000 === R::hydrate_plan( $job )->summary()['selected'], '1000 explicit frozen count' );
    $wpdb->query( $wpdb->prepare( 'UPDATE %i SET total_selected=1 WHERE id=%d', WriteLeash\Job_Schema::jobs_table( $wpdb ), (int) $job['id'] ) );
    update_option( 'wl112_scheduler_down', true, false );
    $before = wl112_evidence_rows();
    wl112_post( wl112_form( $page['body'], A::ACTION_APPROVE ) );
    delete_option( 'wl112_scheduler_down' );
    $after = wl112_evidence_rows();
    wl112_assert( 1 === $after['writeleash_price_items'] - $before['writeleash_price_items'], '1000 explicit approval must seed only its single frozen changing item' );
    wl112_assert( 1000 === R::hydrate_plan( R::read( (int) $job['id'] ) )->summary()['selected'], 'approval never trusts the tampered total_selected counter' );
    wl112_assert( 1000 === WriteLeash\Undo_Repository::history_items( (int) $job['id'], null, null, 0, 50 )['total'], '1000 frozen items remain addressable' );
    $report['1000_explicit'] = 'PASS: 1000-item preview/approval used the frozen population; stale counter ignored';

    // Unapproved at-ceiling PLANNED/BLOCKED jobs expose no recovery or Undo
    // action, and crafted authenticated mutations still execute nothing.
    $selection = WriteLeash\Price_Selection_Spec::ids( array_slice( $ids, 0, 1000 ) );
    $operation = new WriteLeash\Price_Operation( 'SET', '80' );
    $planned = R::create_from_plan( WriteLeash\Woo_Price_Planner::preview( $selection, $operation, new WriteLeash\Safety_Policy( 1000, '50', '50', false, '10' ) ), 1 );
    $blocked = R::create_from_plan( WriteLeash\Woo_Price_Planner::preview( $selection, $operation, new WriteLeash\Safety_Policy( 0, '50', '50', false, '10' ) ), 1 );
    foreach ( array( $planned, $blocked ) as $unapproved ) {
        $before = wl112_evidence_rows();
        $job_url = $base . '&wl_view=job&wl_job=' . $unapproved['public_id'];
        $page = wl112_get( $job_url );
        wl112_assert( false === strpos( $page['body'], 'Legacy oversized job' ), 'at-ceiling unapproved job cannot be called grandfathered' );
        foreach ( array( A::ACTION_RESUME, A::ACTION_UNDO ) as $action ) {
            wl112_assert( false === strpos( $page['body'], 'value="' . $action . '"' ), 'unapproved at-ceiling job exposes recovery mutation' );
            $response = wl112_post( array( 'action' => $action, 'job' => $unapproved['public_id'], '_wpnonce' => wl112_nonce( $action . '_' . $unapproved['public_id'] ) ) );
            wl112_get( $response['location'] );
        }
        wl112_assert( $before === wl112_evidence_rows() && 0 === R::counts( (int) $unapproved['id'] )['applied'], 'unapproved at-ceiling job seeded/executed/recovered' );
    }
    $report['unapproved_ceiling_states'] = 'PASS: PLANNED/BLOCKED 1000-item jobs expose no recovery/Undo actions; crafted authenticated requests execute nothing and add zero journal/Undo rows';

    // History pagination stays truthful beyond the former 100-row window.
    $items = WriteLeash\Undo_Repository::history_items( (int) $planned['id'], null, null, 100, 50 );
    wl112_assert( 1000 === $items['total'] && 50 === count( $items['items'] ) && 150 === $items['next_offset'], 'history pagination beyond 100 remains truthful at the ceiling' );

    $db = V::observer();
    try { V::matches( V::storage( $db, $ids[0] ), '100' ); V::matches( V::storage( $db, $ids[1] ), '80' ); V::matches( V::storage( $db, $ids[1000] ), '80' ); }
    finally { $db->close(); }
    $report['zero_product_mutation_in_boundary_probes'] = 'PASS';
    $report['outcome'] = 'PASS'; sb_save( $report );
} catch ( Throwable $error ) {
    $report['outcome'] = 'FAIL'; $report['error'] = $error->getMessage(); sb_save( $report ); throw $error;
}
