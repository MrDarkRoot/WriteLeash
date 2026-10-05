<?php
use WriteLeash\Free_Admin as Admin;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Undo_Repository as UndoRepo;
use WriteLeash\Price_Operation as Operation;
// #168 presentation and per-job export. Reuse the real Woo/worker/observer fixture.
wp_set_current_user( 1 );
foreach ( array( 'UNKNOWN', 'NEEDS_REVIEW', 'not_a_state' ) as $state168 ) {
	$label168 = Admin::item_label( $state168, 'COMPLETED' );
	ok( str_contains( $label168, 'checking' ) && ! preg_match( '/safe|unchanged|retry/i', $label168 ), 'unproven item remains honest: ' . $state168 );
	ok( ! preg_match( '/safe|unchanged|retry/i', Admin::job_label( $state168 ) ), 'unproven job remains honest: ' . $state168 );
}
eq( Admin::item_label( 'PENDING', 'BLOCKED' ), 'Will not run', 'blocked pending is not queued work' );
$identity168 = make_product( '18.00', 'publish', array( 'name' => 'Áo xanh 日本 <script>alert("168")</script>', 'sku' => 'WL168-' . wp_generate_uuid4() ) );
$nochange168 = make_product( '14.40' );
$sale168 = make_product( '18.00' );
$excluded168 = make_product( '18.00', 'publish', array( 'sale' => '15.00' ) );
$ids168 = array( $identity168, $nochange168, $sale168, $excluded168 );
$preview168 = Admin::process_preview( preview_post( array( 'ids' => implode( ',', $ids168 ), 'amount' => '14.40' ) ), 'POST' );
$job168 = Repo::read_by_public_id( $preview168['public_id'] );
$plan168 = Repo::hydrate_plan( $job168 );
$preview_html168 = render_view( 'preview', $job168['public_id'], 0 );
ok( str_contains( $preview_html168, '<strong>Áo xanh 日本 &lt;script&gt;' ), 'preview escaped name first' );
ok( str_contains( $preview_html168, 'Excluded; unchanged' ) && str_contains( $preview_html168, 'above the current sale price' ), 'exclusion explains actual stored reason' );
eq( Admin::process_approve( approve_post( $job168 ), 'POST' )['status'], 'OK', 'presentation fixture approved' );
$p168 = wc_get_product( $identity168 ); $p168->set_name( 'Renamed later' ); $p168->set_regular_price( '21.00' ); $p168->save();
$p168 = wc_get_product( $sale168 ); $p168->set_sale_price( '15.00' ); $p168->save();
run_job_terminal( (int) $job168['id'] );
$html168 = render_view( 'job', $job168['public_id'], 0 );
$rows168 = wl122_current_rows( $html168 );
ok( str_contains( $rows168[$identity168][0], 'Áo xanh 日本 <script>' ) && ! str_contains( $rows168[$identity168][0], 'Renamed later' ), 'saved identity survives rename in results' );
eq( array_slice( $rows168[$identity168], 1, 3 ), array( '$18.00 USD', '$21.00 USD', '$14.40 USD' ), 'truthful expected/current/target conflict values' );
ok( str_contains( $rows168[$sale168][4], 'sale price or schedule' ), 'equal-regular-price conflict explains live eligibility drift' );
eq( $rows168[$sale168][2], '$18.00 USD', 'sale drift regular price still matches expected' );
ok( str_contains( $html168, '2 conflicts' ) && str_contains( $html168, '1 already at target' ) && str_contains( $html168, '1 excluded' ), 'disjoint results categories visible' );
ok( ! str_contains( $html168, 'name="action" value="' . Admin::ACTION_RESUME . '"' ), 'terminal conflict never offers Resume' );
wp_delete_post( $identity168, true );
$html168 = render_view( 'job', $job168['public_id'], 0 );
$rows168 = wl122_current_rows( $html168 );
eq( $rows168[$identity168][2], 'Unavailable', 'deleted product current price unavailable' );
ok( str_contains( $rows168[$identity168][0], 'Áo xanh 日本 <script>' ) && ! str_contains( $rows168[$identity168][0], 'Review product' ), 'deleted identity retained without edit link' );

ob_start(); Admin::render_view( 'job', $job168['public_id'], 0, array(), 'conflict' ); $filtered_html168 = (string) ob_get_clean();
eq( count( wl122_current_rows( $filtered_html168 ) ), 2, 'attention conflict filter uses repository result set' );
ob_start(); Admin::render_view( 'job', $job168['public_id'], 0, array(), 'review' ); $filtered_html168 = (string) ob_get_clean();
ok( str_contains( $filtered_html168, 'No retained products on this page match this view.' ), 'empty uncertainty view truthful' );
// Create a real actor-owned job; other Shop Managers cannot view/export it.
$actor168 = wp_insert_user( array( 'user_login' => 'wl168-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
wp_set_current_user( $actor168 );
$formula168 = make_product( '18.00', 'publish', array( 'name' => '=SUM(1,2) Café "quoted"', 'sku' => '+WL168-' . wp_generate_uuid4() ) );
$owned168 = Admin::process_preview( preview_post( array( 'ids' => (string) $formula168, 'operation' => Operation::DECREASE_PERCENT, 'amount' => '20' ) ), 'POST' );
$owned_job168 = Repo::read_by_public_id( $owned168['public_id'] );
$export168 = array( 'job' => $owned_job168['public_id'], '_wpnonce' => wp_create_nonce( Admin::ACTION_EXPORT . '_' . $owned_job168['public_id'] ) );
eq( Admin::export_job( $export168, 'GET' )['reason'], 'post_required', 'CSV GET refused' );
eq( Admin::export_job( array_merge( $export168, array( '_wpnonce' => 'bad' ) ), 'POST' )['reason'], 'invalid_nonce', 'CSV nonce required' );
eq( Admin::export_job( array_merge( $export168, array( '_wpnonce' => wp_create_nonce( Admin::ACTION_EXPORT . '_' . $job168['public_id'] ) ) ), 'POST' )['reason'], 'invalid_nonce', 'CSV nonce bound to exact job' );
eq( Admin::export_job( $export168, 'POST' )['status'], 'OK', 'owner may export' );
$second168 = wp_insert_user( array( 'user_login' => 'wl168-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
wp_set_current_user( $second168 );
$other_export168 = array_merge( $export168, array( '_wpnonce' => wp_create_nonce( Admin::ACTION_EXPORT . '_' . $owned_job168['public_id'] ) ) );
eq( Admin::export_job( $other_export168, 'POST' )['status'], 'FORBIDDEN', 'other Manager valid own nonce cannot export unreadable job' );
ok( ! str_contains( render_view( 'history', '', 0 ), $owned_job168['public_id'] ), 'other actor cannot read history job' );
wp_set_current_user( 0 );
eq( Admin::export_job( $export168, 'POST' )['status'], 'FORBIDDEN', 'anonymous cannot export' );
wp_set_current_user( $actor168 );
$actor_object168 = wp_get_current_user(); $actor_object168->add_cap( 'manage_woocommerce', false );
eq( Admin::export_job( $export168, 'POST' )['status'], 'FORBIDDEN', 'revoked capabilities cannot export' );
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $actor168 );
$html168 = render_view( 'history', '', 0 );
ok( str_contains( $html168, 'Decrease regular prices by 20% · 1 product' ) && str_contains( $html168, 'Deleted user (User #' . $actor168 . ')' ) && str_contains( $html168, 'Awaiting review' ), 'history descriptive task and honest deleted creator' );
ok( str_contains( $html168, wp_timezone_string() ), 'history identifies site timezone' );

function csv168( array $job ): array {
	$stream = fopen( 'php://temp', 'w+' );
	try {
		Admin::write_job_csv( $stream, $job, Repo::hydrate_plan( $job ) );
		rewind( $stream ); $rows = array();
		while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) { $rows[] = $row; }
		return $rows;
	} finally { fclose( $stream ); }
}
$queries168 = array();
$trace168 = static function ( $query ) use ( &$queries168 ) { $queries168[] = $query; return $query; };
$reads168 = array();
$read168 = static function ( $id ) use ( &$reads168 ) { $reads168[] = $id; };
add_filter( 'query', $trace168 ); add_action( 'woocommerce_product_read', $read168 );
try {
	$csv_rows168 = csv168( $owned_job168 );
	$large_csv168 = csv168( $page_job ); // Existing 51-row paging fixture.
	$conflict_csv168 = csv168( $job168 );
} finally { remove_filter( 'query', $trace168 ); remove_action( 'woocommerce_product_read', $read168 ); }
eq( count( $large_csv168 ), 52, 'export includes every retained product beyond result page' );
eq( $reads168, array(), 'CSV uses saved evidence only; no live product reads' );
foreach ( $queries168 as $query ) {
	ok( ! preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|GRANT|REVOKE|START\s+TRANSACTION|BEGIN|LOCK)\b|\bFOR\s+UPDATE\b/i', $query ), 'CSV SELECT-only; no product/DDL/durable mutation' );
}
$data168 = array_combine( $csv_rows168[0], $csv_rows168[1] );
eq( $data168['product_name_at_preview'], "'=SUM(1,2) Café \"quoted\"", 'CSV escaped formula name and Unicode/commas/quotes round trip' );
ok( str_starts_with( $data168['sku_at_preview'], "'+WL168-" ), 'CSV formula SKU protected' );
eq( $data168['regular_price_at_preview'], '18.00', 'CSV preserves raw preview decimal string' );
eq( $data168['planned_price'], '14.40', 'CSV preserves planned decimal string' );
ok( str_contains( $data168['creator'], 'Deleted user' ), 'CSV deleted operator fallback' );
ok( ! array_intersect( array( 'evidence', 'plan_json', 'lease_owner', 'attempt_id', 'fingerprint', 'session' ), $csv_rows168[0] ), 'CSV excludes private/session diagnostics' );
eq( array_combine( $conflict_csv168[0], $conflict_csv168[1] )['apply_state'], 'CONFLICT', 'CSV durable conflict survives deleted product' );

// Every precise availability reason is rendered without catch-all guesswork.
$finished168 = Repo::read( (int) $job168['id'] );
$history168 = UndoRepo::history_job( (int) $job168['id'] );
ok( str_contains( Admin::undo_availability( $finished168, $history168 ), 'no products were changed' ), 'Undo no-change reason' );
$eligible_job168 = Repo::read( (int) $current_job['id'] );
$eligible_history168 = UndoRepo::history_job( (int) $current_job['id'] );
ok( str_contains( Admin::undo_availability( $eligible_job168, $eligible_history168 ), 'Undo available' ), 'Undo availability uses existing stopped/applied contract' );
$eligible_history168['undo_expires_at'] = '2000-01-01 00:00:00';
ok( str_contains( Admin::undo_availability( $eligible_job168, $eligible_history168 ), 'Undo expired' ), 'Undo expiry specific' );
// Missing retained evidence fails closed instead of returning a successful partial export.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE job_id=%d', WriteLeash\Job_Schema::items_table( $wpdb ), (int) $owned_job168['id'] ) );
$missing_export168 = false;
try { csv168( $owned_job168 ); } catch ( RuntimeException $error ) { $missing_export168 = true; }
eq( $missing_export168, true, 'missing item evidence refuses CSV' );
$missing_html168 = render_view( 'job', $owned_job168['public_id'], 0 );
ok( str_contains( $missing_html168, 'Some saved product evidence is unavailable' ) && str_contains( $missing_html168, 'Planned 1 product.' ), 'missing retained rows keep the saved planned count and honest unavailable message' );
ok( ! str_contains( $missing_html168, '0 changed' ) && ! str_contains( $missing_html168, 'Undo available for prices' ), 'missing outcomes never become confirmed zero or eligible Undo' );
marker( '#168 readable identity/conflicts/history/Undo and authorized bounded CSV' );
