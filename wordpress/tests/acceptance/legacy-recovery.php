<?php
// Trusted predecessor setup only. Recovery/Undo use authenticated Admin HTTP;
// no public import bypass, migration flag, new worker or generation override.
require __DIR__ . '/http.php';
use WriteLeash\Free_Admin as A;
use WriteLeash\Job_Repository as R;
use WriteLeash\Job_State as JS;
use WriteLeash\Price_Apply_Journal as J;
use WriteLeash\Price_Cache_Verifier as V;
use WriteLeash\Price_Decimal as D;
use WriteLeash\Undo_Repository as U;

global $wpdb, $wl112_cookies;
$wl112_cookies = array();
wp_set_current_user( 1 );
$report = array( 'git_sha' => getenv( 'WL112_SHA' ), 'wp' => get_bloginfo( 'version' ), 'woo' => WC_VERSION, 'php' => PHP_VERSION, 'db' => $wpdb->get_var( 'SELECT VERSION()' ), 'cache' => wp_using_ext_object_cache() ? 'persistent' : 'default', 'selected' => 101 );
function lr_report( array $report ): void {
    file_put_contents( '/evidence/' . DB_HOST . '-legacy-recovery.json', json_encode( $report, JSON_PRETTY_PRINT ) . "\n" );
}
function lr_journal_binding( array $job ): array {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        'SELECT product_id,schema_version,plan_id,plan_schema_version,plan_hash_version,plan_hash,SHA2(plan_json,256) AS material_hash,expected_price,target_price FROM %i WHERE plan_id=%s ORDER BY product_id',
        J::table( $wpdb ), $job['plan_id']
    ), ARRAY_A );
}
function lr_apply_evidence( array $job ): array {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare( 'SELECT product_id,state,attempt_id,applied_at,SHA2(evidence,256) AS evidence_hash FROM %i WHERE plan_id=%s ORDER BY product_id', J::table( $wpdb ), $job['plan_id'] ), ARRAY_A );
}
function lr_probe_events(): array {
    $path = '/evidence/' . DB_HOST . '-legacy-probe.jsonl';
    return is_file( $path ) ? array_map( static function ( $line ) { return json_decode( $line, true ); }, file( $path, FILE_IGNORE_NEW_LINES ) ) : array();
}
function lr_saves(): array {
    $counts = array();
    foreach ( lr_probe_events() as $event ) {
        if ( 'save' === $event['event'] ) { $id = (int) $event['product_id']; $counts[$id] = ( $counts[$id] ?? 0 ) + 1; }
    }
    return $counts;
}
function lr_warning( string $body ): void {
    wl112_assert( false !== strpos( $body, 'Legacy oversized job' ) && false !== strpos( $body, 'Bounded recovery and conflict-aware eligible Undo remain available' ), 'legacy recovery warning missing' );
    wl112_assert( false === strpos( $body, 'supports up to 1,000' ) && false === strpos( $body, '101 products were selected. Narrow' ), 'legacy job mislabeled as newly supported or invalid new work' );
}
function lr_truth( array $prices ): void {
    $db = V::observer();
    try {
        wp_cache_flush_runtime();
        foreach ( $prices as $id => $expected ) {
            V::matches( V::storage( $db, (int) $id ), $expected );
            $p = wc_get_product( $id );
            wl112_assert( $p && D::parse( $p->get_regular_price( 'edit' ) ) === D::parse( $expected ), 'legacy cached regular-price parity' );
            wl112_assert( D::parse( get_post_meta( $id, '_price', true ) ) === D::parse( $expected ), 'legacy cached active-price parity' );
        }
    } finally { $db->close(); }
}
try {
    $ids = array();
    for ( $i = 0; $i < 102; ++$i ) {
        $p = new WC_Product_Simple(); $p->set_name( 'WL119-legacy-' . $i ); $p->set_status( 'publish' ); $p->set_regular_price( '100.00' ); $p->save();
        if ( $i < 101 ) { $ids[] = $p->get_id(); } else { $sentinel = $p->get_id(); }
    }
    $selection = WriteLeash\Price_Selection_Spec::ids( $ids );
    $operation = new WriteLeash\Price_Operation( 'DECREASE_PERCENT', '20' );
    $policy = new WriteLeash\Safety_Policy( 1000, '50', '50', false, '10' );
    // Freeze a historical supported Woo context even when the CURRENT runtime
    // is the unsupported-Woo matrix row. Trusted domain construction models
    // old stored evidence, not permission to execute against that runtime.
    if ( WC_VERSION === '11.1.2' ) {
        $plan = WriteLeash\Woo_Price_Planner::preview( $selection, $operation, $policy );
    } else {
        $plan = WriteLeash\Change_Plan::create( wp_generate_uuid4(), gmdate( 'Y-m-d\TH:i:s\Z' ), 1,
            new WriteLeash\Price_Store_Context( get_woocommerce_currency(), wc_get_price_decimals(), get_bloginfo( 'version' ), '11.1.2' ),
            $selection, $operation, $policy, WriteLeash\Product_Price_Selector::resolve( $selection ) );
    }
    wl112_assert( 101 === $plan->summary()['selected'] && 'PREVIEW' === $plan->data()['status'], 'historical immutable 101-item plan' );
    $job = R::create_from_plan( $plan, 1 );
    $job = R::approve( (int) $job['id'], 1 );
    wl112_assert( JS::READY === $job['status'], 'predecessor approval must be durable READY' );
    $binding = lr_journal_binding( $job );
    wl112_assert( 101 === count( $binding ), 'predecessor must seed all 101 Apply rows before recovery' );
    $original_material = $job['plan_json'];
    $original_hash = $job['plan_hash'];
    $report['pre_existing_journal_rows'] = 101;
    $report['frozen_hash'] = $original_hash;
    wl112_login();
    $url = 'http://127.0.0.1:8080/wp-admin/admin.php?page=writeleash-bulk-prices&wl_view=job&wl_job=' . $job['public_id'];

    if ( WC_VERSION !== '11.1.2' ) {
        $before = wl112_evidence_rows();
        $page = wl112_get( $url );
        wl112_assert( false !== strpos( $page['body'], 'Installed version: ' . WC_VERSION ), 'legacy job must not grandfather Woo version' );
        foreach ( array( A::ACTION_RESUME, A::ACTION_UNDO ) as $action ) {
            wl112_assert( false === strpos( $page['body'], 'value="' . $action . '"' ), 'unsupported Woo legacy mutation form exposed' );
            $result = wl112_post( array( 'action' => $action, 'job' => $job['public_id'], '_wpnonce' => wl112_nonce( $action . '_' . $job['public_id'] ) ) );
            $refusal = wl112_get( $result['location'] );
            wl112_assert( false !== strpos( $refusal['body'], 'Installed version: ' . WC_VERSION ), 'legacy unsupported Woo refusal copy' );
        }
        wl112_assert( $before === wl112_evidence_rows() && $binding === lr_journal_binding( $job ) && 0 === R::counts( (int) $job['id'] )['applied'], 'unsupported Woo legacy work mutated' );
        lr_truth( array_fill_keys( array_merge( $ids, array( $sentinel ) ), '100' ) );
        $report['current_woo_gate'] = 'PASS: legacy 101 READY refused by CURRENT unsupported Woo; no additional evidence or mutation';
        $report['outcome'] = 'PASS_UNSUPPORTED_RUNTIME'; lr_report( $report );
        return;
    }

    update_option( 'wl112_legacy_probe_active', true, false ); // Test-only observer, never production authority.
    // Registered Action Scheduler worker entry; exactly the same run()/lease/
    // item-transaction fence used by Admin Resume, with normal default limits.
    do_action( WriteLeash\Job_Scheduler::HOOK, (int) $job['id'] );
    $partial = R::counts( (int) $job['id'] );
    wl112_assert( $partial['applied'] > 0 && $partial['applied'] <= 10 && $partial['pending'] > 0, 'automatic legacy chunk must be bounded and partial' );
    $automatic_generation = (int) R::read( (int) $job['id'] )['lease_generation'];
    wl112_assert( R::mark_paused( (int) $job['id'], 'SCHEDULER_UNAVAILABLE' ), 'production scheduler impairment must pause legacy job' );
    $page = wl112_get( $url ); lr_warning( $page['body'] );
    $form = wl112_form( $page['body'], A::ACTION_RESUME );
    wl112_assert( false !== strpos( $page['body'], 'PAUSED' ) && false !== strpos( $page['body'], 'pending ' . $partial['pending'] ), 'truthful paused legacy counts' );

    // Both paths must refuse the same live competing lease. No forced expiry
    // or fake generation. Release it through the ordinary fenced transition.
    $owner = wp_generate_uuid4();
    $held = R::acquire_lease( (int) $job['id'], $owner, true );
    wl112_assert( ! empty( $held ), 'trusted competing lease setup' );
    do_action( WriteLeash\Job_Scheduler::HOOK, (int) $job['id'] );
    wl112_post( $form );
    wl112_assert( $partial === R::counts( (int) $job['id'] ) && R::fence( (int) $job['id'], $owner, $held['generation'] ), 'scheduler/manual must honor same held lease' );
    wl112_assert( R::finish_chunk( R::read( (int) $job['id'] ), $owner, $held['generation'], JS::PAUSED, 'MANUAL_PAUSE' ), 'production fenced lease release' );

    $chunks = array();
    for ( $i = 0; $i < 20; ++$i ) {
        $page = wl112_get( $url ); lr_warning( $page['body'] );
        $before = R::counts( (int) $job['id'] );
        if ( JS::COMPLETED === R::read( (int) $job['id'] )['status'] ) { break; }
        $generation = (int) R::read( (int) $job['id'] )['lease_generation'];
        wl112_post( wl112_form( $page['body'], A::ACTION_RESUME ) );
        $after = R::counts( (int) $job['id'] );
        $delta = $after['applied'] - $before['applied'];
        wl112_assert( $delta > 0 && $delta <= 10 && 0 === $after['applying'], 'each HTTP legacy Resume must be one bounded truthful chunk' );
        wl112_assert( (int) R::read( (int) $job['id'] )['lease_generation'] > $generation, 'manual legacy chunk must acquire normal generation' );
        $chunks[] = $delta;
    }
    $done = R::read( (int) $job['id'] );
    wl112_assert( JS::COMPLETED === $done['status'] && 101 === R::counts( (int) $job['id'] )['applied'], 'legacy Apply must finish all 101' );
    wl112_assert( $done['plan_json'] === $original_material && $done['plan_hash'] === $original_hash && $binding === lr_journal_binding( $job ), 'legacy Apply changed frozen population/binding or seeded journal' );
    $saves = lr_saves();
    wl112_assert( count( $saves ) === 101, 'legacy Apply saved product outside frozen plan' );
    foreach ( $ids as $id ) { wl112_assert( 1 === ( $saves[$id] ?? 0 ), 'legacy Apply duplicate/missing Woo save' ); V::observe( $plan, $id ); }
    lr_truth( array_fill_keys( $ids, '80' ) + array( $sentinel => '100' ) );
    $report['paused_apply'] = array( 'outcome' => 'PASS', 'automatic_chunk_applied' => $partial['applied'], 'automatic_generation' => $automatic_generation, 'manual_chunk_applied' => $chunks, 'final_counts' => R::counts( (int) $job['id'] ), 'same_held_lease_refusal' => 'PASS', 'journal_rows_before_after' => array( 101, count( lr_journal_binding( $job ) ) ), 'frozen_binding_unchanged' => true, 'exactly_one_save_each' => true );
    lr_report( $report );

    // Cases B/C share this complete, journal-proven historical job. One later
    // supported Woo edit must conflict, while 100 other originals restore.
    $applied_evidence = lr_apply_evidence( $job );
    $later = $ids[100];
    V::invalidate( $later ); $p = wc_get_product( $later ); $p->set_regular_price( '75.00' ); $p->save();
    $save_baseline = lr_saves();
    $page = wl112_get( $url ); lr_warning( $page['body'] );
    wl112_assert( U::history_job( (int) $job['id'] )['undo_eligible'], 'legacy completed Apply must retain #110 eligibility' );
    wl112_post( wl112_form( $page['body'], A::ACTION_UNDO ) );
    $op = U::read_operation_by_job( (int) $job['id'] );
    $undo_id = (int) $op['id'];
    wl112_assert( (int) $op['undone'] > 0 && (int) $op['undone'] <= 10 && ! WriteLeash\Undo_State::is_terminal( $op['status'] ), 'legacy Undo first POST must be bounded and nonterminal' );
    $report['partial_undo_before_reopen'] = array( 'id' => $undo_id, 'status' => $op['status'], 'undone' => (int) $op['undone'] );
    wl112_login();
    $page = wl112_get( $url ); lr_warning( $page['body'] );
    $undo_chunks = array();
    for ( $i = 0; $i < 20; ++$i ) {
        $op = U::read_operation_by_job( (int) $job['id'] );
        wl112_assert( (int) $op['id'] === $undo_id, 'reopen invented a second legacy Undo operation' );
        if ( WriteLeash\Undo_State::is_terminal( $op['status'] ) ) { break; }
        $before = (int) $op['undone'] + (int) $op['undo_conflict'];
        $generation = (int) $op['lease_generation'];
        wl112_post( wl112_form( $page['body'], A::ACTION_UNDO ) );
        $op = U::read_operation_by_job( (int) $job['id'] );
        $delta = (int) $op['undone'] + (int) $op['undo_conflict'] - $before;
        wl112_assert( $delta > 0 && $delta <= 10 && (int) $op['lease_generation'] > $generation, 'legacy Undo continuation must be bounded and leased' );
        $undo_chunks[] = $delta;
        $page = wl112_get( $url ); lr_warning( $page['body'] );
    }
    wl112_assert( 'UNDO_COMPLETED_WITH_ISSUES' === $op['status'] && 100 === (int) $op['undone'] && 1 === (int) $op['undo_conflict'], 'legacy Undo must restore 100 and preserve one later edit' );
    wl112_assert( $binding === lr_journal_binding( $job ) && $applied_evidence === lr_apply_evidence( $job ), 'Undo must not rewrite/seed Apply evidence' );
    $saves = lr_saves();
    foreach ( $ids as $id ) { wl112_assert( ( $id === $later ? 0 : 1 ) === $saves[$id] - $save_baseline[$id], 'legacy Undo duplicate save or conflict overwrite' ); }
    $prices = array_fill_keys( $ids, '100' ); $prices[$later] = '75'; $prices[$sentinel] = '100'; lr_truth( $prices );
    wl112_assert( false === strpos( $page['body'], 'value="' . A::ACTION_UNDO . '"' ), 'terminal legacy Undo exposes repeat action' );
    $report['completed_apply_undo'] = array( 'outcome' => 'PASS', 'authority_applied_rows' => 101, 'restored' => 100, 'conflict_preserved' => 1, 'same_undo_id' => $undo_id, 'continuation_chunks' => $undo_chunks, 'apply_evidence_unchanged' => true, 'additional_apply_journal_rows' => 0 );
    $report['scheduler_manual_authority'] = 'SAME #109 run()/lease/generation/item-transaction fence/lifecycle contract; both refused same competing live lease';
    $report['legacy_warning_copy'] = A::legacy_oversize_message();
    $report['outcome'] = 'PASS'; lr_report( $report );
} catch ( Throwable $error ) {
    $report['outcome'] = 'FAIL'; $report['error'] = $error->getMessage(); lr_report( $report ); throw $error;
} finally {
    delete_option( 'wl112_legacy_probe_active' );
}
