<?php
// Every timed product operation is a real authenticated Admin HTTP request.
// Fixture creation and read-only independent DB assertions are separate.
//
// Supported sizes complete one frozen category plan in one durable job. Only a
// few reviewed products change per fixture, so a real 1,000-product plan is
// practical in CI: the frozen population stays at $size while Apply/Undo stay
// bounded. One reviewed product is edited externally after approval, so the
// results and Undo must tell the truth about mixed outcomes. A requested size
// above the 1,000 support ceiling is refused before any job or Woo write.
require __DIR__ . '/http.php';
use WriteLeash\Free_Support_Contract as S;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Price_Cache_Verifier as V;
use WriteLeash\Price_Decimal as D;
use WriteLeash\Price_Operation as O;
use WriteLeash\Undo_Repository as U;

global $wpdb, $wl112_cookies, $result_file;
$wl112_cookies = array();
$size = (int) getenv( 'WL112_SIZE' );
$result_file = '/evidence/' . DB_HOST . '-' . $size . '.json';
$facts = array( 'git_sha' => getenv( 'WL112_SHA' ), 'wp' => get_bloginfo( 'version' ), 'woo' => WC_VERSION, 'php' => PHP_VERSION, 'db' => $wpdb->get_var( 'SELECT VERSION()' ), 'cache' => wp_using_ext_object_cache() ? 'Redis 7.4.2 / Redis Object Cache 2.7.0 / Predis' : 'default', 'fixture_product_count' => $size, 'catalog_size' => null, 'requested_job_size' => $size, 'outcome' => 'STARTED', 'fixture_php_memory_limit' => ini_get( 'memory_limit' ), 'supported_job_ceiling' => S::MAX_JOB_PRODUCTS );
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
function wl112_save_counts( int $start ): array {
    $counts = array();
    $lines = is_file( getenv( 'WL112_METRICS' ) ) ? file( getenv( 'WL112_METRICS' ) ) : array();
    foreach ( array_slice( $lines, $start ) as $line ) {
        $entry = json_decode( $line, true );
        foreach ( $entry['woo_save_product_ids'] ?? array() as $id ) { $counts[$id] = ( $counts[$id] ?? 0 ) + 1; }
    }
    return $counts;
}
function wl112_sizes(): array {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s ORDER BY TABLE_NAME', DB_NAME ), ARRAY_A );
}
function wl112_payload( array $job ): array {
    global $wpdb;
    $out = array();
    foreach ( array( 'writeleash_jobs' => array( 'id', (int) $job['id'] ), 'writeleash_job_items' => array( 'job_id', (int) $job['id'] ), 'writeleash_price_items' => array( 'plan_id', $job['plan_id'] ), 'writeleash_undo_operations' => array( 'job_id', (int) $job['id'] ), 'writeleash_undo_items' => array( 'job_id', (int) $job['id'] ) ) as $suffix => $where ) {
        $table = $wpdb->prefix . $suffix;
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) ) ) { continue; }
        $columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
        $terms = array();
        foreach ( $columns as $column ) {
            wl112_assert( (bool) preg_match( '/^[a-z_]+$/', $column ), 'payload identifier' );
            $terms[] = 'COALESCE(OCTET_LENGTH(`' . $column . '`),0)';
        }
        $sql = 'SELECT COUNT(*) AS exact_rows,COALESCE(SUM(' . implode( '+', $terms ) . '),0) AS logical_field_bytes FROM %i WHERE %i=' . ( is_int( $where[1] ) ? '%d' : '%s' );
        $out[$suffix] = $wpdb->get_row( $wpdb->prepare( $sql, $table, $where[0], $where[1] ), ARRAY_A );
    }
    return $out;
}
/**
 * Independent expected regular price per frozen product. A sale-configured
 * product keeps its sale as the shopper-active price, so its parity is read
 * from independent committed storage instead of V::matches().
 */
function wl112_parity_prices( array $regular, int $sale_id = 0, string $sale_expected = '' ): void {
    $db = V::observer();
    try {
        foreach ( $regular as $id => $price ) {
            $id = (int) $id;
            // Before any reconciliation/eviction: cached Woo/meta must already
            // agree with independent committed storage.
            $p = wc_get_product( $id );
            wl112_assert( $p && D::parse( $p->get_regular_price( 'edit' ) ) === D::parse( $price ), 'cached regular parity' );
            wl112_assert( D::parse( get_post_meta( $id, '_regular_price', true ) ) === D::parse( $price ), 'cached metadata parity' );
            if ( $id === $sale_id ) { continue; }
            V::matches( V::storage( $db, $id ), $price );
        }
        if ( $sale_id > 0 ) {
            wl112_assert( isset( $regular[ $sale_id ] ), 'sale product must also be expected' );
            $stored = V::storage( $db, $sale_id );
            wl112_assert( D::parse( $stored['meta']['_regular_price'][0] ) === D::parse( $regular[ $sale_id ] ), 'sale product regular parity' );
            wl112_assert( D::parse( $stored['meta']['_sale_price'][0] ?? '' ) === D::parse( $sale_expected ), 'sale product sale parity' );
            wl112_assert( D::parse( $stored['meta']['_price'][0] ) === D::parse( $sale_expected ), 'sale product active parity' );
        }
    } finally { $db->close(); }
}
/** The externally edited, conflicted frozen product and the two applied ones. */
function wl112_job_plan( array $job ): array {
    $plan = Repo::hydrate_plan( $job );
    $sale_id = 0;
    foreach ( $plan->data()['items'] as $item ) {
        if ( '' !== (string) ( $item['snapshot']['sale_price'] ?? '' ) ) { $sale_id = (int) $item['product_id']; }
    }
    return array( $plan, $sale_id );
}
try {
    $facts['request_start_line'] = is_file( getenv( 'WL112_METRICS' ) ) ? count( file( getenv( 'WL112_METRICS' ) ) ) : 0;
    wl112_save( $facts ); // Preserve exact requested size even on a fatal fixture limit.
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
    $sale_id = 0;
    for ( $i = 0; $i < $size; ++$i ) {
        $p = new WC_Product_Simple();
        $p->set_name( 'WL112-' . $i );
        $p->set_status( 'publish' );
        // One normal reviewed product, one sale-configured reviewed product and
        // one externally edited reviewed product carry the live journey; the
        // rest of the frozen population is already at the target.
        $p->set_regular_price( $i < 3 ? '100.00' : '80.00' );
        if ( 1 === $i ) { $p->set_sale_price( '70.00' ); }
        $p->set_category_ids( array( (int) $term['term_id'] ) );
        $p->save();
        if ( 1 === $i ) { $sale_id = (int) $p->get_id(); }
        $ids[] = $p->get_id();
        if ( 0 === $i % 100 ) { wp_cache_flush_runtime(); }
    }
    $changing = min( 3, $size );
    $facts['fixture_seconds'] = microtime( true ) - $t;
    $facts['fixture_queries'] = $wpdb->num_queries - $fixture_queries;
    $facts['fixture_peak_php_bytes'] = memory_get_peak_usage( true );
    // Earlier fixtures and the one no-CREATE probe remain in this disposable
    // shop. Record the whole catalog truthfully, separately from selected size.
    $catalog = new WP_Query( array( 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'update_post_meta_cache' => false, 'update_post_term_cache' => false ) );
    $facts['catalog_size'] = (int) $catalog->found_posts;
    $facts['db_after_fixture'] = wl112_sizes();
    wl112_save( $facts );
    wl112_login();
    $base = 'http://127.0.0.1:8080/wp-admin/admin.php?page=writeleash-bulk-prices';
    $t = microtime( true );
    $home = wl112_get( $base );
    $before = wl112_evidence_rows();
    // Out-of-range WooCommerce is refused early with an actionable reason;
    // any supported-range release runs the full Preview → Apply → Undo flow.
    if ( ! S::woocommerce_ok() ) {
        wl112_assert( false !== strpos( $home['body'], 'Installed version: ' . WC_VERSION ), 'unsupported Woo version must be visible' );
        wl112_assert( false !== strpos( $home['body'], S::range_text() ), 'supported Woo range must be visible' );
        wl112_assert( false === strpos( $home['body'], 'name="action" value="writeleash_free_preview"' ), 'unsupported Woo exposes preview form' );
        $post = wl112_post( array( 'action' => 'writeleash_free_preview', '_wpnonce' => wl112_nonce( 'writeleash_free_preview' ), 'selector' => 'ids', 'ids' => implode( ',', $ids ), 'operation' => 'SET', 'amount' => '80', 'max_products' => (string) S::MAX_JOB_PRODUCTS, 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '10' ) );
        $page = wl112_get( $post['location'] );
        wl112_assert( false === strpos( $post['location'], 'wl_job=' ), 'unsupported Woo created job reference' );
        wl112_assert( false !== strpos( $page['body'], 'Installed version: ' . WC_VERSION ), 'unsupported Woo POST reason' );
        wl112_assert( false !== strpos( $page['body'], S::range_text() ) && false === strpos( $page['body'], 'security token is missing or invalid' ), 'crafted preview must reach version refusal with a valid HTTP-session nonce' );
        $reason = WriteLeash\Free_Admin::process_preview( array( '_wpnonce' => wl112_nonce( 'writeleash_free_preview' ) ), 'POST' );
        wl112_assert( 'woocommerce_version_unsupported' === $reason['reason'], 'unsupported Woo typed reason' );
        foreach ( array( 'approve', 'resume', 'undo' ) as $action ) {
            $r = wl112_post( array( 'action' => 'writeleash_free_' . $action, 'job' => wp_generate_uuid4(), '_wpnonce' => 'crafted-no-job' ) );
            $denied = wl112_get( $r['location'] );
            wl112_assert( false !== strpos( $denied['body'], 'Installed version: ' . WC_VERSION ), 'unsupported Woo crafted mutation route' );
        }
        wl112_assert( $before === wl112_evidence_rows() && 0 === $before['writeleash_jobs'] && 0 === $before['writeleash_price_items'], 'unsupported Woo created owned evidence' );
        wl112_assert( array() === wl112_save_counts( $facts['request_start_line'] ), 'unsupported Woo saved a product' );
        $fixture_prices = array();
        foreach ( $ids as $index => $id ) { $fixture_prices[ $id ] = $index < 3 ? '100' : '80'; }
        wl112_parity_prices( $fixture_prices, $sale_id, '70' );
        $facts['outcome'] = 'UNSUPPORTED_EARLY';
        $facts['reason'] = 'woocommerce_version_unsupported';
        $facts['actual_job_size'] = 0;
        $facts['final_counts'] = array( 'planned' => 0, 'applied' => 0, 'refused' => $size );
        $facts['owned_evidence_before'] = $before;
        $facts['owned_evidence_after'] = wl112_evidence_rows();
        $facts['cache_lookup_journal_parity'] = 'PASS: unchanged prices and zero evidence';
        $facts['execution_seconds'] = null; $facts['undo_seconds'] = null;
        wl112_save( $facts );
        return;
    }
    $fields = wl112_form( $home['body'], 'writeleash_free_preview' );
    $fields = array_merge( $fields, array( 'selector' => 'category', 'category' => (string) $term['term_id'], 'ids' => '', 'operation' => O::SET, 'amount' => '80', 'max_products' => (string) S::MAX_JOB_PRODUCTS, 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '10' ) );
    unset( $fields['block_zero'] );
    $post = wl112_post( $fields );
    $facts['plan_post_seconds'] = $post['seconds'];
    if ( $size > S::MAX_JOB_PRODUCTS ) {
        wl112_assert( false === strpos( $post['location'], 'wl_job=' ), 'oversized category unexpectedly accepted' );
        $page = wl112_get( $post['location'] );
        wl112_assert( false !== strpos( $page['body'], 'supports up to ' . S::MAX_JOB_PRODUCTS . ' products per job' ), 'oversized category not visibly refused' );
        $fields['selector'] = 'ids';
        $fields['picker_present'] = '0';
        $fields['ids'] = implode( ',', $ids );
        $second = wl112_post( $fields );
        wl112_assert( false === strpos( $second['location'], 'wl_job=' ), 'oversized IDs unexpectedly accepted' );
        $denied = wl112_get( $second['location'] );
        wl112_assert( false !== strpos( $denied['body'], $size . ' products were selected.' ), 'explicit selected count missing' );
        wl112_assert( false !== strpos( $denied['body'], 'No additional job or journal was created and no product was changed.' ), 'typed refusal did not state the durable boundary' );
        wl112_assert( $before === wl112_evidence_rows(), 'oversized request created job/items/journal/Undo evidence' );
        wl112_assert( array() === wl112_save_counts( $facts['request_start_line'] ), 'oversized request issued Woo save' );
        $fixture_prices = array();
        foreach ( $ids as $index => $id ) { $fixture_prices[ $id ] = $index < 3 ? '100' : '80'; }
        wl112_parity_prices( $fixture_prices, $sale_id, '70' );
        $facts['outcome'] = 'REFUSED_BEFORE_JOURNAL';
        $facts['reason'] = 'supported_job_limit_exceeded';
        $facts['classification_reason'] = 'Admin selection above the Free supported ceiling of ' . S::MAX_JOB_PRODUCTS . ' refused before durable import or journal seed; the engineering selector maximum equals the support ceiling. No oversized Apply/Undo throughput exists.';
        $facts['owned_evidence_before'] = $before;
        $facts['owned_evidence_after'] = wl112_evidence_rows();
        $facts['actual_job_size'] = 0;
        $facts['final_counts'] = array( 'planned' => 0, 'applied' => 0, 'refused' => $size );
        $facts['cache_lookup_journal_parity'] = 'PASS: all requested products unchanged; no journal/job created';
        $facts['execution_seconds'] = null;
        $facts['undo_seconds'] = null;
        wl112_save( $facts );
        echo '#112 ' . DB_HOST . ' ' . $size . ': REFUSED_BEFORE_JOURNAL' . "\n";
        return;
    }
    wl112_assert( (bool) preg_match( '/wl_job=([0-9a-f-]{36})/', $post['location'], $m ), 'preview not created' );
    $public = $m[1];
    $job = Repo::read_by_public_id( $public );
    list( $plan, $plan_sale_id ) = wl112_job_plan( $job );
    wl112_assert( $plan_sale_id === $sale_id, 'frozen sale-configured product identity' );
    $preview = wl112_get( $post['location'] );
    $facts['preview_first_page_seconds'] = $preview['seconds'];
    $facts['first_plan_journey_seconds'] = microtime( true ) - $t;
    $seen = array();
    $later = array();
    $facts['evidence_payload_after_plan'] = wl112_payload( $job );
    for ( $offset = 0; $offset < $size; $offset += 20 ) {
        $page = 0 === $offset ? $preview : wl112_get( $post['location'] . '&wl_offset=' . $offset );
        $row_ids = wl112_rows( $page['body'] );
        wl112_assert( $row_ids === array_slice( $ids, $offset, 20 ), 'preview deterministic bounded ordering' );
        $material = $plan->preview_page( $offset, 20 );
        wl112_assert( $plan->summary()['selected'] === $size && $plan->summary()['changing'] === $changing && $material['next_offset'] === ( $offset + 20 < $size ? $offset + 20 : null ), 'preview next/selected/changing total' );
        wl112_assert( false !== strpos( $page['body'], 'Selected ' . $size . ' ' ), 'Admin preview selected total' );
        $seen = array_merge( $seen, $row_ids );
        if ( $offset > 0 ) { $later[] = $page['seconds']; }
    }
    wl112_assert( $seen === $ids, 'all preview pages cover frozen catalog exactly' );
    $facts['preview_later_page_seconds'] = $later;
    require __DIR__ . '/preview-csv.php';
    // Scheduler cannot accept the approval wake-up. Domain truth must pause.
    update_option( 'wl112_scheduler_down', true, false );
    $approval = wl112_post( wl112_form( $preview['body'], 'writeleash_free_approve' ) );
    $facts['approval_seconds'] = $approval['seconds'];
    $url = $approval['location'];
    $progress = wl112_get( $url );
    $facts['approval_durable_state'] = Repo::read( (int) $job['id'] )['status'];
    wl112_assert( false !== strpos( $progress['body'], 'pending ' . $changing ), 'approval falsely implies mutation' );
    $approved_at = microtime( true );
    sleep( 2 );
    wl112_login();
    $progress = wl112_get( $url );
    wl112_assert( false !== strpos( $progress['body'], 'pending ' . $changing ), 'no-traffic reopen lost pending truth' );
    delete_option( 'wl112_scheduler_down' );
    $facts['scheduler_lag_lower_bound_seconds'] = microtime( true ) - $approved_at;
    $facts['scheduler_mode'] = 'DISABLE_WP_CRON=true; async loopback runner denied; no traffic; enqueue unavailable at approval; recovered by protected Admin resume';
    // A merchant edits one reviewed product directly in WooCommerce after
    // approval. It must be preserved, reported and never overwritten.
    $conflict_id = (int) $ids[2];
    V::invalidate( $conflict_id );
    $external = wc_get_product( $conflict_id );
    $external->set_regular_price( '75.00' );
    $external->save();
    $t = microtime( true );
    $batches = array();
    for ( $i = 0; $i < (int) ceil( $size / 10 ) + 5; ++$i ) {
        $progress = wl112_get( $url );
        $batch = wl112_post( wl112_form( $progress['body'], 'writeleash_free_resume' ) );
        $batches[] = $batch['seconds'];
        $current = Repo::read( (int) $job['id'] );
        if ( in_array( $current['status'], array( 'COMPLETED', 'COMPLETED_WITH_ISSUES' ), true ) ) { break; }
        wl112_assert( ! in_array( $current['status'], array( 'NEEDS_REVIEW', 'CANCELLED' ), true ), 'KILL: scale Apply correctness failure' );
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
    $facts['items_per_second_including_progress_http'] = $changing / max( $facts['execution_seconds'], 0.0001 );
    $progress = wl112_get( $url );
    $facts['final_counts'] = Repo::counts( (int) $job['id'] );
    wl112_assert( 2 === $facts['final_counts']['applied'] && 1 === $facts['final_counts']['conflict'] && 0 === $facts['final_counts']['pending'] && $size - $changing === $facts['final_counts']['unchanged'], 'KILL: untruthful mixed Apply outcome' );
    $current = Repo::read( (int) $job['id'] );
    wl112_assert( 'COMPLETED_WITH_ISSUES' === $current['status'], 'external edit must leave a truthful mixed terminal state' );
    $save_counts = wl112_save_counts( $facts['request_start_line'] );
    foreach ( array( $ids[0], $ids[1] ) as $id ) { wl112_assert( 1 === ( $save_counts[$id] ?? 0 ), 'KILL: duplicate or missing Woo save' ); }
    wl112_assert( 0 === ( $save_counts[$conflict_id] ?? 0 ), 'KILL: externally edited product was overwritten' );
    wl112_assert( 2 === count( $save_counts ), 'KILL: Woo save outside the two applied frozen products' );
    $facts['exactly_one_apply_save_per_applied_product'] = 'PASS';
    wp_cache_flush_runtime();
    $expected_apply = array_fill_keys( $ids, '80' );
    $expected_apply[ $conflict_id ] = '75';
    wl112_parity_prices( $expected_apply, $sale_id, '70' );
    V::observe( $plan, (int) $ids[0] );
    V::observe( $plan, (int) $ids[1] );
    $facts['cache_lookup_journal_parity'] = 'PASS: sale preserved on regular edit; conflict preserved at 75';
    $facts['db_after_apply'] = wl112_sizes();
    $facts['evidence_payload_after_apply'] = wl112_payload( $job );
    wl112_assert( false !== strpos( $progress['body'], 'Finished with products needing attention' ), 'mixed outcome label missing' );
    wl112_assert( false !== strpos( $progress['body'], 'Review conflicts and failed products below, then create a new preview if needed.' ), 'conflict next steps missing' );
    $conflict_page = wl112_get( $url . '&wl_filter=conflict' );
    wl112_assert( array( $conflict_id ) === wl112_rows( $conflict_page['body'] ), 'conflict filter must list the externally edited product' );
    $facts['conflict_next_steps'] = 'PASS: mixed state shown with review/no-overwrite copy and a conflict-filtered item';
    $hist = wl112_get( $base . '&wl_view=history' );
    wl112_assert( false !== strpos( $hist['body'], substr( $public, 0, 8 ) ), 'Admin history missing job' );
    $seen = array();
    for ( $offset = 0; $offset < $size; $offset += 50 ) {
        $page = wl112_get( $url . '&wl_offset=' . $offset );
        wl112_assert( wl112_rows( $page['body'] ) === array_slice( $ids, $offset, 50 ), 'history bounded deterministic ordering' );
        $material = U::history_items( (int) $job['id'], null, null, $offset, 50 );
        wl112_assert( $material['total'] === $size && $material['next_offset'] === ( $offset + 50 < $size ? $offset + 50 : null ), 'history next/total' );
    }
    wl112_assert( false !== strpos( $page['body'], 'Expected and planned are Regular price values.' ), 'history states the changed price field' );
    wl112_save( $facts );
    $t = microtime( true );
    $undo_batches = array();
    for ( $i = 0; $i < (int) ceil( $size / 10 ) + 5; ++$i ) {
        $progress = wl112_get( $url );
        $op = U::read_operation_by_job( (int) $job['id'] );
        if ( is_array( $op ) && in_array( $op['status'], array( 'UNDO_COMPLETED', 'UNDO_COMPLETED_WITH_ISSUES' ), true ) ) { break; }
        $batch = wl112_post( wl112_form( $progress['body'], 'writeleash_free_undo' ) );
        $undo_batches[] = $batch['seconds'];
        wl112_assert( ! in_array( $op['status'] ?? '', array( 'UNDO_NEEDS_REVIEW', 'UNDO_CANCELLED' ), true ), 'KILL: scale Undo correctness failure' );
    }
    $facts['undo_seconds'] = microtime( true ) - $t;
    $facts['undo_batch_seconds'] = $undo_batches;
    $facts['undo_batch_quantiles_seconds'] = wl112_quantiles( $undo_batches );
    $op = U::read_operation_by_job( (int) $job['id'] );
    wl112_assert( 'UNDO_COMPLETED' === $op['status'] && 2 === (int) $op['undone'] && 0 === (int) $op['undo_conflict'], 'KILL: incomplete or conflicted Undo of the applied products' );
    $progress = wl112_get( $url );
    wl112_assert( false !== strpos( $progress['body'], '2 restored' ), 'KILL: untruthful Undo summary' );
    $save_counts = wl112_save_counts( $facts['request_start_line'] );
    foreach ( array( $ids[0], $ids[1] ) as $id ) { wl112_assert( 2 === ( $save_counts[$id] ?? 0 ), 'KILL: duplicate or missing Undo save' ); }
    wl112_assert( 0 === ( $save_counts[$conflict_id] ?? 0 ) && 2 === count( $save_counts ), 'KILL: Undo saved an unapplied product' );
    wp_cache_flush_runtime();
    // Only the first three reviewed products started at 100.00; the rest of
    // the frozen population was already at the 80.00 target and stays there.
    $expected_undo = array();
    foreach ( $ids as $index => $id ) { $expected_undo[ $id ] = $index < 3 ? '100' : '80'; }
    $expected_undo[ $conflict_id ] = '75';
    wl112_parity_prices( $expected_undo, $sale_id, '70' );
    $facts['db_after_undo'] = wl112_sizes();
    $facts['evidence_payload_after_undo'] = wl112_payload( $job );
    $facts['history_storage'] = U::storage_estimate();

    // Sale-price target leg: the same Admin flow with the sale field. A
    // first-class sale edit must change only the sale price and preserve the
    // regular baseline; Undo restores the previous sale.
    $sale_metric_start = is_file( getenv( 'WL112_METRICS' ) ) ? count( file( getenv( 'WL112_METRICS' ) ) ) : 0;
    $home = wl112_get( $base );
    $sale_fields = wl112_form( $home['body'], 'writeleash_free_preview' );
    $sale_fields = array_merge( $sale_fields, array( 'selector' => 'ids', 'ids' => (string) $sale_id, 'category' => '', 'picker_present' => '0', 'operation' => O::INCREASE_FIXED, 'price_field' => O::FIELD_SALE, 'amount' => '5', 'max_products' => (string) S::MAX_JOB_PRODUCTS, 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '10' ) );
    unset( $sale_fields['block_zero'] );
    $sale_post = wl112_post( $sale_fields );
    wl112_assert( (bool) preg_match( '/wl_job=([0-9a-f-]{36})/', $sale_post['location'], $sm ), 'sale preview not created' );
    $sale_job = Repo::read_by_public_id( $sm[1] );
    $sale_plan = Repo::hydrate_plan( $sale_job );
    wl112_assert( O::FIELD_SALE === $sale_plan->price_field(), 'sale plan must target the sale field' );
    $sale_item = $sale_plan->item( $sale_id )->data();
    wl112_assert( 'CHANGING' === $sale_item['result'] && '70' === $sale_item['expected_regular_price'] && '75.00' === $sale_item['planned_regular_price'], 'sale target arithmetic' );
    $sale_preview = wl112_get( $sale_post['location'] );
    wl112_assert( false !== strpos( $sale_preview['body'], 'Price to change: Sale price' ), 'sale preview must state the changed field' );
    update_option( 'wl112_scheduler_down', true, false );
    $sale_approval = wl112_post( wl112_form( $sale_preview['body'], 'writeleash_free_approve' ) );
    $sale_url = $sale_approval['location'];
    $sale_progress = wl112_get( $sale_url );
    delete_option( 'wl112_scheduler_down' );
    for ( $i = 0; $i < 3; ++$i ) {
        if ( 'COMPLETED' === Repo::read( (int) $sale_job['id'] )['status'] ) { break; }
        wl112_post( wl112_form( $sale_progress['body'], 'writeleash_free_resume' ) );
        $sale_progress = wl112_get( $sale_url );
    }
    wl112_assert( 'COMPLETED' === Repo::read( (int) $sale_job['id'] )['status'], 'sale target apply did not complete' );
    wp_cache_flush_runtime();
    wl112_parity_prices( array( $sale_id => '100' ), $sale_id, '75' );
    V::observe( $sale_plan, $sale_id );
    for ( $i = 0; $i < 3; ++$i ) {
        $sale_op = U::read_operation_by_job( (int) $sale_job['id'] );
        if ( is_array( $sale_op ) && in_array( $sale_op['status'], array( 'UNDO_COMPLETED', 'UNDO_COMPLETED_WITH_ISSUES' ), true ) ) { break; }
        wl112_post( wl112_form( $sale_progress['body'], 'writeleash_free_undo' ) );
        $sale_progress = wl112_get( $sale_url );
    }
    $sale_op = U::read_operation_by_job( (int) $sale_job['id'] );
    wl112_assert( 'UNDO_COMPLETED' === $sale_op['status'] && 1 === (int) $sale_op['undone'], 'sale Undo did not restore the sale price' );
    wp_cache_flush_runtime();
    wl112_parity_prices( array( $sale_id => '100' ), $sale_id, '70' );
    $sale_saves = wl112_save_counts( $sale_metric_start );
    wl112_assert( 2 === ( $sale_saves[ $sale_id ] ?? 0 ) && 1 === count( $sale_saves ), 'sale apply/Undo must save exactly the sale product twice' );
    $facts['sale_target'] = array( 'field' => O::FIELD_SALE, 'preview' => 'PASS: sale target 75.00 with regular 100 preserved', 'apply' => 'PASS: sale 75 active, regular preserved', 'undo' => 'PASS: sale restored to 70, regular preserved' );

    $facts['outcome'] = 'PASS';
    $facts['actual_job_size'] = $size;
    $facts['mixed_outcome'] = 'PASS: 2 applied, 1 externally edited conflict preserved, 2 restored';
    wl112_save( $facts );
    echo '#112 ' . DB_HOST . ' ' . $size . ': PASS' . "\n";
} catch ( Throwable $error ) {
    $facts['outcome'] = 'FAIL';
    $facts['error'] = $error->getMessage();
    wl112_save( $facts );
    throw $error;
}
