<?php
// Repository-only WP-CLI eval-file observer for an exact ZIP-installed fixture.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$check = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$check( '7.1.2' === get_bloginfo( 'version' ) && '11.1.2' === WC_VERSION, 'wrong fixture' );
$actor = get_user_by( 'login', 'artifact_actor' )->ID ?? wp_insert_user( array( 'user_login' => 'artifact_actor', 'user_pass' => wp_generate_password( 32 ), 'role' => 'shop_manager' ) );
$other = get_user_by( 'login', 'artifact_other' )->ID ?? wp_insert_user( array( 'user_login' => 'artifact_other', 'user_pass' => wp_generate_password( 32 ), 'role' => 'shop_manager' ) );
$low = get_user_by( 'login', 'artifact_low' )->ID ?? wp_insert_user( array( 'user_login' => 'artifact_low', 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
$check( ! is_wp_error( $actor ) && ! is_wp_error( $other ) && ! is_wp_error( $low ), 'fixture users' );
wp_set_current_user( $actor );
$p = new WC_Product_Simple(); $p->set_name( 'Compliance <script>sentinel</script>' ); $p->set_status( 'publish' ); $p->set_regular_price( '21.00' ); $id = $p->save();
$post = array( 'selector' => 'ids', 'ids' => (string) $id, 'operation' => 'SET', 'amount' => '22.00', 'max_products' => '100', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '50', 'block_zero' => '1', '_wpnonce' => wp_create_nonce( WriteLeash\Free_Admin::ACTION_PREVIEW ) );
$check( 'post_required' === WriteLeash\Free_Admin::process_preview( $post, 'GET' )['reason'], 'GET mutation' );
$bad_nonce = $post; $bad_nonce['_wpnonce'] = 'invalid';
$check( 'invalid_nonce' === WriteLeash\Free_Admin::process_preview( $bad_nonce, 'POST' )['reason'], 'nonce missing' );
$job = WriteLeash\Free_Admin::process_preview( $post, 'POST' );
$check( 'OK' === $job['status'], 'preview failed: ' . $job['reason'] );
foreach ( array( array( 'ids', '1e2' ), array( 'ids', '1x' ), array( 'ids', array( $id ) ), array( 'operation', 'CALLBACK' ), array( 'amount', '1e2' ), array( 'amount', 2 ), array( 'max_products', '101' ), array( 'max_products', '100junk' ) ) as $case ) {
    $bad = $post; $bad[ $case[0] ] = $case[1];
    $check( 'INVALID' === WriteLeash\Free_Admin::process_preview( $bad, 'POST' )['status'], 'loose input accepted' );
}
WriteLeash\Undo_Schema::install();
$public = $job['public_id'];
$before = WriteLeash\Job_Repository::read( $job['job_id'] );
foreach ( array( 0, $low, $other ) as $user ) {
    wp_set_current_user( $user );
    foreach ( array( '/writeleash/v1/jobs/' . $public . '/resume', '/writeleash/v1/undo/' . $public . '/start' ) as $route ) {
        $r = rest_do_request( new WP_REST_Request( 'POST', $route ) );
        $check( in_array( $r->get_status(), array( 401, 403 ), true ), 'unauthorized REST accepted' );
        $data = $r->get_data(); $check( ! isset( $data['job'] ) && ! isset( $data['undo'] ), 'job data leak' );
    }
    $check( 'FORBIDDEN' === WriteLeash\Free_Admin::process_preview( $post, 'POST' )['status'] || $user === $other, 'nonce grants capability' );
}
wp_set_current_user( $actor );
foreach ( array( '/writeleash/v1/jobs/' . $public . '/resume', '/writeleash/v1/undo/' . $public . '/start' ) as $route ) {
    $check( 404 === rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status(), 'REST GET mutation' );
}
$check( $before === WriteLeash\Job_Repository::read( $job['job_id'] ), 'negative requests changed job' );
$check( '21.00' === wc_get_product( $id )->get_regular_price( 'edit' ), 'negative requests changed product' );
ob_start(); WriteLeash\Free_Admin::render_view( 'preview', $public, 0 ); $html = ob_get_clean();
$check( false === strpos( $html, '<script>sentinel</script>' ), 'unescaped product title' );
$check( false !== strpos( $html, 'Frozen preview' ), 'preview page not rendered' );
$unrelated = as_schedule_single_action( time() + 3600, 'artifact124_unrelated', array(), 'artifact124-unrelated' );
$owned = as_schedule_single_action( time() + 3600, 'writeleash_job_wakeup', array( $job['job_id'] ), 'writeleash-jobs' );
$check( $unrelated > 0 && $owned > 0, 'scheduler fixture' );
$record = array( 'product_id' => $id, 'job_id' => $job['job_id'], 'unrelated_action_id' => $unrelated, 'owned_action_id' => $owned );
update_option( 'artifact124_fixture', $record, false );
echo "Admin capability/POST/nonce, strict inputs, REST anonymous/subscriber/cross-actor negatives, GET denial, unchanged job/product, output escaping PASS\n";
