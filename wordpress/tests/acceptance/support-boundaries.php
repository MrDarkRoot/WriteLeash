<?php
// #119/#177/#183 repair: real HTTP refusal plus direct typed-controller
// assertions. The Free supported ceiling equals the engineering selector
// maximum (1,000): a 1,001-selected request is refused independently of the
// safety policy and creates no job/journal/Undo evidence. A trusted durable
// predecessor above 1,000 — hash-consistent stored material, because no
// current factory can build it — must still finish and Undo through the real
// Admin HTTP contract.
require __DIR__ . '/http.php';
use WriteLeash\Free_Admin as A;
use WriteLeash\Free_Support_Contract as S;
use WriteLeash\Job_Repository as R;
use WriteLeash\Job_State as JS;
use WriteLeash\Price_Operation as O;
use WriteLeash\Product_Price_Selector as Sel;

global $wpdb, $wl112_cookies;
$wl112_cookies = array();
wp_set_current_user( 1 );
$report = array( 'git_sha' => getenv( 'WL112_SHA' ), 'wp' => get_bloginfo( 'version' ), 'woo' => WC_VERSION, 'php' => PHP_VERSION, 'db' => $wpdb->get_var( 'SELECT VERSION()' ), 'cache' => wp_using_ext_object_cache() ? 'persistent' : 'default', 'engineering_selector_max' => Sel::MAX_SELECTED, 'free_admin_max' => S::MAX_JOB_PRODUCTS );
function sb_save( array $report ): void {
    file_put_contents( '/evidence/' . DB_HOST . '-support-boundaries.json', json_encode( $report, JSON_PRETTY_PRINT ) . "\n" );
}
/**
 * A hash-consistent >1,000 durable predecessor. The only current factories
 * cap at the engineering selector maximum, so this models old stored evidence
 * by extending a valid 1,000-item material with one more inspected item and
 * recomputing the canonical hash. Never a public import or approval bypass.
 */
function sb_extended_plan( WriteLeash\Change_Plan $base, WriteLeash\Change_Plan $tail, int $tail_id ): WriteLeash\Change_Plan {
    $material = $base->data();
    unset( $material['plan_id'], $material['created_at'], $material['plan_hash'], $material['summary'] );
    $tail_items = $tail->data()['items'];
    $material['items'][] = $tail_items[0];
    $material['resolved_product_ids'][] = $tail_id;
    $material['selection']['ids'][] = $tail_id;
    $data = $material;
    $data['plan_id'] = wp_generate_uuid4();
    $data['created_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
    $data['plan_hash'] = WriteLeash\Plan_Hasher::hash( $material );
    return WriteLeash\Change_Plan::hydrate( $data );
}
try {
    wl112_assert( 1000 === $report['engineering_selector_max'] && 1000 === $report['free_admin_max'], 'Free ceiling must match the engineering selector maximum' );
    $term = wp_insert_term( 'WL119-' . wp_generate_uuid4(), 'product_cat' );
    wl112_assert( ! is_wp_error( $term ), 'boundary fixture category' );
    // 12 changing products keep the frozen 1,001-product population large
    // while the bounded Apply/Undo work stays small and CI-practical.
    $ids = array();
    for ( $i = 0; $i < 1001; ++$i ) {
        $p = new WC_Product_Simple(); $p->set_name( 'WL119-' . $i ); $p->set_status( 'publish' ); $p->set_regular_price( $i < 12 ? '100.00' : '80.00' ); $p->set_category_ids( array( (int) $term['term_id'] ) ); $p->save(); $ids[] = $p->get_id();
        if ( 0 === $i % 100 ) { wp_cache_flush_runtime(); }
    }
    wl112_login();
    $base = 'http://127.0.0.1:8080/wp-admin/admin.php?page=writeleash-bulk-prices';
    $home = wl112_get( $base );
    $fields = wl112_form( $home['body'], A::ACTION_PREVIEW );
    wl112_assert( (string) S::MAX_JOB_PRODUCTS === $fields['max_products'], 'UI default must be the supported maximum' );
    wl112_assert( (bool) preg_match( '/name="max_products"[^>]*max="' . S::MAX_JOB_PRODUCTS . '"/', $home['body'] ), 'UI maximum must equal the supported maximum' );
    $fields = array_merge( $fields, array( 'picker_present' => '0', 'selector' => 'ids', 'ids' => implode( ',', $ids ), 'operation' => O::SET, 'amount' => '80', 'max_products' => (string) S::MAX_JOB_PRODUCTS, 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '10' ) );
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
        else { wl112_assert( false !== strpos( $page['body'], 'supports up to ' . S::MAX_JOB_PRODUCTS . ' products per job' ), '1001 visible refusal ' . $selector ); }
        wl112_assert( $before === wl112_evidence_rows(), '1001 created job/journal ' . $selector );
        $report['1001_' . $selector] = array( 'reason' => $typed['reason'], 'before' => $before, 'after' => wl112_evidence_rows() );
    }
    // A lower safety policy cannot make a 1001-selected request a supported job.
    $post['selector'] = 'ids';
    $post['ids'] = implode( ',', $ids );
    $post['max_products'] = '0';
    wl112_assert( 'supported_job_limit_exceeded' === A::process_preview( $post, 'POST' )['reason'], 'support cap must be independent of lower safety policy' );
    wl112_assert( 1001 === A::process_preview( $post, 'POST' )['selected_count'], 'lower policy refusal keeps the exact selected count' );
    $policy = $fields; $policy['ids'] = (string) $ids[0]; $policy['max_products'] = '1001';
    $before = wl112_evidence_rows();
    wl112_assert( 'invalid_product_limit' === A::process_preview( $policy, 'POST' )['reason'], 'max_products=1001 rejected server-side' );
    $response = wl112_post( $policy ); wl112_get( $response['location'] );
    wl112_assert( $before === wl112_evidence_rows(), 'invalid policy created evidence' );
    $policy['max_products'] = '1000';
    wl112_assert( 1000 === A::build_policy( $policy )->data()['max_products_changed'], 'max_products=1000 valid' );
    $report['safety_policy'] = 'PASS: 1001 rejected, 1000 valid, lower policy cannot raise the selection boundary';

    // One real 1,000-item category/plan completes as a single durable job with
    // 12 changing products and paginated preview/results. Approval consumes
    // the frozen population, never a tampered convenience counter.
    $fields['ids'] = implode( ',', array_slice( $ids, 0, 1000 ) );
    $fields['_wpnonce'] = wl112_nonce( A::ACTION_PREVIEW );
    $response = wl112_post( $fields );
    wl112_assert( (bool) preg_match( '/wl_job=([0-9a-f-]{36})/', $response['location'], $match ), '1000 explicit preview failed' );
    $page = wl112_get( $response['location'] );
    $job = R::read_by_public_id( $match[1] );
    $plan = R::hydrate_plan( $job );
    wl112_assert( 1000 === $plan->summary()['selected'] && 12 === $plan->summary()['changing'], '1000 explicit frozen population/change count' );
    $wpdb->query( $wpdb->prepare( 'UPDATE %i SET total_selected=1 WHERE id=%d', WriteLeash\Job_Schema::jobs_table( $wpdb ), (int) $job['id'] ) );
    update_option( 'wl112_scheduler_down', true, false );
    $before = wl112_evidence_rows();
    wl112_post( wl112_form( $page['body'], A::ACTION_APPROVE ) );
    delete_option( 'wl112_scheduler_down' );
    $after = wl112_evidence_rows();
    wl112_assert( 12 === $after['writeleash_price_items'] - $before['writeleash_price_items'], '1000 explicit approval must seed exactly its 12 frozen changing items' );
    wl112_assert( 1000 === R::hydrate_plan( R::read( (int) $job['id'] ) )->summary()['selected'], 'approval never trusts the tampered total_selected counter' );
    $history = WriteLeash\Undo_Repository::history_items( (int) $job['id'], null, null, 0, 50 );
    wl112_assert( 1000 === $history['total'] && 50 === count( $history['items'] ) && 50 === $history['next_offset'], '1000 frozen items remain paginated and addressable' );
    $report['1000_explicit'] = 'PASS: 1000-item frozen preview/approval used the frozen population; 12 journal rows; paginated history';

    // Trusted predecessor construction for the >1,000 durable boundary. The
    // plan factory refuses >1,000, so the extension is hash-consistent stored
    // material only; no public import, migration flag or approval bypass.
    $policy = new WriteLeash\Safety_Policy( S::MAX_JOB_PRODUCTS, '50', '50', false, '10' );
    $base_plan = WriteLeash\Woo_Price_Planner::preview( WriteLeash\Price_Selection_Spec::ids( array_slice( $ids, 0, 1000 ) ), new O( O::SET, '80' ), $policy );
    $tail_plan = WriteLeash\Change_Plan::create( wp_generate_uuid4(), gmdate( 'Y-m-d\TH:i:s\Z' ), 1, WriteLeash\Price_Store_Context::current(), WriteLeash\Price_Selection_Spec::ids( array( (int) $ids[1000] ) ), new O( O::SET, '80' ), $policy, array( WriteLeash\Product_Price_Snapshot::read( (int) $ids[1000], wc_get_product( (int) $ids[1000] ) ) ) );
    $over_planned = R::create_from_plan( sb_extended_plan( $base_plan, $tail_plan, (int) $ids[1000] ), 1 );
    wl112_assert( 1001 === R::hydrate_plan( $over_planned )->summary()['selected'] && JS::PLANNED === $over_planned['status'], 'unapproved >1000 durable predecessor must be PLANNED' );
    // The controller must use the frozen population, never the stale counter.
    $wpdb->query( $wpdb->prepare( 'UPDATE %i SET total_selected=1 WHERE id=%d', WriteLeash\Job_Schema::jobs_table( $wpdb ), (int) $over_planned['id'] ) );
    $before = wl112_evidence_rows();
    $approval = array( 'action' => A::ACTION_APPROVE, 'job' => $over_planned['public_id'], '_wpnonce' => wl112_nonce( A::ACTION_APPROVE . '_' . $over_planned['plan_id'] ) );
    $typed = A::process_approve( $approval, 'POST' );
    wl112_assert( 'supported_job_limit_exceeded' === $typed['reason'] && 1001 === $typed['selected_count'], 'stale >1000 job approval typed refusal' );
    $response = wl112_post( $approval ); $page = wl112_get( $response['location'] );
    wl112_assert( false !== strpos( $page['body'], '1001 products were selected.' ), 'stale >1000 approval notice' );
    wl112_assert( $before === wl112_evidence_rows() && JS::PLANNED === R::read( (int) $over_planned['id'] )['status'], 'stale >1000 approval seeded journal or changed job' );
    $preview = wl112_get( $base . '&wl_view=preview&wl_job=' . $over_planned['public_id'] );
    wl112_assert( false === strpos( $preview['body'], 'value="' . A::ACTION_APPROVE . '"' ), 'stale >1000 preview exposes approval form' );
    wl112_assert( false === strpos( $preview['body'], 'Older large job' ), 'unapproved >1000 job cannot be called grandfathered' );
    $report['stale_approval_defense'] = array( 'reason' => $typed['reason'], 'job_status' => 'PLANNED', 'before' => $before, 'after' => wl112_evidence_rows(), 'additional_journal_rows' => 0 );
    // PLANNED/BLOCKED at-ceiling work exposes no recovery or Undo action, and
    // crafted authenticated requests execute nothing.
    $planned = R::create_from_plan( $base_plan, 1 );
    $blocked_plan = WriteLeash\Woo_Price_Planner::preview( WriteLeash\Price_Selection_Spec::ids( array_slice( $ids, 0, 1000 ) ), new O( O::SET, '80' ), new WriteLeash\Safety_Policy( 0, '50', '50', false, '10' ) );
    $blocked = R::create_from_plan( $blocked_plan, 1 );
    foreach ( array( $planned, $blocked, $over_planned ) as $unapproved ) {
        $before = wl112_evidence_rows();
        $job_url = $base . '&wl_view=job&wl_job=' . $unapproved['public_id'];
        $page = wl112_get( $job_url );
        wl112_assert( false === strpos( $page['body'], 'Older large job' ), 'unapproved job cannot be called grandfathered' );
        foreach ( array( A::ACTION_RESUME, A::ACTION_UNDO ) as $action ) {
            wl112_assert( false === strpos( $page['body'], 'value="' . $action . '"' ), 'unapproved job exposes recovery mutation' );
            $response = wl112_post( array( 'action' => $action, 'job' => $unapproved['public_id'], '_wpnonce' => wl112_nonce( $action . '_' . $unapproved['public_id'] ) ) );
            wl112_get( $response['location'] );
        }
        wl112_assert( $before === wl112_evidence_rows() && 0 === R::counts( (int) $unapproved['id'] )['applied'], 'unapproved job seeded/executed/recovered' );
    }
    $report['unapproved_states'] = 'PASS: PLANNED/BLOCKED 1000- and 1001-item jobs expose no recovery/Undo actions; crafted authenticated requests execute nothing and add zero journal/Undo rows';

    // An older approved >1,000 job must still finish and Undo through the
    // protected HTTP contract.
    $over_ready = R::create_from_plan( sb_extended_plan( $base_plan, $tail_plan, (int) $ids[1000] ), 1 );
    $over_ready = R::approve( (int) $over_ready['id'], 1 );
    wl112_assert( JS::READY === $over_ready['status'] && 1001 === R::hydrate_plan( $over_ready )->summary()['selected'], 'approved >1000 predecessor must be durable READY with all 1,001 items' );
    $over_url = $base . '&wl_view=job&wl_job=' . $over_ready['public_id'];
    $page = wl112_get( $over_url );
    wl112_assert( false !== strpos( $page['body'], 'Older large job' ) && false !== strpos( $page['body'], A::legacy_oversize_message() ), 'approved >1000 job must show the older-large-job recovery copy' );
    wl112_assert( false !== strpos( $page['body'], 'value="' . A::ACTION_RESUME . '"' ), 'approved >1000 job must expose bounded recovery' );
    $frozen_json = $over_ready['plan_json'];
    $frozen_hash = $over_ready['plan_hash'];
    $applied = 0;
    for ( $i = 0; $i < 6; ++$i ) {
        $before_counts = R::counts( (int) $over_ready['id'] );
        if ( JS::COMPLETED === R::read( (int) $over_ready['id'] )['status'] ) { break; }
        wl112_post( wl112_form( $page['body'], A::ACTION_RESUME ) );
        $after_counts = R::counts( (int) $over_ready['id'] );
        $delta = $after_counts['applied'] - $before_counts['applied'];
        wl112_assert( $delta > 0 && $delta <= 10 && 0 === $after_counts['applying'], 'each HTTP recovery step must be one bounded truthful chunk' );
        $applied += $delta;
        $page = wl112_get( $over_url );
    }
    $done = R::read( (int) $over_ready['id'] );
    wl112_assert( JS::COMPLETED === $done['status'] && 12 === $applied && 12 === R::counts( (int) $over_ready['id'] )['applied'], 'approved >1000 job must finish every changing product' );
    wl112_assert( $frozen_json === $done['plan_json'] && $frozen_hash === $done['plan_hash'], 'recovery must not rewrite the frozen >1000 plan' );
    $undo_op = WriteLeash\Undo_Repository::read_operation_by_job( (int) $over_ready['id'] );
    for ( $i = 0; $i < 6; ++$i ) {
        $undo_op = WriteLeash\Undo_Repository::read_operation_by_job( (int) $over_ready['id'] );
        if ( is_array( $undo_op ) && WriteLeash\Undo_State::is_terminal( $undo_op['status'] ) ) { break; }
        wl112_post( wl112_form( $page['body'], A::ACTION_UNDO ) );
        $page = wl112_get( $over_url );
    }
    $undo_op = WriteLeash\Undo_Repository::read_operation_by_job( (int) $over_ready['id'] );
    wl112_assert( 'UNDO_COMPLETED' === $undo_op['status'] && 12 === (int) $undo_op['undone'], 'approved >1000 job must Undo every applied product' );
    $report['grandfathered_1001'] = array( 'selected' => 1001, 'applied' => 12, 'undone' => 12, 'frozen_plan_unchanged' => true, 'http' => 'PASS: real Admin Resume/Undo with the older-large-job copy' );

    // Positive 1000 explicit IDs already exercised the ordinary preview and
    // approval above. Product truth: every boundary probe left prices alone.
    $db = WriteLeash\Price_Cache_Verifier::observer();
    try { foreach ( $ids as $index => $id ) { WriteLeash\Price_Cache_Verifier::matches( WriteLeash\Price_Cache_Verifier::storage( $db, $id ), $index < 12 ? '100' : '80' ); } }
    finally { $db->close(); }
    $report['zero_product_mutation_in_boundary_probes'] = 'PASS';
    $report['outcome'] = 'PASS'; sb_save( $report );
} catch ( Throwable $error ) {
    $report['outcome'] = 'FAIL'; $report['error'] = $error->getMessage(); sb_save( $report ); throw $error;
}
