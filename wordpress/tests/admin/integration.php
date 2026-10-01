<?php
// #111 complete Free Admin workflow E2E on real WooCommerce: activation
// without Redirection, entry point, selection, five operations, paginated
// preview, exclusions, policy block, approval binding, durable execution,
// browser-close/reopen truth, bounded manual resume, history, eligible and
// conflict/expired Undo, plus the security negative matrix. MySQL + MariaDB,
// default and persistent Redis cache modes. No mocked mutation internals.
use WriteLeash\Free_Admin as Admin;
use WriteLeash\Job_Item_State as IState;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Job_State as JState;
use WriteLeash\Job_Worker as Worker;
use WriteLeash\Price_Apply_Journal as Journal;
use WriteLeash\Price_Cache_Verifier as Verifier;
use WriteLeash\Price_Decimal as Decimal;
use WriteLeash\Price_Operation as Operation;
use WriteLeash\Undo_Repository as UndoRepo;
use WriteLeash\Undo_State as UState;
use WriteLeash\Undo_Worker as UndoWorker;

global $wpdb, $assertions;
$assertions = 0;
$cache_mode = (string) ( getenv( 'WL111_CACHE' ) ?: 'default' );

function eq( $actual, $expected, string $label ): void {
	global $assertions;
	++$assertions;
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
}
function ok( bool $condition, string $label ): void { eq( $condition, true, $label ); }
function marker( string $label ): void {
	global $assertions, $cache_mode;
	echo "#111[$cache_mode] $label: PASS ($assertions assertions)\n";
}
function make_product( string $price, string $status = 'publish', array $opts = array() ): int {
	$type = (string) ( $opts['type'] ?? 'simple' );
	if ( 'grouped' === $type ) {
		$p = new WC_Product_Grouped();
	} else {
		$p = new WC_Product_Simple();
	}
	$p->set_name( (string) ( $opts['name'] ?? ( 'WL111-' . wp_generate_uuid4() ) ) );
	$p->set_status( $status );
	if ( isset( $opts['sku'] ) ) { $p->set_sku( (string) $opts['sku'] ); }
	if ( ! isset( $opts['empty'] ) ) { $p->set_regular_price( $price ); }
	if ( isset( $opts['sale'] ) && $p instanceof WC_Product_Simple ) { $p->set_sale_price( (string) $opts['sale'] ); }
	if ( isset( $opts['category'] ) && $p instanceof WC_Product_Simple ) { $p->set_category_ids( array( (int) $opts['category'] ) ); }
	$p->save();
	return $p->get_id();
}
function fresh_price( int $id ): string {
	Verifier::invalidate( $id );
	$p = wc_get_product( $id );
	return (string) $p->get_regular_price( 'edit' );
}
/** Numeric price equality: Undo restores the canonical expected value, whose stored string form may differ from the original literal. */
function price_eq( string $actual, string $expected, string $label ): void {
	eq( Decimal::parse( $actual ), Decimal::parse( $expected ), $label );
}
function preview_post( array $extra = array() ): array {
	$base = array(
		'_wpnonce' => wp_create_nonce( Admin::ACTION_PREVIEW ),
		'selector' => 'ids',
		'ids' => '',
		'sku' => null,
		'category' => null,
		'operation' => Operation::SET,
		'amount' => '80.00',
		'max_products' => '1000',
		'max_increase' => '100',
		'max_decrease' => '100',
		'warning_threshold' => '100',
		'block_zero' => null,
		'job' => null,
	);
	return array_merge( $base, $extra );
}
function approve_post( array $job ): array {
	return array( '_wpnonce' => wp_create_nonce( Admin::ACTION_APPROVE . '_' . $job['plan_id'] ), 'job' => $job['public_id'] );
}
function resume_post( array $job ): array {
	return array( '_wpnonce' => wp_create_nonce( Admin::ACTION_RESUME . '_' . $job['public_id'] ), 'job' => $job['public_id'] );
}
function undo_post( array $job ): array {
	return array( '_wpnonce' => wp_create_nonce( Admin::ACTION_UNDO . '_' . $job['public_id'] ), 'job' => $job['public_id'] );
}
function run_job_terminal( int $job_id ): void {
	for ( $i = 0; $i < 30; ++$i ) {
		$job = Repo::read( $job_id );
		if ( JState::is_terminal( $job['status'] ) ) { return; }
		Worker::run( $job_id, array( 'max_items' => 10, 'budget_seconds' => 20 ), true );
	}
	throw new RuntimeException( 'job did not reach terminal state: ' . $job_id );
}
function run_undo_terminal( int $undo_id ): void {
	for ( $i = 0; $i < 30; ++$i ) {
		$op = UndoRepo::read_operation( $undo_id );
		if ( UState::is_terminal( $op['status'] ) ) { return; }
		UndoWorker::run( $undo_id, array( 'max_items' => 10, 'budget_seconds' => 20 ), true );
	}
	throw new RuntimeException( 'undo did not reach terminal state: ' . $undo_id );
}
function render_view( string $view, string $job_param, int $offset ): string {
	ob_start();
	Admin::render_view( $view, $job_param, $offset );
	return (string) ob_get_clean();
}

wp_set_current_user( 1 );
eq( WC_VERSION, '11.1.2', 'pinned Woo' );
eq( get_bloginfo( 'version' ), '7.1.2', 'pinned WordPress' );
ok( ! is_plugin_active( 'redirection/redirection.php' ), 'Redirection absent for the default Free path' );
ok( Admin::dependency_ok(), 'Woo dependency satisfied without Redirection' );

// Entry point: Products submenu with product-editing capability.
global $submenu;
Admin::menu();
$found = false;
foreach ( (array) ( $submenu['edit.php?post_type=product'] ?? array() ) as $entry ) {
	if ( Admin::SLUG === ( $entry[2] ?? '' ) ) {
		$found = true;
		eq( $entry[1] ?? '', 'edit_products', 'menu capability is edit_products, not manage_options' );
	}
}
ok( $found, 'Bulk Prices submenu registered under Products' );
marker( 'activation without Redirection and Woo-first entry point' );

// First-use fixture: two clean simple products for the first preview timing.
$t0 = microtime( true );
$first_ids = array( make_product( '100.00' ), make_product( '100.00' ) );
sort( $first_ids, SORT_NUMERIC );
$result = Admin::process_preview( preview_post( array( 'ids' => implode( ',', $first_ids ) ) ), 'POST' );
eq( $result['status'], 'OK', 'first preview status' );
eq( $result['reason'], 'preview_ready', 'first preview reason' );
$first_job = Repo::read( (int) $result['job_id'] );
eq( $first_job['status'], 'PLANNED', 'first job planned' );
echo "#111[$cache_mode] first preview wall time: " . round( microtime( true ) - $t0, 2 ) . "s (fixtures plus planning, informational)\n";
marker( 'first plan reaches preview' );

// All five operations with exact frozen targets on 100.00.
$ops = array(
	array( Operation::SET, '80.00', '80.00' ),
	array( Operation::INCREASE_FIXED, '10', '110.00' ),
	array( Operation::DECREASE_FIXED, '10', '90.00' ),
	array( Operation::INCREASE_PERCENT, '20', '120.00' ),
	array( Operation::DECREASE_PERCENT, '10', '90.00' ),
);
foreach ( $ops as list( $type, $amount, $target ) ) {
	$id = make_product( '100.00' );
	$res = Admin::process_preview( preview_post( array( 'ids' => (string) $id, 'operation' => $type, 'amount' => $amount ) ), 'POST' );
	eq( $res['status'], 'OK', 'preview ' . $type );
	$job = Repo::read_by_public_id( $res['public_id'] );
	$plan = Repo::hydrate_plan( $job );
	$page = $plan->preview_page( 0, 20 );
	eq( $page['items'][0]['planned_regular_price'], $target, 'frozen target ' . $type );
	eq( $page['items'][0]['stored_regular_price'], '100.00', 'stored before ' . $type );
	$approval = Admin::process_approve( approve_post( $job ), 'POST' );
	eq( $approval['status'], 'OK', 'approve ' . $type );
	eq( $approval['scheduler'], 'SCHEDULED', 'wake-up queued ' . $type );
	run_job_terminal( (int) $job['id'] );
	eq( fresh_price( $id ), $target, 'applied ' . $type );
	$counts = Repo::counts( (int) $job['id'] );
	eq( $counts['applied'], 1, 'one applied ' . $type );
}
marker( 'five operations preview approve execute' );

// Unchanged items never count as changes and need no mutation.
$same_id = make_product( '80.00' );
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $same_id, 'amount' => '80.00' ) ), 'POST' );
eq( $res['status'], 'OK', 'unchanged preview' );
$uplan = Repo::hydrate_plan( Repo::read_by_public_id( $res['public_id'] ) );
eq( $uplan->summary()['unchanged'], 1, 'one unchanged' );
eq( $uplan->summary()['changing'], 0, 'zero changing' );
$ujob = Repo::read_by_public_id( $res['public_id'] );
Admin::process_approve( approve_post( $ujob ), 'POST' );
run_job_terminal( (int) $ujob['id'] );
eq( Repo::read( (int) $ujob['id'] )['status'], 'COMPLETED', 'unchanged-only completes clean' );
// Preview pagination walks the frozen plan, never a full-catalog render.
$three = array( make_product( '100.00' ), make_product( '100.00' ), make_product( '100.00' ) );
$res = Admin::process_preview( preview_post( array( 'ids' => implode( ',', $three ), 'amount' => '80.00' ) ), 'POST' );
$pjob = Repo::read_by_public_id( $res['public_id'] );
$pplan = Repo::hydrate_plan( $pjob );
$pg1 = $pplan->preview_page( 0, 2 );
eq( count( $pg1['items'] ), 2, 'first preview page' );
eq( $pg1['next_offset'], 2, 'preview next offset' );
$pg2 = $pplan->preview_page( 2, 2 );
eq( count( $pg2['items'] ), 1, 'second preview page' );
eq( $pg2['next_offset'], null, 'preview last page' );
$html = render_view( 'preview', $pjob['public_id'], 2 );
ok( str_contains( $html, 'Next page' ) && str_contains( $html, 'Previous page' ), 'preview pager renders' );
marker( 'unchanged items and preview pagination' );

// Selector kinds: exact SKU and direct category (no descendants).
$term = wp_insert_term( 'WL111 Cat ' . wp_generate_uuid4(), 'product_cat' );
ok( ! is_wp_error( $term ), 'category created' );
$cat_id = (int) $term['term_id'];
$child = wp_insert_term( 'WL111 Child ' . wp_generate_uuid4(), 'product_cat', array( 'parent' => $cat_id ) );
$child_id = (int) $child['term_id'];
$sku = 'WL111-SKU-' . wp_generate_uuid4();
$sku_id = make_product( '50.00', 'publish', array( 'sku' => $sku ) );
$cat_id_a = make_product( '50.00', 'publish', array( 'category' => $cat_id ) );
$cat_id_b = make_product( '50.00', 'publish', array( 'category' => $child_id ) );
$res = Admin::process_preview( preview_post( array( 'selector' => 'sku', 'sku' => $sku, 'amount' => '60.00' ) ), 'POST' );
eq( $res['status'], 'OK', 'sku preview' );
$job = Repo::read_by_public_id( $res['public_id'] );
eq( Repo::hydrate_plan( $job )->summary()['selected'], 1, 'sku selects exactly one' );
$res = Admin::process_preview( preview_post( array( 'selector' => 'category', 'category' => (string) $cat_id, 'amount' => '60.00' ) ), 'POST' );
eq( $res['status'], 'OK', 'category preview' );
$plan = Repo::hydrate_plan( Repo::read_by_public_id( $res['public_id'] ) );
eq( $plan->summary()['selected'], 1, 'category excludes descendants' );
eq( $plan->data()['resolved_product_ids'], array( $cat_id_a ), 'direct membership only' );
marker( 'sku and category selectors' );

// Exclusions with typed reasons: draft, grouped, sale-configured, empty price.
$draft = make_product( '100.00', 'draft' );
$grouped = make_product( '100.00', 'publish', array( 'type' => 'grouped' ) );
$sale = make_product( '100.00', 'publish', array( 'sale' => '90.00' ) );
$empty = make_product( '100.00', 'publish', array( 'empty' => true ) );
$mix_ids = array( $sku_id, $draft, $grouped, $sale, $empty );
sort( $mix_ids, SORT_NUMERIC );
$res = Admin::process_preview( preview_post( array( 'ids' => implode( ',', $mix_ids ), 'amount' => '80.00' ) ), 'POST' );
eq( $res['status'], 'OK', 'mixed preview' );
$job = Repo::read_by_public_id( $res['public_id'] );
$plan = Repo::hydrate_plan( $job );
$summary = $plan->summary();
eq( $summary['changing'], 1, 'one changing' );
eq( $summary['unsupported'], 4, 'four unsupported' );
$reasons = array();
foreach ( $plan->preview_page( 0, 20 )['items'] as $item ) {
	if ( 'UNSUPPORTED' === $item['result'] ) { $reasons[ (int) $item['product_id'] ] = $item['eligibility']['reason']; }
}
eq( $reasons[ $draft ], 'unsupported_status', 'draft reason' );
eq( $reasons[ $grouped ], 'unsupported_product_type', 'grouped reason' );
eq( $reasons[ $sale ], 'sale_configured', 'sale reason' );
eq( $reasons[ $empty ], 'empty_regular_price', 'empty reason' );
$approval = Admin::process_approve( approve_post( $job ), 'POST' );
eq( $approval['status'], 'OK', 'mixed approve' );
run_job_terminal( (int) $job['id'] );
eq( fresh_price( $sku_id ), '80.00', 'eligible applied' );
eq( fresh_price( $sale ), '100.00', 'sale product untouched' );
$counts = Repo::counts( (int) $job['id'] );
eq( $counts['unsupported'], 4, 'unsupported terminal' );
marker( 'exclusions with typed reasons' );

// Policy block makes execution impossible.
$block_ids = array( make_product( '100.00' ), make_product( '100.00' ) );
$res = Admin::process_preview( preview_post( array( 'ids' => implode( ',', $block_ids ), 'amount' => '80.00', 'max_products' => '0' ) ), 'POST' );
eq( $res['status'], 'OK', 'blocked preview created' );
eq( $res['blocked'], true, 'blocked flag' );
$job = Repo::read_by_public_id( $res['public_id'] );
eq( $job['status'], 'BLOCKED', 'blocked job state' );
$denied = Admin::process_approve( approve_post( $job ), 'POST' );
eq( $denied['status'], 'INVALID', 'blocked approve refused' );
eq( $denied['reason'], 'plan_policy_blocked', 'blocked reason' );
foreach ( $block_ids as $id ) { eq( fresh_price( $id ), '100.00', 'blocked product unchanged' ); }
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $block_ids[0], 'operation' => Operation::INCREASE_PERCENT, 'amount' => '100', 'max_increase' => '10' ) ), 'POST' );
eq( $res['blocked'], true, 'percentage cap blocks' );
marker( 'policy block prevents execution' );

// Approval binds the exact plan: tampered binding and bad nonces fail.
$res_a = Admin::process_preview( preview_post( array( 'ids' => (string) $block_ids[0], 'amount' => '70.00' ) ), 'POST' );
$res_b = Admin::process_preview( preview_post( array( 'ids' => (string) $block_ids[1], 'amount' => '71.00' ) ), 'POST' );
$job_a = Repo::read_by_public_id( $res_a['public_id'] );
$job_b = Repo::read_by_public_id( $res_b['public_id'] );
$tampered = array( '_wpnonce' => wp_create_nonce( Admin::ACTION_APPROVE . '_' . $job_a['plan_id'] ), 'job' => $job_b['public_id'] );
$out = Admin::process_approve( $tampered, 'POST' );
eq( $out['status'], 'INVALID', 'cross-plan nonce rejected' );
eq( $out['reason'], 'invalid_nonce', 'binding reason' );
$out = Admin::process_approve( array( '_wpnonce' => 'bad-nonce', 'job' => $job_a['public_id'] ), 'POST' );
eq( $out['reason'], 'invalid_nonce', 'bad nonce' );
$out = Admin::process_approve( array( 'job' => $job_a['public_id'] ), 'POST' );
eq( $out['reason'], 'invalid_nonce', 'missing nonce' );
$out = Admin::process_approve( approve_post( $job_a ), 'GET' );
eq( $out['reason'], 'post_required', 'GET approve refused' );
$ok_a = Admin::process_approve( approve_post( $job_a ), 'POST' );
eq( $ok_a['status'], 'OK', 'legit approve' );
$again = Admin::process_approve( approve_post( Repo::read( (int) $job_a['id'] ) ), 'POST' );
eq( $again['reason'], 'already_approved', 'double approve idempotent' );
marker( 'approval binding and nonce separation' );

// Durable execution with browser-close/reopen truth and bounded manual resume.
$resume_ids = array( make_product( '100.00' ), make_product( '100.00' ), make_product( '100.00' ) );
$res = Admin::process_preview( preview_post( array( 'ids' => implode( ',', $resume_ids ), 'amount' => '80.00' ) ), 'POST' );
$job = Repo::read_by_public_id( $res['public_id'] );
Admin::process_approve( approve_post( $job ), 'POST' );
$partial = \WriteLeash\Job_Worker::run( (int) $job['id'], array( 'max_items' => 1, 'budget_seconds' => 20 ), true );
eq( $partial['processed'], 1, 'one item in partial chunk' );
$observed = Repo::observe( (int) $job['id'] );
eq( $observed['counts']['applied'], 1, 'durable applied after close' );
eq( $observed['counts']['pending'], 2, 'durable pending after close' );
// A reopened browser rebuilds the same truth from the repositories.
$reobserved = Repo::observe( (int) $job['id'] );
eq( $reobserved['counts'], $observed['counts'], 'reopen truth matches' );
$history = UndoRepo::history_job( (int) $job['id'] );
eq( $history['apply']['applied'], 1, 'history agrees' );
eq( $history['apply']['pending'], 2, 'history pending agrees' );
$resumed = Admin::process_resume( resume_post( Repo::read( (int) $job['id'] ) ), 'POST' );
eq( $resumed['status'], 'OK', 'manual resume ok' );
run_job_terminal( (int) $job['id'] );
$final = Repo::read( (int) $job['id'] );
eq( $final['status'], 'COMPLETED', 'resumed job completes' );
foreach ( $resume_ids as $id ) { eq( fresh_price( $id ), '80.00', 'resumed price' ); }
$terminal_resume = Admin::process_resume( resume_post( $final ), 'POST' );
eq( $terminal_resume['reason'], 'job_terminal', 'terminal resume refused' );
marker( 'durable progress close reopen manual resume' );

// External edit becomes a conflict; the later price is never overwritten.
$conflict_id = make_product( '100.00' );
$other_id = make_product( '100.00' );
$res = Admin::process_preview( preview_post( array( 'ids' => $conflict_id . ',' . $other_id, 'amount' => '80.00' ) ), 'POST' );
$job = Repo::read_by_public_id( $res['public_id'] );
Admin::process_approve( approve_post( $job ), 'POST' );
$ext = wc_get_product( $conflict_id );
$ext->set_regular_price( '75.00' );
$ext->save();
run_job_terminal( (int) $job['id'] );
$counts = Repo::counts( (int) $job['id'] );
eq( $counts['conflict'], 1, 'one conflict' );
eq( $counts['applied'], 1, 'one applied' );
eq( fresh_price( $conflict_id ), '75.00', 'external price preserved' );
eq( fresh_price( $other_id ), '80.00', 'independent item applied' );
$completed = Repo::read( (int) $job['id'] );
eq( $completed['status'], 'COMPLETED_WITH_ISSUES', 'partial truth, never generic success' );
$html = render_view( 'job', $job['public_id'], 0 );
ok( str_contains( $html, 'COMPLETED_WITH_ISSUES' ), 'progress names the issue state' );
ok( str_contains( $html, 'conflict 1' ), 'progress shows conflict count' );
marker( 'conflict preserves later edits' );

// History reads, pagination and per-user scoping.
$page = UndoRepo::history_jobs( 0, 2 );
eq( count( $page['jobs'] ), 2, 'history page size' );
$full = UndoRepo::history_jobs( 0, 100 );
ok( count( $full['jobs'] ) >= 8, 'history accumulates' );
$items = UndoRepo::history_items( (int) $job['id'], null, null, 0, 1 );
eq( $items['total'], 2, 'items total' );
eq( $items['next_offset'], 1, 'items next offset' );
$items2 = UndoRepo::history_items( (int) $job['id'], null, null, 1, 1 );
eq( $items2['next_offset'], null, 'items last page' );
$filtered = UndoRepo::history_items( (int) $job['id'], IState::CONFLICT, null, 0, 10 );
eq( $filtered['total'], 1, 'conflict filter total' );
$html = render_view( 'history', '', 0 );
ok( str_contains( $html, 'History' ), 'history view renders' );
ok( str_contains( $html, substr( $job['public_id'], 0, 8 ) ), 'history links the job' );
marker( 'history pagination and filters' );

// Undo: eligible restore, conflict stays conflict, expired hides Restore.
$undo_ids = array( make_product( '100.00' ), make_product( '100.00' ) );
$res = Admin::process_preview( preview_post( array( 'ids' => implode( ',', $undo_ids ), 'amount' => '80.00' ) ), 'POST' );
$job = Repo::read_by_public_id( $res['public_id'] );
Admin::process_approve( approve_post( $job ), 'POST' );
run_job_terminal( (int) $job['id'] );
$undo = Admin::process_undo( undo_post( Repo::read( (int) $job['id'] ) ), 'POST' );
eq( $undo['status'], 'OK', 'undo chunk ok' );
run_undo_terminal( (int) UndoRepo::read_operation_by_job( (int) $job['id'] )['id'] );
foreach ( $undo_ids as $id ) { price_eq( fresh_price( $id ), '100.00', 'price restored' ); }
$history = UndoRepo::history_job( (int) $job['id'] );
eq( $history['undo_eligible'], false, 'finished Undo hides Restore' );
$repeat = Admin::process_undo( undo_post( Repo::read( (int) $job['id'] ) ), 'POST' );
eq( $repeat['reason'], 'undo_terminal', 'repeat Undo refused' );
// Conflict path: external edit after apply blocks restore for that product.
$u2 = array( make_product( '100.00' ), make_product( '100.00' ) );
$res = Admin::process_preview( preview_post( array( 'ids' => implode( ',', $u2 ), 'amount' => '80.00' ) ), 'POST' );
$job2 = Repo::read_by_public_id( $res['public_id'] );
Admin::process_approve( approve_post( $job2 ), 'POST' );
run_job_terminal( (int) $job2['id'] );
$ext2 = wc_get_product( $u2[0] );
$ext2->set_regular_price( '70.00' );
$ext2->save();
Admin::process_undo( undo_post( Repo::read( (int) $job2['id'] ) ), 'POST' );
run_undo_terminal( (int) UndoRepo::read_operation_by_job( (int) $job2['id'] )['id'] );
eq( fresh_price( $u2[0] ), '70.00', 'Undo conflict preserves later edit' );
price_eq( fresh_price( $u2[1] ), '100.00', 'clean item restored' );
$op2 = UndoRepo::read_operation_by_job( (int) $job2['id'] );
eq( $op2['status'], 'UNDO_COMPLETED_WITH_ISSUES', 'Undo issues named, never generic success' );
$html = render_view( 'job', $job2['public_id'], 0 );
ok( str_contains( $html, 'does not reverse' ), 'Undo limits explained' );
// Expiry path: backdate the terminal apply beyond the 30-day retention.
$exp = array( make_product( '100.00' ) );
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $exp[0], 'amount' => '80.00' ) ), 'POST' );
$job3 = Repo::read_by_public_id( $res['public_id'] );
Admin::process_approve( approve_post( $job3 ), 'POST' );
run_job_terminal( (int) $job3['id'] );
global $wpdb;
$jobs_table = $wpdb->prefix . 'writeleash_jobs';
$wpdb->query( $wpdb->prepare( "UPDATE `{$jobs_table}` SET completed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 40 DAY), updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 40 DAY) WHERE id = %d", (int) $job3['id'] ) );
$history3 = UndoRepo::history_job( (int) $job3['id'] );
eq( $history3['undo_eligible'], false, 'expired history hides Restore' );
$expired = Admin::process_undo( undo_post( Repo::read( (int) $job3['id'] ) ), 'POST' );
eq( $expired['status'], 'INVALID', 'expired Undo refused' );
eq( $expired['reason'], 'UNDO_EXPIRED', 'expiry reason' );
eq( fresh_price( $exp[0] ), '80.00', 'expired job price untouched' );
marker( 'eligible conflict expired Undo' );

// Security negatives: subscriber, insufficient editor, revoked actor, wrong
// owner, malformed inputs, invalid operation, XSS escaping.
$subscriber = wp_insert_user( array( 'user_login' => 'wl111-sub-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $subscriber );
$sub_post = preview_post( array( 'ids' => (string) $exp[0] ) );
$sub_post['_wpnonce'] = wp_create_nonce( Admin::ACTION_PREVIEW );
$out = Admin::process_preview( $sub_post, 'POST' );
eq( $out['status'], 'FORBIDDEN', 'subscriber forbidden' );
eq( $out['reason'], 'capability_required', 'subscriber reason' );
$editor = wp_insert_user( array( 'user_login' => 'wl111-ed-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$user = new WP_User( $editor );
$user->add_cap( 'edit_products' );
wp_set_current_user( $editor );
$ed_post = preview_post( array( 'ids' => (string) $exp[0] ) );
$ed_post['_wpnonce'] = wp_create_nonce( Admin::ACTION_PREVIEW );
$out = Admin::process_preview( $ed_post, 'POST' );
eq( $out['status'], 'FORBIDDEN', 'editor without manage_woocommerce forbidden' );
wp_set_current_user( 1 );
$manager_a = wp_insert_user( array( 'user_login' => 'wl111-mga-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$manager_b = wp_insert_user( array( 'user_login' => 'wl111-mgb-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
wp_set_current_user( $manager_a );
$mid = make_product( '100.00' );
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $mid ) ), 'POST' );
$owned = Repo::read_by_public_id( $res['public_id'] );
wp_set_current_user( $manager_b );
$out = Admin::process_approve( array( '_wpnonce' => wp_create_nonce( Admin::ACTION_APPROVE . '_' . $owned['plan_id'] ), 'job' => $owned['public_id'] ), 'POST' );
eq( $out['status'], 'FORBIDDEN', 'wrong owner forbidden' );
eq( $out['reason'], 'not_authorized', 'wrong owner reason' );
$html = render_view( 'job', $owned['public_id'], 0 );
ok( str_contains( $html, 'No job is visible' ), 'wrong owner sees nothing' );
// Revoked actor loses execution authority gained at plan creation.
wp_set_current_user( $manager_a );
$u = new WP_User( $manager_a );
$u->set_role( 'subscriber' );
// wp_set_current_user() reuses the in-memory user when the ID is unchanged,
// so a logout/login round-trip models the fresh capability load of a new
// request after revocation.
wp_set_current_user( 0 );
wp_set_current_user( $manager_a );
$revoked_post = array( '_wpnonce' => wp_create_nonce( Admin::ACTION_APPROVE . '_' . $owned['plan_id'] ), 'job' => $owned['public_id'] );
$out = Admin::process_approve( $revoked_post, 'POST' );
eq( $out['status'], 'FORBIDDEN', 'revoked actor forbidden' );
eq( fresh_price( $mid ), '100.00', 'revoked actor mutated nothing' );
// Manager A is restored for the remaining malformed-input matrix.
$u->set_role( 'shop_manager' );
wp_set_current_user( 0 );
wp_set_current_user( $manager_a );
foreach ( array( 'abc', '', '1,,2', '0', '1, 2,', '99999999999999999999999' ) as $bad_ids ) {
	$out = Admin::process_preview( preview_post( array( 'ids' => $bad_ids ) ), 'POST' );
	eq( $out['status'], 'INVALID', 'malformed ids rejected: ' . $bad_ids );
}
foreach ( array( '12,5%', '$10', ' 10', '10 ', '.5', '1.', '01x' ) as $bad_amount ) {
	$out = Admin::process_preview( preview_post( array( 'ids' => (string) $mid, 'amount' => $bad_amount ) ), 'POST' );
	eq( $out['status'], 'INVALID', 'malformed amount rejected: ' . $bad_amount );
}
$float_post = preview_post( array( 'ids' => (string) $mid ) );
$float_post['amount'] = 12.5;
$out = Admin::process_preview( $float_post, 'POST' );
eq( $out['status'], 'INVALID', 'non-string amount rejected' );
$out = Admin::process_preview( preview_post( array( 'ids' => (string) $mid, 'operation' => 'DOUBLE' ) ), 'POST' );
eq( $out['status'], 'INVALID', 'invalid operation rejected' );
$out = Admin::process_preview( preview_post( array( 'selector' => 'title', 'ids' => (string) $mid ) ), 'POST' );
eq( $out['status'], 'INVALID', 'title selector unsupported' );
$out = Admin::process_preview( preview_post( array( 'selector' => 'sku', 'sku' => '' ) ), 'POST' );
eq( $out['status'], 'INVALID', 'empty sku rejected' );
$out = Admin::process_preview( preview_post( array( 'selector' => 'category', 'category' => '999999999' ) ), 'POST' );
eq( $out['status'], 'INVALID', 'missing category rejected' );
$out = Admin::process_preview( preview_post( array( 'ids' => (string) $mid, 'max_products' => 'abc' ) ), 'POST' );
eq( $out['status'], 'INVALID', 'malformed policy rejected' );
$out = Admin::process_preview( preview_post( array( 'ids' => (string) $mid, 'block_zero' => 'yes' ) ), 'POST' );
eq( $out['status'], 'INVALID', 'malformed flag rejected' );
// XSS: hostile name saved by a privileged author (unfiltered_html) must be
// escaped in every rendered view; the authoring role matters, not just render.
wp_set_current_user( 1 );
$xss_id = make_product( '100.00', 'publish', array( 'name' => '<script>alert("wl111")</script>' ) );
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $xss_id ) ), 'POST' );
$xjob = Repo::read_by_public_id( $res['public_id'] );
foreach ( array( 'preview', 'job' ) as $v ) {
	$html = render_view( $v, $xjob['public_id'], 0 );
	ok( false === strpos( $html, '<script>' ), 'no raw script in ' . $v );
}
ok( str_contains( render_view( 'preview', $xjob['public_id'], 0 ), '&lt;script' ), 'escaped hostile name' );
marker( 'security negatives and escaping' );

// Accessibility and honest scope copy in the rendered product.
wp_set_current_user( 1 );
$home = render_view( '', '', 0 );
foreach ( array( 'Build frozen preview', '<label for=', 'aria-describedby=', 'Variations', 'sale prices', 'not guaranteed shopper prices' ) as $needle ) {
	ok( str_contains( $home, $needle ), 'home copy: ' . $needle );
}
ok( false !== strpos( $home, 'Explicit product IDs' ), 'only proved selectors offered' );
ok( false === strpos( $home, 'title search' ), 'no decorative title filter' );
$single = render_view( 'preview', $xjob['public_id'], 0 );
ok( str_contains( $single, 'disabled' ) && str_contains( $single, 'aria-disabled' ), 'pager disabled semantics' );
set_transient( 'writeleash_free_notice_' . get_current_user_id(), array( 'status' => 'OK', 'reason' => 'preview_ready' ), 120 );
$noticed = render_view( '', '', 0 );
ok( str_contains( $noticed, 'role="alert"' ), 'notice role alert' );
ok( false !== strpos( $noticed, 'non-color' ) || str_contains( $noticed, 'Preview ready' ), 'notice text status' );
marker( 'accessibility and honest scope' );

// Zero-price and percent-from-zero boundaries.
$zero_id = make_product( '0.00' );
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $zero_id, 'amount' => '5.00' ) ), 'POST' );
eq( $res['status'], 'OK', 'fixed from zero preview' );
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $zero_id, 'operation' => Operation::INCREASE_PERCENT, 'amount' => '10' ) ), 'POST' );
eq( $res['status'], 'OK', 'percent from zero stays a preview' );
$zplan = Repo::hydrate_plan( Repo::read_by_public_id( $res['public_id'] ) );
eq( $zplan->preview_page( 0, 20 )['items'][0]['eligibility']['reason'], 'percent_from_zero_undefined', 'percent from zero reason' );
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $zero_id, 'operation' => Operation::SET, 'amount' => '0.00', 'block_zero' => '1' ) ), 'POST' );
eq( $res['blocked'], false, 'unchanged zero never violates the zero policy' );
$nonzero_id = make_product( '100.00' );
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $nonzero_id, 'operation' => Operation::SET, 'amount' => '0.00', 'block_zero' => '1' ) ), 'POST' );
eq( $res['blocked'], true, 'block-zero policy blocks' );
marker( 'zero price boundaries' );

// WooCommerce dependency loss fails closed at the Admin boundary. Plugin
// code cannot be unloaded in-process, so the loss itself is asserted in a
// fresh `wp eval-file` process where Woo is genuinely absent.
if ( ! function_exists( 'deactivate_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
deactivate_plugins( 'woocommerce/woocommerce.php' );
$lost_script = __DIR__ . '/dependency-lost.php';
$lost_command = 'wp --path=' . escapeshellarg( ABSPATH ) . ' eval-file ' . escapeshellarg( $lost_script ) . ' 2>&1';
$lost_output = array();
$lost_code = 0;
exec( $lost_command, $lost_output, $lost_code );
ok( 0 === $lost_code, 'fresh-process loss probe exits clean: ' . implode( "\n", $lost_output ) );
$activated = activate_plugin( 'woocommerce/woocommerce.php' );
ok( ! is_wp_error( $activated ), 'Woo reactivation succeeds' );
ok( Admin::dependency_ok(), 'dependency gate reopens' );
marker( 'dependency loss fails closed' );

echo "#111[$cache_mode] Free Admin workflow E2E: PASS ($assertions assertions)\n";
