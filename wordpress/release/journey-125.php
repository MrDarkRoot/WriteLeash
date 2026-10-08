<?php
// Exact installed ZIP only: real authenticated Admin requests, independent observers.
require __DIR__ . '/http-125.php';
use WriteLeash\Job_Repository as R;
use WriteLeash\Undo_Repository as U;
use WriteLeash\Price_Cache_Verifier as V;
use WriteLeash\Price_Decimal as D;
use WriteLeash\Free_Admin as A;
use WriteLeash\Price_Apply_Journal as J;
global $wpdb, $report, $base, $wl112_cookies;
$wl112_cookies = array();
$base = getenv( 'WL125_URL' ) . '/wp-admin/admin.php?page=writeleash-bulk-prices';
$report = array( 'configuration' => getenv( 'WL125_LABEL' ), 'outcome' => 'STARTED', 'operations' => array(), 'checks' => array(), 'external_effects' => 'OUTSIDE_CONTRACT' );
function j125_record(): void { global $report; file_put_contents( getenv( 'WL125_RESULT' ), json_encode( $report, JSON_PRETTY_PRINT ) . "\n" ); }
function j125_check( bool $ok, string $label ): void { global $report; if ( ! $ok ) { $report['outcome'] = 'FAIL'; $report['finding'] = $label; j125_record(); throw new RuntimeException( 'KILL: ' . $label ); } }
function j125_product( string $name, string $price = '18.00' ): int { $p = new WC_Product_Simple(); $p->set_name( 'WL125-synthetic-' . $name ); $p->set_status( 'publish' ); $p->set_regular_price( $price ); return $p->save(); }
function j125_edit( int $id, callable $change ): void { V::invalidate( $id ); $p = wc_get_product( $id ); $change( $p ); $p->save(); }
function j125_mark(): int { return is_file( getenv( 'WL112_METRICS' ) ) ? count( file( getenv( 'WL112_METRICS' ) ) ) : 0; }
function j125_saves( int $mark ): array {
    $counts = array(); $lines = is_file( getenv( 'WL112_METRICS' ) ) ? file( getenv( 'WL112_METRICS' ) ) : array();
    foreach ( array_slice( $lines, $mark ) as $line ) { foreach ( json_decode( $line, true )['woo_save_product_ids'] ?? array() as $id ) { $counts[$id] = ( $counts[$id] ?? 0 ) + 1; } }
    return $counts;
}
function j125_fresh( array $expected ): array {
    $cmd = array( 'php', '-d', 'zend.exception_ignore_args=1', '/usr/local/bin/wp', '--path=' . ABSPATH, 'eval-file', '/repo/wordpress/release/observer-125.php', json_encode( $expected ) );
    $p = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes ); fclose( $pipes[0] );
    $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
    j125_check( 0 === proc_close( $p ), 'fresh independent Woo/storage observer failed' ); return json_decode( $out, true );
}
function j125_plan( array $ids, string $operation = 'DECREASE_PERCENT', string $amount = '20', array $override = array() ): array {
    global $base;
    $home = wl112_get( $base );
    $fields = array_merge( wl112_form( $home['body'], A::ACTION_PREVIEW ), array( 'selector' => 'ids', 'ids' => implode( ',', $ids ), 'operation' => $operation, 'amount' => $amount, 'max_products' => '100', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '10', 'block_zero' => '1' ), $override );
    $mark = j125_mark(); $response = wl112_post( $fields );
    j125_check( (bool) preg_match( '/wl_job=([0-9a-f-]{36})/', $response['location'], $m ), 'preview not created' );
    $page = wl112_get( $response['location'] ); $job = R::read_by_public_id( $m[1] );
    j125_check( array() === j125_saves( $mark ), 'unpreviewed product mutation during preview' );
    return array( 'job' => $job, 'plan' => R::hydrate_plan( $job ), 'preview' => $page, 'url' => $response['location'] );
}
function j125_approve( array $f, array $extra = array() ): array {
    $post = wl112_post( array_merge( wl112_form( $f['preview']['body'], A::ACTION_APPROVE ), $extra ) );
    $job = R::read( (int) $f['job']['id'] );
    j125_check( $job['plan_json'] === $f['job']['plan_json'] && $job['plan_hash'] === $f['job']['plan_hash'], 'approval material changed' );
    return array( 'url' => $post['location'], 'page' => wl112_get( $post['location'] ) );
}
function j125_resume( array $progress ): array {
    wl112_post( wl112_form( $progress['page']['body'], A::ACTION_RESUME ) );
    $progress['page'] = wl112_get( $progress['url'] ); return $progress;
}
function j125_finish( array $f, array $progress ): array {
    for ( $i = 0; $i < 20; ++$i ) {
        $job = R::read( (int) $f['job']['id'] );
        if ( WriteLeash\Job_State::is_terminal( $job['status'] ) ) { return $progress; }
        $progress = j125_resume( $progress );
    }
    j125_check( false, 'Apply did not finish bounded resumes' ); return $progress;
}
function j125_undo( array $f, array $progress ): array {
    for ( $i = 0; $i < 20; ++$i ) {
        wl112_post( wl112_form( $progress['page']['body'], A::ACTION_UNDO ) ); $progress['page'] = wl112_get( $progress['url'] );
        $op = U::read_operation_by_job( (int) $f['job']['id'] );
        if ( WriteLeash\Undo_State::is_terminal( $op['status'] ) ) { return $progress; }
    }
    j125_check( false, 'Undo did not finish bounded requests' ); return $progress;
}
function j125_snapshot(): array {
    global $wpdb; $rows = array();
    foreach ( array_keys( wl112_evidence_rows() ) as $suffix ) { $table = $wpdb->prefix . $suffix; $rows[$suffix] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A ); }
    return $rows;
}
try {
    wp_set_current_user( 1 );
    foreach ( array( 'shop' => 'shop_manager', 'other' => 'shop_manager', 'low' => 'subscriber' ) as $name => $role ) {
        $existing = get_user_by( 'login', 'wl125-' . $name );
        $id = $existing ? wp_update_user( array( 'ID' => $existing->ID, 'user_pass' => getenv( 'WL125_PASSWORD' ) ) ) : wp_insert_user( array( 'user_login' => 'wl125-' . $name, 'user_pass' => getenv( 'WL125_PASSWORD' ), 'role' => $role ) ); j125_check( ! is_wp_error( $id ), 'synthetic actor creation' );
    }
    $owner = get_user_by( 'login', 'wl125-shop' )->ID; wp_set_current_user( $owner ); wl112_login( 'wl125-shop' );
    foreach ( array( 'SET' => array( '15', '15' ), 'INCREASE_FIXED' => array( '2', '20' ), 'DECREASE_FIXED' => array( '2', '16' ), 'INCREASE_PERCENT' => array( '10', '19.8' ), 'DECREASE_PERCENT' => array( '20', '14.4' ) ) as $operation => $spec ) {
        $id = j125_product( $operation ); $f = j125_plan( array( $id ), $operation, $spec[0] ); $item = $f['plan']->item( $id )->data();
        j125_check( D::parse( $item['expected_regular_price'] ) === '18' && D::parse( $item['planned_regular_price'] ) === $spec[1], 'operation exact frozen target' );
        $mark = j125_mark();
        $progress = j125_finish( $f, j125_approve( $f, array( 'selector' => 'category', 'operation' => 'SET', 'amount' => '999', 'max_products' => '0', 'target' => '999' ) ) );
        $truth = j125_fresh( array( $id => $spec[1] ) ); $job = R::read( (int) $f['job']['id'] ); $journal = J::read( $GLOBALS['wpdb'], $job['plan_id'], $id );
        j125_check( 'COMPLETED' === $job['status'] && (int) $job['applied'] === 1 && 'APPLIED' === $journal['state'] && 1 === ( j125_saves( $mark )[$id] ?? 0 ), 'operation durable Apply or duplicate completed save' );
        $report['operations'][$operation] = array( 'classification' => 'PASS', 'job_id' => (int) $job['id'], 'plan_id' => $job['plan_id'], 'plan_hash' => $job['plan_hash'], 'expected' => $item['expected_regular_price'], 'absolute_target' => $item['planned_regular_price'], 'counts' => R::counts( (int) $job['id'] ), 'journal_state' => $journal['state'], 'fresh_truth' => $truth, 'apply_saves' => 1 );
        j125_undo( $f, $progress ); j125_fresh( array( $id => '18' ) );
    }
    $report['checks']['ALL_FIVE_OPERATIONS'] = 'PASS'; $report['checks']['APPROVAL_POST_FIELDS_CANNOT_REPLAN'] = 'PASS'; j125_record();
    // Immutable material rejects changed selector/operation/amount/policy/population/target.
    $data = $f['plan']->data();
    foreach ( array( 'selector', 'operation', 'amount', 'policy', 'population', 'target' ) as $change ) {
        $bad = $data;
        if ( 'selector' === $change ) { $bad['selection']['type'] = 'CATEGORY'; }
        if ( 'operation' === $change ) { $bad['operation']['type'] = 'SET'; }
        if ( 'amount' === $change ) { $bad['operation']['input'] = '999'; }
        if ( 'policy' === $change ) { $bad['policy_snapshot']['max_products_changed'] = 0; }
        if ( 'population' === $change ) { $bad['resolved_product_ids'][] = 999999999; }
        if ( 'target' === $change ) { $bad['items'][0]['planned_regular_price'] = '999'; }
        $material = $bad; unset( $material['plan_id'], $material['created_at'], $material['plan_hash'], $material['summary'] );
        $bad['plan_hash'] = WriteLeash\Plan_Hasher::hash( $material );
        $rejected = false;
        try { R::assert_binding( $f['job'], WriteLeash\Change_Plan::hydrate( $bad ) ); } catch ( Throwable $e ) { $rejected = true; }
        j125_check( $rejected, 'changed approval material accepted: ' . $change );
    }
    $report['checks']['APPROVAL_BINDING'] = 'PASS';
    // Exact SKU / direct category, including a descendant not selected.
    $sku = j125_product( 'exact-sku' ); j125_edit( $sku, static function ( $p ) { $p->set_sku( 'WL125-EXACT-SKU' ); } );
    $fsku = j125_plan( array(), 'SET', '16', array( 'selector' => 'sku', 'sku' => 'WL125-EXACT-SKU' ) );
    j125_check( $fsku['plan']->data()['resolved_product_ids'] === array( $sku ), 'exact SKU population' );
    $root = wp_insert_term( 'WL125-direct', 'product_cat' ); $child = wp_insert_term( 'WL125-descendant', 'product_cat', array( 'parent' => $root['term_id'] ) );
    $direct = array( j125_product( 'direct-0' ), j125_product( 'direct-1' ) ); $desc = j125_product( 'descendant' );
    foreach ( $direct as $id ) { j125_edit( $id, static function ( $p ) use ( $root ) { $p->set_category_ids( array( (int) $root['term_id'] ) ); } ); }
    j125_edit( $desc, static function ( $p ) use ( $child ) { $p->set_category_ids( array( (int) $child['term_id'] ) ); } );
    $fc = j125_plan( array(), 'SET', '16', array( 'selector' => 'category', 'category' => (string) $root['term_id'] ) );
    j125_check( $fc['plan']->data()['resolved_product_ids'] === $direct, 'category included descendants' );
    j125_edit( $sku, static function ( $p ) { $p->set_sku( 'WL125-CHANGED-SKU' ); $p->set_name( 'WL125-new synthetic title' ); } );
    foreach ( $direct as $id ) { j125_edit( $id, static function ( $p ) { $p->set_category_ids( array() ); } ); }
    $added = j125_product( 'new-category-match' ); j125_edit( $added, static function ( $p ) use ( $root ) { $p->set_category_ids( array( (int) $root['term_id'] ) ); } );
    foreach ( array( $fsku, $fc ) as $selected ) { j125_finish( $selected, j125_approve( $selected ) ); }
    j125_fresh( array( $sku => '16', $direct[0] => '16', $direct[1] => '16', $desc => '18', $added => '18' ) );
    $report['checks']['EXACT_SKU_DIRECT_CATEGORY_FROZEN_POPULATION_PROVENANCE_ONLY_DRIFT'] = 'PASS';
    // Preview pagination and exclusions; navigation never saves products or changes material.
    $page_ids = array(); for ( $i = 0; $i < 41; ++$i ) { $page_ids[] = j125_product( 'page-' . $i ); }
    $draft = j125_product( 'draft' ); j125_edit( $draft, static function ( $p ) { $p->set_status( 'draft' ); } );
    $sale = j125_product( 'sale' ); j125_edit( $sale, static function ( $p ) { $p->set_sale_price( '12' ); } );
    $empty = j125_product( 'empty' ); j125_edit( $empty, static function ( $p ) { $p->set_regular_price( '' ); } );
    $unchanged = j125_product( 'unchanged', '14.40' );
    $all = array_merge( $page_ids, array( $draft, $sale, $empty, $unchanged ) ); $fp = j125_plan( $all, 'SET', '14.40' ); $seen = array(); $mark = j125_mark();
    foreach ( array( 0, 20, 40 ) as $offset ) { $page = wl112_get( $fp['url'] . '&wl_offset=' . $offset ); $seen = array_merge( $seen, wl112_rows( $page['body'] ) ); j125_check( R::read( (int) $fp['job']['id'] )['plan_json'] === $fp['job']['plan_json'], 'preview pagination changed material' ); }
    j125_check( $seen === $all && array() === j125_saves( $mark ) && $fp['plan']->summary()['unsupported'] === 3 && $fp['plan']->summary()['unchanged'] === 1, 'pagination/exclusions/unchanged contract' );
    $new = j125_plan( $all, 'SET', '13' ); j125_check( $new['job']['plan_id'] !== $fp['job']['plan_id'], 'changed user inputs reused plan' );
    $report['checks']['PREVIEW_PAGINATION_FROZEN_PLAN_EXCLUSIONS'] = 'PASS';
    // Exact policy thresholds: limits block whole plans, warnings never override blockers.
    foreach ( array(
        array( 'max-products-equal', 'SET', '20', array( 'max_products' => '2' ), false ),
        array( 'max-products-block', 'SET', '20', array( 'max_products' => '1' ), true ),
        array( 'increase-equal', 'SET', '19.80', array( 'max_increase' => '10' ), false ),
        array( 'increase-over', 'SET', '19.80', array( 'max_increase' => '9.999999', 'warning_threshold' => '1' ), true ),
        array( 'decrease-equal', 'SET', '14.40', array( 'max_decrease' => '20' ), false ),
        array( 'decrease-over', 'SET', '14.40', array( 'max_decrease' => '19.999999', 'warning_threshold' => '1' ), true ),
        array( 'zero-block', 'SET', '0', array( 'block_zero' => '1' ), true ),
        array( 'warning-equal', 'SET', '19.80', array( 'warning_threshold' => '10' ), false ),
        array( 'warning-over', 'SET', '19.80', array( 'warning_threshold' => '9.999999' ), false )
    ) as $case ) {
        $ids = array( j125_product( 'policy-' . $case[0] . '-0' ), j125_product( 'policy-' . $case[0] . '-1' ) ); $policy = j125_plan( $ids, $case[1], $case[2], $case[3] );
        j125_check( ( 'BLOCKED' === $policy['job']['status'] ) === $case[4], 'policy threshold ' . $case[0] );
        if ( 'warning-equal' === $case[0] ) { j125_check( 0 === $policy['plan']->summary()['warning_items'], 'warning equality' ); }
        if ( 'warning-over' === $case[0] ) { j125_check( 2 === $policy['plan']->summary()['warning_items'], 'warning strict threshold' ); }
        if ( $case[4] ) {
            $rows = wl112_evidence_rows(); $mark = j125_mark();
            wl112_post( array( 'action' => A::ACTION_APPROVE, 'job' => $policy['job']['public_id'], '_wpnonce' => wl112_nonce( A::ACTION_APPROVE . '_' . $policy['job']['plan_id'] ) ) );
            j125_check( R::read( (int) $policy['job']['id'] )['status'] === 'BLOCKED' && $rows === wl112_evidence_rows() && array() === j125_saves( $mark ), 'blocked policy became executable or seeded journal' );
        }
    }
    $report['checks']['POLICY_BOUNDARIES_BLOCK_ENTIRE_PLAN'] = 'PASS'; j125_record();
    // Real partial job, price and sale/status drift, truthful Current column.
    $mixed = array( j125_product( 'partial-clean' ), j125_product( 'partial-price' ), j125_product( 'partial-sale' ) ); $fm = j125_plan( $mixed ); $progress = j125_approve( $fm );
    j125_edit( $mixed[1], static function ( $p ) { $p->set_regular_price( '21' ); } ); j125_edit( $mixed[2], static function ( $p ) { $p->set_sale_price( '10' ); } );
    $mark = j125_mark(); $progress = j125_finish( $fm, $progress ); $job = R::read( (int) $fm['job']['id'] ); $counts = R::counts( (int) $job['id'] );
    j125_check( 'COMPLETED_WITH_ISSUES' === $job['status'] && $counts['applied'] === 1 && $counts['conflict'] === 2 && j125_saves( $mark ) === array( $mixed[0] => 1 ), 'blind overwrite/false global success or false APPLIED' );
    j125_fresh( array( $mixed[0] => '14.4', $mixed[1] => '21' ) );
    j125_check( strpos( $progress['page']['body'], 'Current' ) !== false && strpos( $progress['page']['body'], 'COMPLETED_WITH_ISSUES' ) !== false && strpos( $progress['page']['body'], 'conflict 2' ) !== false, 'partial UI hides conflict/current truth' );
    $dom = new DOMDocument(); libxml_use_internal_errors( true ); $dom->loadHTML( $progress['page']['body'] ); libxml_clear_errors(); $xpath = new DOMXPath( $dom );
    $cells = $xpath->query( '//table/tbody/tr[td[1]="' . $mixed[1] . '"]/td' );
    j125_check( $cells->length >= 4 && trim( $cells->item(1)->textContent ) === '18' && trim( $cells->item(2)->textContent ) === '21' && trim( $cells->item(3)->textContent ) === '14.40', 'Expected/Current/Planned UI values differ from durable/fresh truth' );
    foreach ( array( 1, 2 ) as $n ) { j125_check( 'CONFLICT' === J::read( $GLOBALS['wpdb'], $job['plan_id'], $mixed[$n] )['state'], 'durable conflict outcome missing' ); }
    wl112_login( 'wl125-shop' ); $reopened = wl112_get( $progress['url'] ); j125_check( strpos( $reopened['body'], 'conflict 2' ) !== false, 'fresh-session partial result lost' );
    $report['partial'] = array( 'status' => $job['status'], 'counts' => $counts, 'price_expected' => $fm['plan']->item( $mixed[1] )->data()['expected_regular_price'], 'price_current' => j125_fresh( array( $mixed[1] => '21' ) )[$mixed[1]]['stored_regular'], 'price_planned' => $fm['plan']->item( $mixed[1] )->data()['planned_regular_price'], 'woo_saves' => j125_saves( $mark ) );
    $report['checks']['STALE_PRICE_OTHER_PRECONDITION_PARTIAL_NO_BLIND_OVERWRITE'] = 'PASS';
    // External equality with the target is still a stale conflict, never evidence of our Apply.
    $eqid = j125_product( 'external-target-equality' ); $eqf = j125_plan( array( $eqid ) ); $eqp = j125_approve( $eqf );
    j125_edit( $eqid, static function ( $p ) { $p->set_regular_price( '14.40' ); } ); $mark = j125_mark(); $eqp = j125_finish( $eqf, $eqp ); $eqjob = R::read( (int) $eqf['job']['id'] );
    j125_check( (int) $eqjob['applied'] === 0 && (int) $eqjob['conflict'] === 1 && 'CONFLICT' === J::read( $GLOBALS['wpdb'], $eqjob['plan_id'], $eqid )['state'] && array() === j125_saves( $mark ), 'target equality falsely fabricated APPLIED' );
    $report['checks']['TARGET_EQUALITY_WITHOUT_JOURNAL_PROVEN_APPLY_IS_CONFLICT'] = 'PASS';
    // Eligible partial Undo; one later edit stays newer while another applied product restores.
    $undo_ids = array( j125_product( 'undo-clean' ), j125_product( 'undo-newer' ) ); $fu = j125_plan( $undo_ids ); $pu = j125_finish( $fu, j125_approve( $fu ) );
    j125_edit( $undo_ids[1], static function ( $p ) { $p->set_regular_price( '25' ); } ); $mark = j125_mark(); $pu = j125_undo( $fu, $pu ); $op = U::read_operation_by_job( (int) $fu['job']['id'] ); $uc = U::counts( (int) $op['id'] );
    j125_check( 'UNDO_COMPLETED_WITH_ISSUES' === $op['status'] && $uc['undone'] === 1 && $uc['conflict'] === 1 && j125_saves( $mark ) === array( $undo_ids[0] => 1 ), 'unsafe Undo or false partial Undo success' );
    $report['undo_conflict'] = array( 'status' => $op['status'], 'counts' => $uc, 'truth' => j125_fresh( array( $undo_ids[0] => '18', $undo_ids[1] => '25' ) ) );
    $report['checks']['ELIGIBLE_UNDO_UNDO_CONFLICT_NEWER_VALUE_PRESERVED'] = 'PASS';
    // Duplicate registered wakeups and a repeated protected Resume do no completed save.
    $before = j125_snapshot(); $mark = j125_mark(); do_action( WriteLeash\Job_Scheduler::HOOK, (int) $fm['job']['id'] ); do_action( WriteLeash\Job_Scheduler::HOOK, (int) $fm['job']['id'] );
    wl112_post( array( 'action' => A::ACTION_RESUME, 'job' => $fm['job']['public_id'], '_wpnonce' => wl112_nonce( A::ACTION_RESUME . '_' . $fm['job']['public_id'] ) ) );
    j125_check( $before === j125_snapshot() && array() === j125_saves( $mark ), 'duplicate completed Apply/evidence' );
    $report['checks']['DUPLICATE_WAKE_RETRY_NO_DOUBLE_PERCENT_APPLY'] = 'PASS'; j125_record();
    // Scheduler interruption is real; a worker is SIGKILLed inside an uncommitted Woo save.
    $crash_ids = array(); for ( $i = 0; $i < 12; ++$i ) { $crash_ids[] = j125_product( 'interrupt-' . $i ); }
    $fi = j125_plan( $crash_ids ); update_option( 'wl112_scheduler_down', true, false ); $pi = j125_approve( $fi ); delete_option( 'wl112_scheduler_down' );
    $mark = j125_mark(); $pi = j125_resume( $pi ); $before_crash = R::read( (int) $fi['job']['id'] );
    j125_check( (int) $before_crash['applied'] === 10 && (int) $before_crash['pending'] === 2, 'bounded partial interruption fixture' );
    wl112_login( 'wl125-shop' ); $pi['page'] = wl112_get( $pi['url'] ); j125_check( strpos( $pi['page']['body'], 'applied 10' ) !== false && strpos( $pi['page']['body'], 'pending 2' ) !== false, 'browser-close reopen lost durable partial truth' );
    $prefix = '/work/tmp/' . getenv( 'WL125_LABEL' ) . '-kill'; $spec = array( 'job_id' => (int) $fi['job']['id'], 'started' => $prefix . '.started', 'barrier' => $prefix . '.barrier' ); file_put_contents( $prefix . '.json', json_encode( $spec ) );
    $worker = proc_open( array( 'php', '-d', 'zend.exception_ignore_args=1', '/usr/local/bin/wp', '--path=' . ABSPATH, 'eval-file', '/repo/wordpress/release/worker-125.php', $prefix . '.json' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes ); fclose( $pipes[0] );
    $deadline = microtime( true ) + 30; while ( ! is_file( $spec['barrier'] ) ) { j125_check( microtime( true ) < $deadline, 'actual worker kill barrier unavailable' ); usleep( 10000 ); }
    posix_kill( (int) file_get_contents( $spec['started'] ), 9 ); proc_close( $worker );
    $observer = V::observer(); try { V::matches( V::storage( $observer, $crash_ids[10] ), '18' ); } finally { $observer->close(); }
    sleep( 61 ); // Real configured lease TTL; no durable authority or price rows are forged.
    $pi['page'] = wl112_get( $pi['url'] ); j125_check( strpos( $pi['page']['body'], 'LEASE_RECOVERY' ) !== false, 'stalled lease not truthful in UI' );
    $pi = j125_finish( $fi, $pi ); $completed = R::read( (int) $fi['job']['id'] );
    j125_check( $completed['plan_json'] === $fi['job']['plan_json'] && (int) $completed['applied'] === 12, 'Resume replanned or lost counts' );
    $truth = j125_fresh( array_fill_keys( $crash_ids, '14.4' ) ); foreach ( $crash_ids as $id ) { j125_check( 1 === ( j125_saves( $mark )[$id] ?? 0 ), 'completed item repeated or percentage double applied' ); }
    $report['interruption'] = array( 'method' => 'real SIGKILL after Woo save before journal/commit; real 60-second lease expiry', 'before' => array( 'applied' => (int) $before_crash['applied'], 'pending' => (int) $before_crash['pending'] ), 'frozen_plan_hash' => $fi['job']['plan_hash'], 'after' => R::counts( (int) $fi['job']['id'] ), 'truth' => $truth, 'completed_products_not_repeated' => 'PASS' );
    $report['checks']['BROWSER_REOPEN_INTERRUPTION_PROTECTED_RESUME_NO_REPLAN'] = 'PASS';
    $report['checks']['NO_PERCENT_EXECUTION_RECOMPUTE'] = 'PASS'; j125_record();
    // Product-level HTTP capability/nonce/method/actor refusal; all denied state unchanged.
    $before = j125_snapshot(); $mark = j125_mark();
    foreach ( array( 'wl125-other', 'wl125-low' ) as $login ) {
        wl112_login( $login );
        foreach ( array( A::ACTION_RESUME, A::ACTION_UNDO ) as $action ) { $denied = wl112_post( array( 'action' => $action, 'job' => $fi['job']['public_id'], '_wpnonce' => wl112_nonce( $action . '_' . $fi['job']['public_id'] ) ) ); if ( 'wl125-other' === $login ) { $notice = wl112_get( $denied['location'] ); j125_check( false !== strpos( $notice['body'], 'Only the job creator, approver or an administrator' ), 'cross actor valid nonce must reach actor-scope refusal' ); } }
    }
    wl112_login( 'wl125-shop' );
    foreach ( array( 'missing', 'invalid' ) as $case ) { $post = array( 'action' => A::ACTION_RESUME, 'job' => $fi['job']['public_id'] ); if ( 'invalid' === $case ) { $post['_wpnonce'] = 'invalid'; } wl112_post( $post ); }
    wl112_http( 'GET', getenv( 'WL125_URL' ) . '/wp-admin/admin-post.php?action=' . A::ACTION_RESUME . '&job=' . $fi['job']['public_id'] );
    j125_check( $before === j125_snapshot() && array() === j125_saves( $mark ), 'unauthorized/missing nonce/GET mutation' );
    $report['checks']['AUTHORIZATION_CROSS_ACTOR_NONCE_GET'] = 'PASS';
    $report['outcome'] = 'PASS'; j125_record(); echo "All-five-operation, selectors, pagination, policies, immutable approval, mixed conflicts, Undo conflict, duplicate, real SIGKILL/Resume and authorization journey PASS\n";
} catch ( Throwable $error ) {
    if ( 'FAIL' !== $report['outcome'] ) { $report['outcome'] = 'FAIL'; $report['finding'] = $error->getMessage(); j125_record(); }
    throw $error;
}
