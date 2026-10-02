<?php
// #119 repair: real HTTP refusal plus direct typed-controller assertions.
// Only the stale PLANNED-job fixture uses the preserved internal #107 planner.
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
    wl112_assert( 1000 === $report['engineering_selector_max'] && 100 === $report['free_admin_max'], 'engineering and Free ceilings must remain separate' );
    $term = wp_insert_term( 'WL119-' . wp_generate_uuid4(), 'product_cat' );
    wl112_assert( ! is_wp_error( $term ), 'boundary fixture category' );
    $ids = array();
    for ( $i = 0; $i < 101; ++$i ) {
        $p = new WC_Product_Simple(); $p->set_name( 'WL119-' . $i ); $p->set_status( 'publish' ); $p->set_regular_price( '100.00' ); $p->set_category_ids( array( (int) $term['term_id'] ) ); $p->save(); $ids[] = $p->get_id();
    }
    wl112_login();
    $base = 'http://127.0.0.1:8080/wp-admin/admin.php?page=writeleash-bulk-prices';
    $home = wl112_get( $base );
    $fields = wl112_form( $home['body'], A::ACTION_PREVIEW );
    wl112_assert( '100' === $fields['max_products'], 'UI default must be 100' );
    wl112_assert( (bool) preg_match( '/name="max_products"[^>]*max="100"/', $home['body'] ), 'UI maximum must be 100' );
    $fields = array_merge( $fields, array( 'selector' => 'ids', 'ids' => implode( ',', $ids ), 'operation' => 'SET', 'amount' => '80', 'max_products' => '100', 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '10' ) );
    unset( $fields['block_zero'] );
    foreach ( array( 'ids', 'category' ) as $selector ) {
        $post = $fields; $post['selector'] = $selector; $post['category'] = (string) $term['term_id'];
        $post['_wpnonce'] = wl112_nonce( A::ACTION_PREVIEW );
        $before = wl112_evidence_rows();
        $typed = A::process_preview( $post, 'POST' );
        wl112_assert( 'supported_job_limit_exceeded' === $typed['reason'] && 101 === $typed['selected_count'], '101 typed refusal ' . $selector );
        $response = wl112_post( $post );
        $page = wl112_get( $response['location'] );
        wl112_assert( false === strpos( $response['location'], 'wl_job=' ) && false !== strpos( $page['body'], '101 products were selected.' ), '101 visible refusal ' . $selector );
        wl112_assert( $before === wl112_evidence_rows(), '101 created job/journal ' . $selector );
        $report['101_' . $selector] = array( 'reason' => $typed['reason'], 'selected_count' => 101, 'before' => $before, 'after' => wl112_evidence_rows() );
    }
    // A lower safety policy cannot make a 101-selected plan a supported job.
    $post['max_products'] = '0';
    wl112_assert( 'supported_job_limit_exceeded' === A::process_preview( $post, 'POST' )['reason'], 'support cap must be independent of lower safety policy' );
    $policy = $fields; $policy['ids'] = (string) $ids[0]; $policy['max_products'] = '101';
    $before = wl112_evidence_rows();
    wl112_assert( 'invalid_product_limit' === A::process_preview( $policy, 'POST' )['reason'], 'max_products=101 rejected server-side' );
    $response = wl112_post( $policy ); wl112_get( $response['location'] );
    wl112_assert( $before === wl112_evidence_rows(), 'invalid policy created evidence' );
    $policy['max_products'] = '100';
    wl112_assert( 100 === A::build_policy( $policy )->data()['max_products_changed'], 'max_products=100 valid' );
    $report['safety_policy'] = 'PASS: 101 rejected, 100 valid, lower policy cannot raise selection boundary';

    $plan = WriteLeash\Woo_Price_Planner::preview( WriteLeash\Price_Selection_Spec::ids( $ids ), new WriteLeash\Price_Operation( 'SET', '80' ), new WriteLeash\Safety_Policy( 1000, '50', '50', false, '10' ) );
    wl112_assert( 101 === $plan->summary()['selected'] && 'PREVIEW' === $plan->data()['status'], 'internal engineering plan must remain valid at 101' );
    $stale = R::create_from_plan( $plan, 1 );
    // The controller must use the frozen population, not a stale/tampered
    // convenience counter on the durable job row.
    $wpdb->query( $wpdb->prepare( 'UPDATE %i SET total_selected=100 WHERE id=%d', WriteLeash\Job_Schema::jobs_table( $wpdb ), (int) $stale['id'] ) );
    $before = wl112_evidence_rows();
    $approval = array( 'action' => A::ACTION_APPROVE, 'job' => $stale['public_id'], '_wpnonce' => wl112_nonce( A::ACTION_APPROVE . '_' . $stale['plan_id'] ) );
    $typed = A::process_approve( $approval, 'POST' );
    wl112_assert( 'supported_job_limit_exceeded' === $typed['reason'], 'stale 101 job approval typed refusal' );
    $response = wl112_post( $approval ); $page = wl112_get( $response['location'] );
    wl112_assert( false !== strpos( $page['body'], '101 products were selected.' ), 'stale approval notice' );
    wl112_assert( $before === wl112_evidence_rows() && 'PLANNED' === R::read( (int) $stale['id'] )['status'], 'stale approval seeded journal or changed job' );
    $preview = wl112_get( $base . '&wl_view=preview&wl_job=' . $stale['public_id'] );
    wl112_assert( false === strpos( $preview['body'], 'value="' . A::ACTION_APPROVE . '"' ), 'stale preview exposes approval form' );
    $report['stale_approval_defense'] = array( 'reason' => $typed['reason'], 'job_status' => 'PLANNED', 'before' => $before, 'after' => wl112_evidence_rows(), 'additional_journal_rows' => 0 );
    $items = WriteLeash\Undo_Repository::history_items( (int) $stale['id'], null, null, 100, 50 );
    wl112_assert( 101 === $items['total'] && 1 === count( $items['items'] ) && null === $items['next_offset'], 'historical engineering job pagination beyond 100 remains truthful' );

    $blocked_plan = WriteLeash\Woo_Price_Planner::preview( WriteLeash\Price_Selection_Spec::ids( $ids ), new WriteLeash\Price_Operation( 'SET', '80' ), new WriteLeash\Safety_Policy( 0, '50', '50', false, '10' ) );
    $blocked = R::create_from_plan( $blocked_plan, 1 );
    foreach ( array( $stale, $blocked ) as $unapproved ) {
        $before = wl112_evidence_rows();
        $job_url = $base . '&wl_view=job&wl_job=' . $unapproved['public_id'];
        $page = wl112_get( $job_url );
        wl112_assert( false === strpos( $page['body'], 'Legacy oversized job' ), 'unapproved oversized job cannot be called grandfathered' );
        foreach ( array( A::ACTION_RESUME, A::ACTION_UNDO ) as $action ) {
            wl112_assert( false === strpos( $page['body'], 'value="' . $action . '"' ), 'unapproved oversized job exposes recovery mutation' );
            $response = wl112_post( array( 'action' => $action, 'job' => $unapproved['public_id'], '_wpnonce' => wl112_nonce( $action . '_' . $unapproved['public_id'] ) ) );
            wl112_get( $response['location'] );
        }
        wl112_assert( $before === wl112_evidence_rows() && 0 === R::counts( (int) $unapproved['id'] )['applied'], 'unapproved oversized job seeded/executed/recovered' );
    }
    $report['unapproved_oversized_states'] = 'PASS: PLANNED/BLOCKED expose no recovery/Undo actions; crafted authenticated requests execute nothing and add zero journal/Undo rows';

    // Positive 100 explicit IDs: exact frozen preview and ordinary approval.
    $fields['ids'] = implode( ',', array_slice( $ids, 0, 100 ) );
    $fields['_wpnonce'] = wl112_nonce( A::ACTION_PREVIEW );
    $response = wl112_post( $fields );
    wl112_assert( (bool) preg_match( '/wl_job=([0-9a-f-]{36})/', $response['location'], $match ), '100 explicit preview failed' );
    $page = wl112_get( $response['location'] );
    $job = R::read_by_public_id( $match[1] );
    wl112_assert( 100 === R::hydrate_plan( $job )->summary()['selected'], '100 explicit frozen count' );
    $before = wl112_evidence_rows();
    wl112_post( wl112_form( $page['body'], A::ACTION_APPROVE ) );
    wl112_assert( 100 === wl112_evidence_rows()['writeleash_price_items'] - $before['writeleash_price_items'], '100 explicit approval must seed exactly 100 journal rows' );
    $report['100_explicit'] = 'PASS: normal preview/approval, 100 journal rows seeded';
    $db = V::observer();
    try { foreach ( $ids as $id ) { V::matches( V::storage( $db, $id ), '100' ); } }
    finally { $db->close(); }
    $report['zero_product_mutation_in_boundary_probes'] = 'PASS';
    $report['outcome'] = 'PASS'; sb_save( $report );
} catch ( Throwable $error ) {
    $report['outcome'] = 'FAIL'; $report['error'] = $error->getMessage(); sb_save( $report ); throw $error;
}
