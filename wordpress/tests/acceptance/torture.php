<?php
require __DIR__ . '/http.php';
use WriteLeash\Job_Repository as R;
use WriteLeash\Undo_Repository as U;
use WriteLeash\Price_Cache_Verifier as V;
use WriteLeash\Price_Apply_Journal as J;
use WriteLeash\Price_Decimal as D;

global $wpdb, $wl112_cookies;
$wl112_cookies = array();
$report = array( 'git_sha' => getenv( 'WL112_SHA' ), 'wp' => get_bloginfo( 'version' ), 'woo' => WC_VERSION, 'php' => PHP_VERSION, 'db' => $wpdb->get_var( 'SELECT VERSION()' ), 'cache' => wp_using_ext_object_cache() ? 'persistent' : 'default', 'external_side_effects' => 'OUTSIDE CONTRACT: emails, webhooks, remote HTTP, orders, external queues, arbitrary plugin side effects' );
$base = 'http://127.0.0.1:8080/wp-admin/admin.php?page=writeleash-bulk-prices';
function wt_save( array $report ): void {
    file_put_contents( '/evidence/' . DB_HOST . '-torture.json', json_encode( $report, JSON_PRETTY_PRINT ) . "\n" );
}
function wt_product(): int {
    $p = new WC_Product_Simple();
    $p->set_name( 'WL112-torture' ); $p->set_status( 'publish' ); $p->set_regular_price( '100.00' ); $p->save();
    return $p->get_id();
}
function wt_plan( array $ids ): array {
    global $base;
    $home = wl112_get( $base );
    $form = wl112_form( $home['body'], 'writeleash_free_preview' );
    $form = array_merge( $form, array( 'selector' => 'ids', 'ids' => implode( ',', $ids ), 'operation' => 'DECREASE_PERCENT', 'amount' => '20', 'max_products' => '1000', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '10' ) );
    unset( $form['block_zero'] );
    $post = wl112_post( $form );
    wl112_assert( (bool) preg_match( '/wl_job=([0-9a-f-]{36})/', $post['location'], $m ), 'torture preview refused' );
    $page = wl112_get( $post['location'] );
    return array( 'job' => R::read_by_public_id( $m[1] ), 'preview' => $page );
}
function wt_approve( array $f ): array {
    $post = wl112_post( wl112_form( $f['preview']['body'], 'writeleash_free_approve' ) );
    return array( 'url' => $post['location'], 'page' => wl112_get( $post['location'] ) );
}
function wt_resume( array $progress ): array {
    wl112_post( wl112_form( $progress['page']['body'], 'writeleash_free_resume' ) );
    return array( 'url' => $progress['url'], 'page' => wl112_get( $progress['url'] ) );
}
function wt_truth( int $id ): array {
    $db = V::observer();
    try {
        $stored = V::storage( $db, $id );
        $price = $stored['meta']['_regular_price'][0];
        V::matches( $stored, $price );
        wp_cache_flush_runtime();
        $p = wc_get_product( $id );
        wl112_assert( D::parse( $p->get_regular_price( 'edit' ) ) === D::parse( $price ), 'KILL: cache disagrees after reconciliation' );
        return array( 'regular' => $price, 'lookup_min' => $stored['lookup']['min_price'] );
    } finally { $db->close(); }
}
try {
    wp_set_current_user( 1 );
    wl112_login();
    $wpdb->query( "CREATE TABLE {$wpdb->prefix}wl112_hook_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,product_id bigint unsigned NOT NULL) ENGINE=InnoDB" );
    foreach ( array( 'clean', 'rollback', 'throw', 'error', 'property', 'query-commit', 'raw-commit' ) as $mode ) {
        $id = wt_product();
        $f = wt_plan( array( $id ) );
        $progress = wt_approve( $f );
        update_option( 'wl112_hook', array( 'id' => $id, 'mode' => $mode ), false );
        // Fault is inside the owned mutation, after real Woo hooks and before
        // journal/COMMIT. A mu-plugin loads this checkpoint in HTTP requests.
        if ( 'rollback' === $mode ) { update_option( 'wl112_rollback', $id, false ); }
        $progress = wt_resume( $progress );
        delete_option( 'wl112_hook' ); delete_option( 'wl112_rollback' );
        $job = R::read( (int) $f['job']['id'] );
        $truth = wt_truth( $id );
        $row = J::read( $wpdb, $job['plan_id'], $id );
        $report['hooks'][$mode] = array( 'job_state' => $job['status'], 'counts' => R::counts( (int) $job['id'] ), 'journal_state' => $row['state'], 'truth' => $truth, 'db_effect_rows' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wl112_hook_events WHERE product_id=%d", $id ) ) );
        if ( 'APPLIED' === $row['state'] ) {
            wl112_assert( D::parse( $truth['regular'] ) === '80', 'KILL: journal falsely APPLIED under hook torture' );
            V::observe( R::hydrate_plan( $job ), $id );
        }
        if ( 'clean' === $mode ) {
            wl112_assert( 'COMPLETED' === $job['status'], 'clean hook failed' );
            wl112_post( wl112_form( $progress['page']['body'], 'writeleash_free_undo' ) );
            wl112_assert( D::parse( wt_truth( $id )['regular'] ) === '100', 'clean-hook Undo failed' );
            $report['hooks'][$mode]['undo'] = 'PASS';
            wl112_assert( 1 === $report['hooks'][$mode]['db_effect_rows'], 'clean hook side effect not durable' );
        } elseif ( 'raw-commit' !== $mode ) {
            wl112_assert( D::parse( $truth['regular'] ) === '100', 'KILL: failed hook changed stored price' );
            wl112_assert( 'APPLIED' !== $row['state'], 'KILL: failed hook falsely APPLIED' );
        }
        wt_save( $report );
    }
    // External edits before Apply and after Apply are never overwritten.
    foreach ( array( 'before-apply', 'before-undo' ) as $when ) {
        $id = wt_product(); $f = wt_plan( array( $id ) ); $progress = wt_approve( $f );
        if ( 'before-undo' === $when ) { $progress = wt_resume( $progress ); }
        V::invalidate( $id ); $p = wc_get_product( $id ); $p->set_regular_price( '75.00' ); $p->save();
        if ( 'before-apply' === $when ) { $progress = wt_resume( $progress ); }
        else { wl112_post( wl112_form( $progress['page']['body'], 'writeleash_free_undo' ) ); }
        wl112_assert( D::parse( wt_truth( $id )['regular'] ) === '75', 'KILL: external edit overwritten' );
        $report['external_edit'][$when] = 'PASS: 75 preserved; conflict visible';
    }
    // A 100-item Admin-origin plan is genuinely killed inside its first Woo
    // transaction. Recovery still uses the protected HTTP Resume contract.
    $ids = array(); for ( $i = 0; $i < 100; ++$i ) { $ids[] = wt_product(); }
    $f = wt_plan( $ids ); $progress = wt_approve( $f );
    $prefix = sys_get_temp_dir() . '/wl112-kill-' . bin2hex( random_bytes( 4 ) );
    $spec = array( 'job_id' => (int) $f['job']['id'], 'started' => $prefix . '.started', 'result' => $prefix . '.result', 'barrier' => $prefix . '.barrier', 'release' => $prefix . '.release', 'mutator_checkpoint' => 'AFTER_WOO_SAVE_BEFORE_JOURNAL', 'mutator_fault' => 'wait', 'manual' => true );
    file_put_contents( $prefix . '.json', json_encode( $spec ) );
    $proc = proc_open( array( 'wp', '--path=' . ABSPATH, 'eval-file', __DIR__ . '/../jobs/worker.php', $prefix . '.json' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $prefix . '.stdout', 'w' ), 2 => array( 'file', $prefix . '.stderr', 'w' ) ), $pipes );
    wl112_assert( is_resource( $proc ), 'kill worker spawn' ); fclose( $pipes[0] );
    $deadline = microtime( true ) + 30;
    while ( ! is_file( $spec['barrier'] ) ) { wl112_assert( microtime( true ) < $deadline, 'kill barrier timeout' ); usleep( 10000 ); }
    posix_kill( (int) file_get_contents( $spec['started'] ), 9 );
    proc_close( $proc );
    wl112_assert( D::parse( wt_truth( $ids[0] )['regular'] ) === '100', 'KILL: killed transaction durable mutation' );
    // Wait for the actual lease TTL; do not forge generation/fence authority.
    sleep( 61 );
    $t = microtime( true );
    $progress['page'] = wl112_get( $progress['url'] );
    for ( $i = 0; $i < 15; ++$i ) {
        $progress = wt_resume( $progress );
        if ( 'COMPLETED' === R::read( (int) $f['job']['id'] )['status'] ) { break; }
    }
    wl112_assert( 100 === R::counts( (int) $f['job']['id'] )['applied'], 'KILL: killed job failed recovery' );
    $report['kill_resume_seconds_after_lease_expiry'] = microtime( true ) - $t;
    $before = R::counts( (int) $f['job']['id'] );
    do_action( WriteLeash\Job_Scheduler::HOOK, (int) $f['job']['id'] );
    do_action( WriteLeash\Job_Scheduler::HOOK, (int) $f['job']['id'] );
    do_action( WriteLeash\Job_Scheduler::HOOK, 999999999 );
    wl112_assert( $before === R::counts( (int) $f['job']['id'] ), 'KILL: duplicate/stale wake-up changed counts' );
    $report['scheduler_duplicate_stale'] = 'PASS';
    wt_save( $report );

    // Interleave >20 jobs per Shop Manager, each with a 100-product frozen
    // selection, so foreign rows cannot hide a manager's second history page.
    $actors = array();
    foreach ( array( 'a', 'b' ) as $name ) {
        $actors[] = wp_insert_user( array( 'user_login' => 'wl112-' . $name, 'user_pass' => 'disposable_admin_password', 'role' => 'shop_manager' ) );
    }
    $owned = array();
    for ( $i = 0; $i < 21; ++$i ) {
        foreach ( $actors as $actor ) {
            wl112_login( 'wl112-' . ( $actor === $actors[0] ? 'a' : 'b' ) );
            $f = wt_plan( $ids ); $owned[$actor][] = $f['job']['public_id'];
        }
    }
    foreach ( $actors as $actor ) {
        $name = $actor === $actors[0] ? 'a' : 'b';
        wl112_login( 'wl112-' . $name );
        $html = wl112_get( $base . '&wl_view=history' );
        $later = wl112_get( $base . '&wl_view=history&wl_offset=20' );
        $mine = array_reverse( $owned[$actor] );
        foreach ( $mine as $i => $public ) { wl112_assert( false !== strpos( ( $i < 20 ? $html : $later )['body'], substr( $public, 0, 8 ) ), 'own pagination hiding' ); }
        $foreign = $owned[$actor === $actors[0] ? $actors[1] : $actors[0]][0];
        wl112_assert( false === strpos( $html['body'] . $later['body'], substr( $foreign, 0, 8 ) ), 'KILL: foreign history leakage' );
        $guessed = wl112_get( $base . '&wl_view=job&wl_job=' . $foreign );
        wl112_assert( false !== strpos( $guessed['body'], 'No job is visible' ), 'guessed foreign access' );
    }
    $user = new WP_User( $actors[0] ); $user->set_role( 'subscriber' );
    // Existing session remains authenticated, but fresh request loses authority.
    wl112_login( 'wl112-a' );
    $blocked = wl112_post( array( 'action' => 'writeleash_free_resume', 'job' => $owned[$actors[0]][0], '_wpnonce' => 'irrelevant-after-revocation' ) );
    $notice = wl112_get( $blocked['location'] );
    wl112_assert( false !== strpos( $notice['body'], 'not allowed' ) || false !== strpos( $notice['body'], 'FORBIDDEN' ), 'revoked actor refusal' );
    wl112_login();
    $override = wl112_get( $base . '&wl_view=job&wl_job=' . $owned[$actors[1]][0] );
    wl112_assert( false !== strpos( $override['body'], 'Durable progress' ), 'intentional admin override' );
    $report['multi_user'] = 'PASS: A/B each 21 interleaved 100-item plans; two real HTTP history pages; guessed access denied; revoked mutation denied; administrator override intentional';
    $report['outcome'] = 'PASS'; wt_save( $report );
} catch ( Throwable $error ) {
    $report['outcome'] = 'FAIL'; $report['error'] = $error->getMessage(); wt_save( $report ); throw $error;
}
