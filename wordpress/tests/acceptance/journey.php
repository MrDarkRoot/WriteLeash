<?php
// Every timed product operation is a real authenticated Admin HTTP request.
// Fixture creation and read-only independent DB assertions are separate.
require __DIR__ . '/http.php';
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Price_Cache_Verifier as V;
use WriteLeash\Price_Decimal as D;
use WriteLeash\Undo_Repository as U;

global $wpdb, $wl112_cookies, $result_file;
$wl112_cookies = array();
$size = (int) getenv( 'WL112_SIZE' );
$result_file = '/evidence/' . DB_HOST . '-' . $size . '.json';
$facts = array( 'git_sha' => getenv( 'WL112_SHA' ), 'wp' => get_bloginfo( 'version' ), 'woo' => WC_VERSION, 'php' => PHP_VERSION, 'db' => $wpdb->get_var( 'SELECT VERSION()' ), 'cache' => wp_using_ext_object_cache() ? 'Redis 7.4.2 / Redis Object Cache 2.7.0 / Predis' : 'default', 'catalog_size' => $size, 'requested_job_size' => $size, 'outcome' => 'STARTED', 'fixture_php_memory_limit' => ini_get( 'memory_limit' ) );
function wl112_save( array $facts ): void {
    global $result_file;
    $facts['request_end_line'] = is_file( getenv( 'WL112_METRICS' ) ) ? count( file( getenv( 'WL112_METRICS' ) ) ) : 0;
    file_put_contents( $result_file, json_encode( $facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
}
function wl112_quantiles( array $values ): array {
    sort( $values, SORT_NUMERIC );
    $out = array();
    foreach ( array( 50, 95, 99 ) as $p ) { $out['p' . $p] = $values[(int) ceil( count( $values ) * $p / 100 ) - 1]; }
    return $out;
}
function wl112_sizes(): array {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s ORDER BY TABLE_NAME', DB_NAME ), ARRAY_A );
}
function wl112_parity( array $ids, string $price ): void {
    $db = V::observer();
    try {
        foreach ( $ids as $id ) {
            // Before any reconciliation/eviction: cached Woo/meta must already
            // agree with independent committed storage.
            $p = wc_get_product( $id );
            wl112_assert( $p && D::parse( $p->get_regular_price( 'edit' ) ) === D::parse( $price ), 'cached regular parity' );
            wl112_assert( D::parse( get_post_meta( $id, '_regular_price', true ) ) === D::parse( $price ), 'cached metadata parity' );
            V::matches( V::storage( $db, $id ), $price );
        }
    } finally { $db->close(); }
}
try {
    $facts['request_start_line'] = is_file( getenv( 'WL112_METRICS' ) ) ? count( file( getenv( 'WL112_METRICS' ) ) ) : 0;
    wp_set_current_user( 1 );
    delete_transient( '_wc_activation_redirect' );
    $facts['db_privileges'] = array_map( static function ( $grant ) {
        preg_match( '/^GRANT (.*?) ON/', $grant, $m );
        return $m[1] ?? 'UNKNOWN';
    }, $wpdb->get_col( 'SHOW GRANTS' ) );
    $facts['innodb'] = $wpdb->get_results( 'SHOW ENGINES', ARRAY_A );
    $facts['db_before_fixture'] = wl112_sizes();
    $t = microtime( true );
    $fixture_queries = $wpdb->num_queries;
    $term = wp_insert_term( 'WL112-' . wp_generate_uuid4(), 'product_cat' );
    wl112_assert( ! is_wp_error( $term ), 'fixture category failed' );
    $ids = array();
    for ( $i = 0; $i < $size; ++$i ) {
        $p = new WC_Product_Simple();
        $p->set_name( 'WL112-' . $i );
        $p->set_status( 'publish' );
        $p->set_regular_price( '100.00' );
        $p->set_category_ids( array( (int) $term['term_id'] ) );
        $p->save();
        $ids[] = $p->get_id();
        if ( 0 === $i % 100 ) { wp_cache_flush_runtime(); }
    }
    $facts['fixture_seconds'] = microtime( true ) - $t;
    $facts['fixture_queries'] = $wpdb->num_queries - $fixture_queries;
    $facts['fixture_peak_php_bytes'] = memory_get_peak_usage( true );
    $facts['db_after_fixture'] = wl112_sizes();
    wl112_save( $facts );
    wl112_login();
    $base = 'http://127.0.0.1:8080/wp-admin/admin.php?page=writeleash-bulk-prices';
    $t = microtime( true );
    $home = wl112_get( $base );
    $fields = wl112_form( $home['body'], 'writeleash_free_preview' );
    $fields = array_merge( $fields, array( 'selector' => 'category', 'category' => (string) $term['term_id'], 'ids' => '', 'operation' => 'DECREASE_PERCENT', 'amount' => '20', 'max_products' => '1000', 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '10' ) );
    unset( $fields['block_zero'] );
    $post = wl112_post( $fields );
    $facts['plan_post_seconds'] = $post['seconds'];
    if ( $size > 1000 ) {
        wl112_assert( false === strpos( $post['location'], 'wl_job=' ), '10k unexpectedly accepted' );
        $page = wl112_get( $post['location'] );
        wl112_assert( false !== strpos( $page['body'], 'INVALID' ), '10k not visibly refused' );
        $fields['selector'] = 'ids';
        $fields['ids'] = implode( ',', $ids );
        $second = wl112_post( $fields );
        wl112_assert( false === strpos( $second['location'], 'wl_job=' ), '10k IDs unexpectedly accepted' );
        wl112_parity( $ids, '100' );
        $facts['outcome'] = 'USABLE_WITH_LIMIT';
        $facts['classification_reason'] = '10,000 actual fixture products; both category and explicit-ID Admin selection refused by existing 1,000 selection contract; zero mutations. No 10k job can be created.';
        $facts['actual_job_size'] = 0;
        $facts['execution_seconds'] = null;
        $facts['undo_seconds'] = null;
        wl112_save( $facts );
        echo '#112 ' . DB_HOST . ' 10000: USABLE_WITH_LIMIT (selection refused)\n';
        return;
    }
    wl112_assert( (bool) preg_match( '/wl_job=([0-9a-f-]{36})/', $post['location'], $m ), 'preview not created' );
    $public = $m[1];
    $job = Repo::read_by_public_id( $public );
    $preview = wl112_get( $post['location'] );
    $facts['preview_first_page_seconds'] = $preview['seconds'];
    $facts['first_plan_journey_seconds'] = microtime( true ) - $t;
    $seen = array();
    $later = array();
    $plan = Repo::hydrate_plan( $job );
    for ( $offset = 0; $offset < $size; $offset += 20 ) {
        $page = 0 === $offset ? $preview : wl112_get( $post['location'] . '&wl_offset=' . $offset );
        $row_ids = wl112_rows( $page['body'] );
        wl112_assert( $row_ids === array_slice( $ids, $offset, 20 ), 'preview deterministic bounded ordering' );
        $material = $plan->preview_page( $offset, 20 );
        wl112_assert( $plan->summary()['selected'] === $size && $material['next_offset'] === ( $offset + 20 < $size ? $offset + 20 : null ), 'preview next/selected total' );
        wl112_assert( false !== strpos( $page['body'], 'Selected ' . $size . ' ' ), 'Admin preview selected total' );
        $seen = array_merge( $seen, $row_ids );
        if ( $offset > 0 ) { $later[] = $page['seconds']; }
    }
    wl112_assert( $seen === $ids, 'all preview pages cover frozen catalog exactly' );
    $facts['preview_later_page_seconds'] = $later;
    // Scheduler cannot accept the approval wake-up. Domain truth must pause.
    update_option( 'wl112_scheduler_down', true, false );
    $approval = wl112_post( wl112_form( $preview['body'], 'writeleash_free_approve' ) );
    $facts['approval_seconds'] = $approval['seconds'];
    $url = $approval['location'];
    $progress = wl112_get( $url );
    $facts['approval_durable_state'] = Repo::read( (int) $job['id'] )['status'];
    wl112_assert( false !== strpos( $progress['body'], 'pending ' . $size ), 'approval falsely implies mutation' );
    $approved_at = microtime( true );
    sleep( 2 );
    wl112_login();
    $progress = wl112_get( $url );
    wl112_assert( false !== strpos( $progress['body'], 'pending ' . $size ), 'no-traffic reopen lost pending truth' );
    delete_option( 'wl112_scheduler_down' );
    $facts['scheduler_lag_lower_bound_seconds'] = microtime( true ) - $approved_at;
    $facts['scheduler_mode'] = 'DISABLE_WP_CRON=true; async loopback runner denied; no traffic; enqueue unavailable at approval; recovered by protected Admin resume';
    $t = microtime( true );
    $batches = array();
    for ( $i = 0; $i < (int) ceil( $size / 10 ) + 5; ++$i ) {
        $batch = wl112_post( wl112_form( $progress['body'], 'writeleash_free_resume' ) );
        $batches[] = $batch['seconds'];
        $progress = wl112_get( $url );
        $current = Repo::read( (int) $job['id'] );
        if ( WC_VERSION !== '11.1.2' ) {
            wl112_assert( (int) $current['applied'] === 0, 'unsupported Woo mutated' );
            wl112_parity( $ids, '100' );
            $facts['outcome'] = 'UNSUPPORTED';
            $facts['classification_reason'] = 'Previous Woo rejected by existing exact-version execution contract; no private metadata fallback.';
            wl112_save( $facts );
            return;
        }
        if ( 'COMPLETED' === $current['status'] ) { break; }
        wl112_assert( ! in_array( $current['status'], array( 'COMPLETED_WITH_ISSUES', 'NEEDS_REVIEW', 'FAILED' ), true ), 'KILL: scale Apply correctness failure' );
        if ( 0 === $i ) {
            wl112_assert( (int) $current['applied'] <= 10, 'manual resume unbounded' );
            wl112_login();
            $progress = wl112_get( $url );
            wl112_assert( false !== strpos( $progress['body'], 'applied ' . $current['applied'] ), 'partial reopen drift' );
        }
    }
    $facts['execution_seconds'] = microtime( true ) - $t;
    $facts['manual_resume_first_chunk_seconds'] = $batches[0];
    $facts['apply_batch_seconds'] = $batches;
    $facts['apply_batch_quantiles_seconds'] = wl112_quantiles( $batches );
    $facts['items_per_second_including_progress_http'] = $size / $facts['execution_seconds'];
    $facts['final_counts'] = Repo::counts( (int) $job['id'] );
    wl112_assert( $facts['final_counts']['applied'] === $size && $facts['final_counts']['pending'] === 0, 'KILL: incomplete Apply' );
    wp_cache_flush_runtime();
    wl112_parity( $ids, '80' );
    foreach ( $ids as $id ) { V::observe( $plan, $id ); }
    $facts['cache_lookup_journal_parity'] = 'PASS';
    $facts['db_after_apply'] = wl112_sizes();
    $hist = wl112_get( $base . '&wl_view=history' );
    wl112_assert( false !== strpos( $hist['body'], substr( $public, 0, 8 ) ), 'Admin history missing job' );
    $seen = array();
    for ( $offset = 0; $offset < $size; $offset += 50 ) {
        $page = wl112_get( $url . '&wl_offset=' . $offset );
        wl112_assert( wl112_rows( $page['body'] ) === array_slice( $ids, $offset, 50 ), 'history bounded deterministic ordering' );
        $material = U::history_items( (int) $job['id'], null, null, $offset, 50 );
        wl112_assert( $material['total'] === $size && $material['next_offset'] === ( $offset + 50 < $size ? $offset + 50 : null ), 'history next/total' );
    }
    wl112_save( $facts );
    $t = microtime( true );
    $undo_batches = array();
    for ( $i = 0; $i < (int) ceil( $size / 10 ) + 5; ++$i ) {
        $batch = wl112_post( wl112_form( $progress['body'], 'writeleash_free_undo' ) );
        $undo_batches[] = $batch['seconds'];
        $progress = wl112_get( $url );
        $op = U::read_operation_by_job( (int) $job['id'] );
        if ( 'UNDO_COMPLETED' === $op['status'] ) { break; }
        wl112_assert( ! in_array( $op['status'], array( 'UNDO_COMPLETED_WITH_ISSUES', 'UNDO_NEEDS_REVIEW' ), true ), 'KILL: scale Undo correctness failure' );
    }
    $facts['undo_seconds'] = microtime( true ) - $t;
    $facts['undo_batch_seconds'] = $undo_batches;
    $facts['undo_batch_quantiles_seconds'] = wl112_quantiles( $undo_batches );
    wl112_assert( false !== strpos( $progress['body'], 'undone ' . $size ), 'KILL: incomplete Undo' );
    wp_cache_flush_runtime();
    wl112_parity( $ids, '100' );
    $facts['db_after_undo'] = wl112_sizes();
    $facts['history_storage'] = U::storage_estimate();
    $facts['outcome'] = 'PASS';
    $facts['actual_job_size'] = $size;
    wl112_save( $facts );
    echo '#112 ' . DB_HOST . ' ' . $size . ': PASS\n';
} catch ( Throwable $error ) {
    $facts['outcome'] = 'FAIL';
    $facts['error'] = $error->getMessage();
    wl112_save( $facts );
    throw $error;
}
