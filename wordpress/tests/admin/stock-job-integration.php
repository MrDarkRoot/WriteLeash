<?php
// #310 Free Admin stock-job integration: a stock grant job renders safely in
// the human admin (no price_field fatal), history shows stock copy, export
// refuses with a typed message, and the price progress client is never wired.
// Reuses the real Agent/grant acceptance path and the real worker.
use WriteLeash\Agent_Jobs_Rest as AgentRest;
use WriteLeash\Change_Plan;
use WriteLeash\Free_Admin as Admin;
use WriteLeash\Grant_Repository as Grants;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Job_State as JState;
use WriteLeash\Job_Worker as Worker;
use WriteLeash\Price_Selection_Spec as Selection;
use WriteLeash\Price_Store_Context;
use WriteLeash\Product_Price_Selector as Selector;
use WriteLeash\Stock_Operation as StockOp;

global $assertions;
$assertions = 0;

function eq( $actual, $expected, string $label ): void {
	global $assertions;
	++$assertions;
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
}
function ok( bool $condition, string $label ): void { eq( $condition, true, $label ); }
function marker( string $label ): void {
	global $assertions;
	echo "#310 $label: PASS ($assertions assertions)\n";
}
function wl310_user( string $login, string $role ): int {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) { return (int) $existing->ID; }
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 20 ), 'role' => $role, 'user_email' => $login . '@example.test' ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'user failed: ' . $login ); }
	return (int) $id;
}
function wl310_stock_product( int $qty ): int {
	$previous = get_current_user_id();
	wp_set_current_user( 1 );
	$p = new WC_Product_Simple();
	$p->set_name( 'WL310-admin-' . wp_generate_uuid4() );
	$p->set_status( 'publish' );
	$p->set_regular_price( '10.00' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $qty );
	$p->save();
	$id = $p->get_id();
	wp_set_current_user( $previous );
	return $id;
}
function wl310_qty( int $id ): string {
	\WriteLeash\Price_Cache_Verifier::invalidate( $id );
	$p = wc_get_product( $id );
	return null === $p->get_stock_quantity( 'edit' ) ? '' : (string) $p->get_stock_quantity( 'edit' );
}
/** Accept a real stock grant job through the repository (the REST path's core). */
function wl310_accept( array $grant, int $issuer, int $actor, array $ids, string $type, string $field, string $input, string $key ): array {
	wp_set_current_user( $issuer );
	$selection = Selection::ids( $ids );
	$snapshots = Selector::resolve( $selection );
	$operation = new StockOp( $type, $field, $input, '1000', '0' );
	$plan = Change_Plan::create_stock( AgentRest::plan_id( (string) $grant['public_id'], $key ), gmdate( 'Y-m-d\TH:i:s\Z' ), $actor, Price_Store_Context::current(), $selection, $operation, $snapshots );
	return Grants::accept_plan( $plan, (string) $grant['public_id'], $issuer, $key );
}
function wl310_run( int $job_id ): void {
	Worker::queue_job( $job_id );
	for ( $i = 0; $i < 6 && ! JState::is_terminal( (string) ( Repo::read( $job_id )['status'] ?? '' ) ); ++$i ) {
		Worker::run( $job_id, array( 'max_items' => 5, 'budget_seconds' => 20 ), true );
	}
}

eq( get_bloginfo( 'version' ), '7.1.2', 'pinned WordPress' );
eq( WC_VERSION, '11.1.2', 'pinned WooCommerce' );
update_option( 'woocommerce_manage_stock', 'yes' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
$issuer = wl310_user( 'wl310-admin-issuer', 'administrator' );
$actor = wl310_user( 'wl310-admin-actor', 'administrator' );
ok( $issuer !== $actor, 'issuer and actor differ' );
wp_set_current_user( $issuer );
update_option( \WriteLeash\Grant_Schema::DISABLE_OPTION, '0', false );
$grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );

// A completed stock job (applied) and a conflicted one (race before Apply).
$applied_id = wl310_stock_product( 10 );
$applied = wl310_accept( $grant, $issuer, $actor, array( $applied_id ), StockOp::SET, StockOp::FIELD_QUANTITY, '25', 'wl310-admin-set' );
wl310_run( (int) $applied['job']['id'] );
eq( Repo::read( (int) $applied['job']['id'] )['status'], JState::COMPLETED, 'stock job completed' );
eq( wl310_qty( $applied_id ), '25', 'stock quantity applied' );

$raced_id = wl310_stock_product( 6 );
$raced = wl310_accept( $grant, $issuer, $actor, array( $raced_id ), StockOp::SET, StockOp::FIELD_QUANTITY, '30', 'wl310-admin-race' );
wp_set_current_user( 1 );
wc_update_product_stock( $raced_id, 2, 'decrease' );
wl310_run( (int) $raced['job']['id'] );
eq( \WriteLeash\Price_Apply_Journal::read( \WriteLeash\Price_Cache_Verifier::observer(), $raced['job']['plan_id'], $raced_id )['state'], 'CONFLICT', 'raced item conflicted' );
$applied_job = Repo::read_by_public_id( (string) $applied['job']['public_id'] );
$raced_job = Repo::read_by_public_id( (string) $raced['job']['public_id'] );
$stock_plan = Repo::hydrate_plan( $applied_job );
marker( 'stock grant fixtures' );

// B: task_description renders stock copy, never price copy, for every op shape.
wp_set_current_user( $issuer );
eq( Admin::task_description( $stock_plan->data() ), 'Set stock quantity to 25 · 1 product', 'stock SET description' );
$description_conflict = Admin::task_description( Repo::hydrate_plan( $raced_job )->data() );
eq( $description_conflict, 'Set stock quantity to 30 · 1 product', 'stock conflict job description' );
$inc_plan = Change_Plan::create_stock( 'wl310-desc-inc', gmdate( 'Y-m-d\TH:i:s\Z' ), $actor, Price_Store_Context::current(), Selection::ids( array( $applied_id ) ), new StockOp( StockOp::INCREASE_INT, StockOp::FIELD_QUANTITY, '3', '1000', '0' ), Selector::resolve( Selection::ids( array( $applied_id ) ) ) );
eq( Admin::task_description( $inc_plan->data() ), 'Increase stock quantity by 3 · 1 product', 'stock INCREASE_INT description' );
$dec_plan = Change_Plan::create_stock( 'wl310-desc-dec', gmdate( 'Y-m-d\TH:i:s\Z' ), $actor, Price_Store_Context::current(), Selection::ids( array( $applied_id ) ), new StockOp( StockOp::DECREASE_INT, StockOp::FIELD_QUANTITY, '4', '1000', '0' ), Selector::resolve( Selection::ids( array( $applied_id ) ) ) );
eq( Admin::task_description( $dec_plan->data() ), 'Decrease stock quantity by 4 · 1 product', 'stock DECREASE_INT description' );
$status_plan = Change_Plan::create_stock( 'wl310-desc-status', gmdate( 'Y-m-d\TH:i:s\Z' ), $actor, Price_Store_Context::current(), Selection::ids( array( $applied_id ) ), new StockOp( StockOp::SET, StockOp::FIELD_STATUS, 'onbackorder', '1000', '0' ), Selector::resolve( Selection::ids( array( $applied_id ) ) ) );
eq( Admin::task_description( $status_plan->data() ), 'Set stock status to onbackorder · 1 product', 'stock status description' );
ok( ! str_contains( Admin::task_description( $stock_plan->data() ), 'regular prices' ), 'stock description never says regular prices' );
marker( 'task_description stock copy' );

// A: the stock job view renders read-only for an administrator: stock copy,
// grant-route notice, plain quantities, and none of the price workflow.
wp_set_current_user( $issuer );
ob_start();
Admin::render_view( 'job', (string) $applied_job['public_id'], 0 );
$html = (string) ob_get_clean();
ok( str_contains( $html, 'Set stock quantity to 25' ), 'stock job view shows the stock task copy' );
ok( str_contains( $html, 'restricted grant route' ), 'stock job view names the grant route' );
ok( str_contains( $html, 'no human Apply, Repeat, Recovery, Undo or price-CSV controls' ), 'stock job view states the missing human controls' );
ok( str_contains( $html, '>25<' ), 'stock job view shows the plain planned quantity' );
ok( ! str_contains( $html, 'value="' . Admin::ACTION_RESUME . '"' ), 'stock job view has no Resume form' );
ok( ! str_contains( $html, 'value="' . Admin::ACTION_UNDO . '"' ), 'stock job view has no Undo form' );
ok( ! str_contains( $html, 'value="' . Admin::ACTION_EXPORT . '"' ), 'stock job view has no price CSV form' );
ok( ! str_contains( $html, 'data-writeleash-progress="1"' ), 'stock job view wires no price progress poll' );
ok( ! str_contains( $html, 'Repeat as new Preview' ), 'stock job view has no Repeat link' );
ok( ! str_contains( $html, 'Re-preview conflicted products' ), 'stock job view has no Recovery link' );
ok( ! str_contains( $html, 'regular prices' ), 'stock job view never renders price copy' );
ok( ! str_contains( $html, 'writeleash-undo' ), 'stock job view has no Undo section' );
ok( ! str_contains( $html, 'Restore eligible prices' ), 'stock job view has no Undo control' );
marker( 'stock job view read-only (administrator)' );

// Same render as the restricted creator: no exception, same boundaries.
wp_set_current_user( $actor );
ob_start();
Admin::render_view( 'job', (string) $applied_job['public_id'], 0 );
$creator_html = (string) ob_get_clean();
ok( str_contains( $creator_html, 'Set stock quantity to 25' ), 'creator sees the stock task copy' );
ok( ! str_contains( $creator_html, 'value="' . Admin::ACTION_RESUME . '"' ), 'creator sees no Resume form' );
ok( ! str_contains( $creator_html, 'data-writeleash-progress="1"' ), 'creator sees no price progress poll' );
marker( 'stock job view read-only (restricted creator)' );

// The conflicted stock job view shows the outcome label and conflict copy.
wp_set_current_user( $issuer );
ob_start();
Admin::render_view( 'job', (string) $raced_job['public_id'], 0 );
$conflict_html = (string) ob_get_clean();
ok( str_contains( $conflict_html, 'Set stock quantity to 30' ), 'conflicted stock view shows its own task copy' );
ok( str_contains( $conflict_html, 'Not changed' ), 'conflicted stock view labels the outcome' );
ok( str_contains( $conflict_html, 'left the newer value unchanged' ), 'conflicted stock view carries the conflict copy' );
ok( str_contains( $conflict_html, '>6<' ), 'conflicted stock view shows the frozen expected quantity, never the live value' );
ok( ! str_contains( $conflict_html, '>' . wl310_qty( $raced_id ) . '<' ), 'conflicted stock view renders no live quantity' );
marker( 'stock job view conflict outcome' );

// History lists the stock jobs with stock copy and links safely. A real
// price job joins the history first, so the row-scoped assertions prove the
// stock row itself (a page-level Repeat assertion would also match the price
// row, which legitimately offers Repeat).
wp_set_current_user( $issuer );
$price_preview = Admin::process_preview( array( '_wpnonce' => wp_create_nonce( Admin::ACTION_PREVIEW ), 'selector' => 'ids', 'ids' => (string) $applied_id, 'operation' => 'SET', 'price_field' => 'regular_price', 'amount' => '11.00', 'max_products' => '100', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '100' ), 'POST' );
eq( $price_preview['status'], 'OK', 'price fixture preview accepted' );
ob_start();
Admin::render_view( 'history', '', 0 );
$history_html = (string) ob_get_clean();
ok( str_contains( $history_html, 'Set stock quantity to 25' ), 'history shows the stock description' );
ok( str_contains( $history_html, 'Set regular prices to' ), 'history still shows price copy for price jobs' );
$stock_row = '';
$price_row = '';
foreach ( explode( '<tr', $history_html ) as $fragment ) {
	if ( str_contains( $fragment, 'Set stock quantity to 25' ) ) { $stock_row = $fragment; }
	if ( str_contains( $fragment, 'Set regular prices to' ) ) { $price_row = $fragment; }
}
ok( '' !== $stock_row, 'history contains the stock job row' );
ok( '' !== $price_row, 'history contains the price fixture row' );
ok( ! str_contains( $stock_row, 'Saved task details unavailable' ), 'stock row does not fall back to the unavailable copy' );
ok( ! str_contains( $stock_row, 'Repeat as new Preview' ), 'stock row offers no Repeat' );
ok( str_contains( $price_row, 'Repeat as new Preview' ), 'price row still offers Repeat' );
ok( str_contains( $stock_row, 'Download job CSV' ), 'stock row keeps the export form' );
marker( 'history with stock jobs' );

// Export of a stock job: typed, readable refusal through export_job + write_job_csv.
wp_set_current_user( $issuer );
$export = Admin::export_job( array( 'job' => $applied_job['public_id'], '_wpnonce' => wp_create_nonce( Admin::ACTION_EXPORT . '_' . $applied_job['public_id'] ) ), 'POST' );
eq( $export['status'], 'OK', 'stock export gate authorizes like a price job' );
$stream = fopen( 'php://temp/maxmemory:1048576', 'w+' );
ok( is_resource( $stream ), 'export stream open' );
try {
	Admin::write_job_csv( $stream, $export['job'], $export['plan'] );
	throw new RuntimeException( 'stock export must refuse' );
} catch ( \WriteLeash\Price_Validation_Error $error ) {
	eq( $error->reason(), 'stock_job_export_unavailable', 'stock export refuses with the typed reason' );
} finally {
	fclose( $stream );
}
ok( str_contains( Admin::reason_message( 'stock_job_export_unavailable' ), 'grant audit trail' ), 'stock export refusal names the grant audit trail' );
ok( ! str_contains( Admin::reason_message( 'stock_job_export_unavailable' ), 'Saved export evidence is unavailable' ), 'stock export refusal is not the generic copy' );
marker( 'stock export typed refusal' );

// The progress poll never renders price rows for a stock job.
$poll = Admin::progress_snapshot( array( 'job' => $applied_job['public_id'], '_wpnonce' => wp_create_nonce( Admin::ACTION_PROGRESS . '_' . $applied_job['public_id'] ), 'offset' => '0', 'filter' => '' ), 'POST' );
eq( $poll['status'], 'UNAVAILABLE', 'stock poll refused' );
eq( $poll['reason'], 'stock_job_progress_not_polled', 'stock poll typed reason' );
ok( ! isset( $poll['rows'] ), 'stock poll never carries price rows' );
marker( 'progress poll typed refusal' );

echo "#310 admin stock job gate: PASS ($assertions assertions)\n";
