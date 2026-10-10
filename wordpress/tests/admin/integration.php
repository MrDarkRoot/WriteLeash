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
		'max_products' => '100',
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
eq( \WriteLeash\Free_Support_Contract::woo_supported( WC_VERSION ), true, 'fixture Woo inside supported range' );
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

// Complete preview information contract: one plan carrying a large
// decrease pair plus zero targets, and one plan with large increases.
// Summary counts and per-row absolute/percentage deltas must equal the
// frozen plan truth exactly; conflicts read 0 at preview by plan semantics.
$zd_ids = array( make_product( '100.00' ), make_product( '50.00' ), make_product( '0.00' ), make_product( '100.00', 'draft' ) );
$res = Admin::process_preview( preview_post( array( 'ids' => implode( ',', $zd_ids ), 'operation' => Operation::SET, 'amount' => '0.00', 'max_increase' => '500', 'max_decrease' => '500', 'warning_threshold' => '20' ) ), 'POST' );
eq( $res['status'], 'OK', 'zero-target preview' );
$zjob = Repo::read_by_public_id( $res['public_id'] );
$zplan = Repo::hydrate_plan( $zjob );
$zsummary = $zplan->summary();
eq( $zsummary['changing'], 2, 'two changing to zero' );
eq( $zsummary['unchanged'], 1, 'zero-to-zero unchanged' );
eq( $zsummary['unsupported'], 1, 'draft unsupported' );
eq( $zsummary['conflicted'], 0, 'previews carry no conflicts' );
$zextra = Admin::preview_extra_counts( $zplan->data()['items'] );
eq( $zextra, array( 'large_increase' => 0, 'large_decrease' => 2, 'zero_target' => 2 ), 'zero-plan extra counts' );
$zhtml = render_view( 'preview', $zjob['public_id'], 0 );
ok( str_contains( $zhtml, 'Large increases 0' ), 'rendered large increases' );
ok( str_contains( $zhtml, 'large decreases 2' ), 'rendered large decreases' );
ok( str_contains( $zhtml, 'zero-price targets 2' ), 'rendered zero targets' );
ok( str_contains( $zhtml, '2 planned changes' ), 'preview categories do not double-count policy-blocked changes' );
ok( str_contains( $zhtml, '-100%' ), 'rendered full-decrease percentage' );
$li_ids = array( make_product( '100.00' ), make_product( '150.00' ), make_product( '200.00' ) );
$res = Admin::process_preview( preview_post( array( 'ids' => implode( ',', $li_ids ), 'operation' => Operation::SET, 'amount' => '200.00', 'max_increase' => '500', 'max_decrease' => '500', 'warning_threshold' => '20' ) ), 'POST' );
eq( $res['status'], 'OK', 'large-increase preview' );
$lijob = Repo::read_by_public_id( $res['public_id'] );
$liplan = Repo::hydrate_plan( $lijob );
$liextra = Admin::preview_extra_counts( $liplan->data()['items'] );
eq( $liextra, array( 'large_increase' => 2, 'large_decrease' => 0, 'zero_target' => 0 ), 'increase-plan extra counts' );
$lihtml = render_view( 'preview', $lijob['public_id'], 0 );
ok( str_contains( $lihtml, 'Large increases 2' ), 'rendered large increases 2' );
ok( str_contains( $lihtml, '100%' ), 'rendered full-increase percentage' );
// Every rendered row matches its frozen plan row exactly.
foreach ( $liplan->preview_page( 0, 20 )['items'] as $prow ) {
	ok( str_contains( $lihtml, Admin::money_display( $prow['stored_regular_price'], $liplan->data()['store'] ) ), 'row before matches plan' );
	ok( str_contains( $lihtml, Admin::money_display( $prow['planned_regular_price'], $liplan->data()['store'] ) ), 'row after matches plan' );
	ok( str_contains( $lihtml, Admin::money_display( $prow['absolute_delta'], $liplan->data()['store'], true ) ), 'row delta matches plan' );
	$disp = $prow['percentage_delta']['display'] ?? null;
	if ( is_string( $disp ) ) {
		ok( str_contains( $lihtml, Admin::percentage_display( $disp, $prow['percentage_delta']['numerator'] ?? null ) ), 'row percentage matches plan' );
	}
}
marker( 'complete preview information contract' );

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
eq( $reasons[ $sale ], 'regular_price_not_above_sale', 'sale-clearing regular target reason' );
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
ok( str_contains( $html, '1 conflict' ), 'progress shows conflict count' );
marker( 'conflict preserves later edits' );

// History reads, pagination and per-user scoping.
$page = UndoRepo::history_jobs( 0, 2, 1 );
eq( count( $page['jobs'] ), 2, 'history page size' );
$full = UndoRepo::history_jobs( 0, 100, 1 );
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

// Actor-scoped history pagination: interleaved jobs from two managers with
// more than one page each. Scoping happens in SQL before LIMIT/OFFSET, so a
// page never hides behind another actor's newer rows.
$scoped_a = wp_insert_user( array( 'user_login' => 'wl111-sca-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$scoped_b = wp_insert_user( array( 'user_login' => 'wl111-scb-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$scoped_ids = array();
for ( $i = 0; $i < 7; ++$i ) {
	foreach ( array( $scoped_a, $scoped_b ) as $owner ) {
		wp_set_current_user( $owner );
		$pid = make_product( '100.00' );
		$res = Admin::process_preview( preview_post( array( 'ids' => (string) $pid, 'amount' => '90.00' ) ), 'POST' );
		eq( $res['status'], 'OK', 'scoped preview for owner ' . $owner );
		$scoped_ids[ $owner ][] = (int) Repo::read_by_public_id( $res['public_id'] )['id'];
	}
}
wp_set_current_user( 1 );
foreach ( array( $scoped_a, $scoped_b, 1 ) as $viewer ) {
	$seen = array();
	$offset = 0;
	do {
		$pg = UndoRepo::history_jobs( $offset, 5, (int) $viewer );
		foreach ( $pg['jobs'] as $entry ) {
			$seen[] = (int) $entry['job_id'];
			if ( 1 !== (int) $viewer ) {
				$jr = Repo::read( (int) $entry['job_id'] );
				eq( (int) $jr['creator_id'], (int) $viewer, 'no foreign job leaks to viewer ' . $viewer );
			}
		}
		$offset = $pg['next_offset'];
	} while ( null !== $offset );
	if ( 1 === (int) $viewer ) {
		ok( count( $seen ) >= 14, 'admin override sees the global set' );
	} else {
		$expected = $scoped_ids[ $viewer ];
		rsort( $expected, SORT_NUMERIC );
		eq( $seen, $expected, 'viewer sees every own job newest-first' );
	}
}
// Recent jobs is actor-scoped as well: B's five newest hide none of A's own.
$recent_a = UndoRepo::history_jobs( 0, 5, (int) $scoped_a );
eq( count( $recent_a['jobs'] ), 5, 'scoped recent page full' );
foreach ( $recent_a['jobs'] as $entry ) {
	eq( (int) Repo::read( (int) $entry['job_id'] )['creator_id'], (int) $scoped_a, 'recent has zero foreign rows' );
}
// Unknown viewers see an empty page with no queries against hidden rows.
$anon = UndoRepo::history_jobs( 0, 20, 0 );
eq( $anon, array( 'offset' => 0, 'limit' => 20, 'total' => 0, 'jobs' => array(), 'next_offset' => null ), 'anonymous history empty' );
marker( 'actor-scoped history pagination' );

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

// #178: sale-price targets and regular edits on sale-configured products.
$sale_target_id = make_product( '100.00' );
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $sale_target_id, 'operation' => Operation::SET, 'amount' => '80.00', 'price_field' => Operation::FIELD_SALE ) ), 'POST' );
eq( $res['status'], 'OK', 'sale SET preview' );
$sale_job = Repo::read_by_public_id( $res['public_id'] );
$sale_plan = Repo::hydrate_plan( $sale_job );
eq( $sale_plan->price_field(), Operation::FIELD_SALE, 'sale plan records its field' );
eq( Admin::task_description( $sale_plan->data() ) !== '' && str_contains( Admin::task_description( $sale_plan->data() ), 'sale prices' ), true, 'sale task copy names the field' );
eq( Admin::process_approve( approve_post( $sale_job ), 'POST' )['status'], 'OK', 'sale plan approved' );
run_job_terminal( (int) $sale_job['id'] );
Verifier::invalidate( $sale_target_id );
$sale_product = wc_get_product( $sale_target_id );
eq( $sale_product->get_regular_price( 'edit' ), '100.00', 'first-sale apply preserves the regular baseline' );
eq( $sale_product->get_sale_price( 'edit' ), '80.00', 'first-sale target applied' );
eq( Decimal::parse( $sale_product->get_price( 'edit' ) ), Decimal::parse( '80.00' ), 'active price follows the applied sale' );
$observer178 = Verifier::observer();
try {
	$storage178 = Verifier::storage( $observer178, $sale_target_id );
	eq( (string) $storage178['lookup']['onsale'], '1', 'lookup onsale proves the sale is the active price' );
	eq( Decimal::parse( $storage178['lookup']['min_price'] ), Decimal::parse( '80.00' ), 'lookup active price follows the sale' );
} finally { $observer178->close(); }
$csv178 = fopen( 'php://temp', 'w+' );
try {
	Admin::write_job_csv( $csv178, $sale_job, $sale_plan );
	rewind( $csv178 );
	$header178 = fgetcsv( $csv178, 0, ',', '"', '' );
	$row178 = fgetcsv( $csv178, 0, ',', '"', '' );
	$field178 = array_search( 'price_field', $header178, true );
	eq( $field178 !== false && $row178[ $field178 ] === 'Sale price', true, 'CSV names the changed field' );
} finally { fclose( $csv178 ); }
// Percentage change on the existing sale uses the sale baseline.
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $sale_target_id, 'operation' => Operation::INCREASE_PERCENT, 'amount' => '10', 'price_field' => Operation::FIELD_SALE ) ), 'POST' );
$sale_job2 = Repo::read_by_public_id( $res['public_id'] );
eq( Repo::hydrate_plan( $sale_job2 )->item( $sale_target_id )->data()['planned_regular_price'], '88.00', 'sale percent baseline is the sale price' );
eq( Admin::process_approve( approve_post( $sale_job2 ), 'POST' )['status'], 'OK', 'sale percentage plan approved' );
run_job_terminal( (int) $sale_job2['id'] );
Verifier::invalidate( $sale_target_id );
eq( wc_get_product( $sale_target_id )->get_sale_price( 'edit' ), '88.00', 'sale percentage applied and regular preserved' );
// Regular edit on a product with an active sale and dates preserves both.
$preserve_id = make_product( '100.00' );
$p = wc_get_product( $preserve_id ); $p->set_sale_price( '80.00' ); $p->set_date_on_sale_from( time() - 3600 ); $p->set_date_on_sale_to( time() + 86400 ); $p->save();
$preserve_from = wc_get_product( $preserve_id )->get_date_on_sale_from( 'edit' );
$preserve_to = wc_get_product( $preserve_id )->get_date_on_sale_to( 'edit' );
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $preserve_id, 'operation' => Operation::SET, 'amount' => '90.00' ) ), 'POST' );
eq( $res['status'], 'OK', 'regular edit on an active sale previews' );
$preserve_job = Repo::read_by_public_id( $res['public_id'] );
eq( Admin::process_approve( approve_post( $preserve_job ), 'POST' )['status'], 'OK', 'regular-on-sale plan approved' );
run_job_terminal( (int) $preserve_job['id'] );
Verifier::invalidate( $preserve_id );
$preserved = wc_get_product( $preserve_id );
eq( $preserved->get_regular_price( 'edit' ), '90.00', 'regular target applied' );
eq( $preserved->get_sale_price( 'edit' ), '80.00', 'active sale preserved by a regular edit' );
eq( $preserved->get_date_on_sale_from( 'edit' )->getTimestamp(), $preserve_from->getTimestamp(), 'sale start preserved' );
eq( $preserved->get_date_on_sale_to( 'edit' )->getTimestamp(), $preserve_to->getTimestamp(), 'sale end preserved' );
eq( Decimal::parse( $preserved->get_price( 'edit' ) ), Decimal::parse( '80.00' ), 'active shopper price stays the sale' );
$lookup_observer178 = Verifier::observer();
try { $lookup178 = Verifier::storage( $lookup_observer178, $preserve_id ); }
finally { $lookup_observer178->close(); }
eq( (string) $lookup178['lookup']['onsale'], '1', 'lookup onsale survives a regular edit' );
// Refusals: never let WooCommerce silently clear the sale.
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $preserve_id, 'operation' => Operation::SET, 'amount' => '80.00' ) ), 'POST' );
$refused_plan = Repo::hydrate_plan( Repo::read_by_public_id( $res['public_id'] ) );
eq( $refused_plan->item( $preserve_id )->data()['eligibility']['reason'], 'regular_price_not_above_sale', 'regular target at the sale is refused' );
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $preserve_id, 'operation' => Operation::INCREASE_FIXED, 'amount' => '20', 'price_field' => Operation::FIELD_SALE ) ), 'POST' );
$refused_plan = Repo::hydrate_plan( Repo::read_by_public_id( $res['public_id'] ) );
eq( $refused_plan->item( $preserve_id )->data()['eligibility']['reason'], 'sale_price_not_below_regular', 'sale target at the regular price is refused' );
$no_sale_id = make_product( '100.00' );
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $no_sale_id, 'operation' => Operation::INCREASE_FIXED, 'amount' => '5', 'price_field' => Operation::FIELD_SALE ) ), 'POST' );
$refused_plan = Repo::hydrate_plan( Repo::read_by_public_id( $res['public_id'] ) );
eq( $refused_plan->item( $no_sale_id )->data()['eligibility']['reason'], 'empty_sale_price', 'fixed increase from a missing sale is refused' );
// Undo restores only the sale field and removes a sale WriteLeash added.
$res = Admin::process_preview( preview_post( array( 'ids' => (string) $no_sale_id, 'operation' => Operation::SET, 'amount' => '70.00', 'price_field' => Operation::FIELD_SALE ) ), 'POST' );
$remove_job = Repo::read_by_public_id( $res['public_id'] );
Admin::process_approve( approve_post( $remove_job ), 'POST' );
run_job_terminal( (int) $remove_job['id'] );
Admin::process_undo( undo_post( Repo::read( (int) $remove_job['id'] ) ), 'POST' );
run_undo_terminal( (int) UndoRepo::read_operation_by_job( (int) $remove_job['id'] )['id'] );
Verifier::invalidate( $no_sale_id );
$removed = wc_get_product( $no_sale_id );
eq( $removed->get_regular_price( 'edit' ), '100.00', 'sale-field undo leaves the regular price untouched' );
eq( $removed->get_sale_price( 'edit' ), '', 'sale-field undo removes the WriteLeash-added sale' );
eq( Decimal::parse( $removed->get_price( 'edit' ) ), Decimal::parse( '100.00' ), 'active price returns to the regular price' );
marker( 'sale targets, preserved sale configuration and field-scoped sale Undo' );

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
foreach ( array( 'Preview price changes', '<label for=', 'aria-describedby=', 'Variations', 'sale prices', 'not guaranteed shopper prices' ) as $needle ) {
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

// #122 Phase A: real conflict plus fresh read-only Current evidence.
// Match cells in ONE rendered row, rather than unrelated text on the page.
function wl122_current_rows( string $html ): array {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	libxml_clear_errors();
	$xpath = new DOMXPath( $dom );
	$tables = $xpath->query( '//table[@data-writeleash-results="1"]' );
	eq( $tables->length, 1, 'exactly one results item table' );
	$headers = array();
	foreach ( $xpath->query( './thead/tr/th', $tables->item( 0 ) ) as $cell ) { $headers[] = trim( $cell->textContent ); }
	eq( $headers, array( 'Product', 'Regular price expected', 'Regular price now', 'Regular price planned', 'Apply', 'Undo' ), 'field-labelled column order' );
	$rows = array();
	foreach ( $xpath->query( './tbody/tr', $tables->item( 0 ) ) as $row ) {
		if ( ! $row->hasAttribute( 'data-product-id' ) ) { continue; }
		$cells = array();
		foreach ( $xpath->query( './td', $row ) as $cell ) { $cells[] = trim( $cell->textContent ); }
		eq( count( $cells ), 6, 'six item cells' );
		$rows[ (int) $row->getAttribute( 'data-product-id' ) ] = $cells;
	}
	return $rows;
}
$current_id = make_product( '18.00' );
$current_other = make_product( '24.00' );
$current_preview = Admin::process_preview( preview_post( array( 'ids' => $current_id . ',' . $current_other, 'operation' => Operation::DECREASE_PERCENT, 'amount' => '20' ) ), 'POST' );
$current_job = Repo::read_by_public_id( $current_preview['public_id'] );
eq( Admin::process_approve( approve_post( $current_job ), 'POST' )['status'], 'OK', 'Current fixture approved' );
$old_product = wc_get_product( $current_id );
$old_meta = get_post_meta( $current_id );
eq( $old_product->get_regular_price( 'edit' ), '18.00', 'prime old Woo regular price' );
$edited_product = wc_get_product( $current_id );
$edited_product->set_regular_price( '21.00' );
$edited_product->save();
run_job_terminal( (int) $current_job['id'] );
eq( Repo::counts( (int) $current_job['id'] )['conflict'], 1, '18 to 21 is a real worker conflict' );
$before_current_job = Repo::read( (int) $current_job['id'] );
$before_current_items = UndoRepo::history_items( (int) $current_job['id'], null, null, 0, 50 );
$before_current_history = UndoRepo::history_job( (int) $current_job['id'] );
$before_current_journal = Journal::read( $wpdb, $current_job['plan_id'], $current_id );
// Reintroduce known stale cache AFTER the worker's own eviction. Without a
// fresh display read this is a failing negative control, in both cache modes.
wp_cache_set( $current_id, $old_meta, 'post_meta' );
wc_get_container()->get( \Automattic\WooCommerce\Internal\Caches\ProductCache::class )->set( $old_product );
eq( get_post_meta( $current_id, '_regular_price', true ), '18.00', 'negative control: metadata cache is stale' );
// A storefront filter must not masquerade as the stored regular price.
$shopper_price = static function () { return '999.00'; };
add_filter( 'woocommerce_product_get_regular_price', $shopper_price );
$render_queries = array();
$trace_queries = static function ( $query ) use ( &$render_queries ) { $render_queries[] = $query; return $query; };
add_filter( 'query', $trace_queries );
try { $current_html = render_view( 'job', $current_job['public_id'], 0 ); }
finally { remove_filter( 'query', $trace_queries ); remove_filter( 'woocommerce_product_get_regular_price', $shopper_price ); }
$current_rows = wl122_current_rows( $current_html );
eq( array_slice( $current_rows[$current_id], 1, 3 ), array( '$18.00 USD', '$21.00 USD', '$14.40 USD' ), 'same conflict row preserves saved and current decimal strings' );
ok( str_contains( $current_rows[$current_id][4], 'Not changed' ) && str_contains( $current_rows[$current_id][4], 'left the newer value unchanged' ), 'merchant conflict explanation in the affected row' );
eq( $current_rows[$current_other][2], '$19.20 USD', 'ordinary applied row also uses fresh Current' );
foreach ( $render_queries as $query ) {
	ok( ! preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|GRANT|REVOKE|START\s+TRANSACTION|BEGIN|LOCK)\b|\bFOR\s+UPDATE\b/i', $query ), 'Current render is SELECT-only, with no mutation/worker lock' );
}
eq( Repo::read( (int) $current_job['id'] ), $before_current_job, 'render leaves job authority/state untouched' );
eq( UndoRepo::history_items( (int) $current_job['id'], null, null, 0, 50 ), $before_current_items, 'render leaves items untouched' );
eq( UndoRepo::history_job( (int) $current_job['id'] ), $before_current_history, 'render leaves Undo eligibility untouched' );
eq( Journal::read( $wpdb, $current_job['plan_id'], $current_id ), $before_current_journal, 'render leaves journal untouched' );
// Independent connection checks storage after render; no object/meta cache.
$current_observer = Verifier::observer();
try { eq( Verifier::storage( $current_observer, $current_id )['meta']['_regular_price'][0], '21.00', 'independent storage proves no overwrite after render' ); }
finally { $current_observer->close(); }
marker( 'fresh Current conflict row and read-only/no-overwrite proof' );

// Missing, malformed and exceptional reads must remain truthful and local to
// the affected row. Frozen Expected/Planned values must never be substituted.
$missing_current_id = make_product( '18.00' );
$malformed_current_id = make_product( '18.00' );
$valid_current_id = make_product( '18.00' );
$missing_preview = Admin::process_preview( preview_post( array( 'ids' => implode( ',', array( $missing_current_id, $malformed_current_id, $valid_current_id ) ), 'amount' => '14.40' ) ), 'POST' );
$missing_job = Repo::read_by_public_id( $missing_preview['public_id'] );
wp_delete_post( $missing_current_id, true );
update_post_meta( $malformed_current_id, '_regular_price', 'not-a-price' ); // Fixture corruption only.
$missing_rows = wl122_current_rows( render_view( 'job', $missing_job['public_id'], 0 ) );
eq( $missing_rows[$missing_current_id][2], 'Unavailable', 'missing product Current unavailable' );
eq( $missing_rows[$malformed_current_id][2], 'Unavailable', 'malformed stored price Current unavailable' );
eq( $missing_rows[$valid_current_id][2], '$18.00 USD', 'one unavailable Current does not prevent other rows' );
$unreadable = static function ( $class, $type, $post_type, $id ) use ( $current_id ) {
	if ( $id === $current_id ) { throw new RuntimeException( 'test-only unreadable product' ); }
	return $class;
};
add_filter( 'woocommerce_product_class', $unreadable, 10, 4 );
try { $unreadable_rows = wl122_current_rows( render_view( 'job', $current_job['public_id'], 0 ) ); }
finally { remove_filter( 'woocommerce_product_class', $unreadable, 10 ); }
eq( $unreadable_rows[$current_id][2], 'Unavailable', 'read exception does not fatal or fall back to Expected/Planned' );
eq( $unreadable_rows[$current_other][2], '$19.20 USD', 'read exception isolated to one row' );
marker( 'missing malformed unreadable Current remains nonfatal' );

// A 51-product job must read only the 50 visible rows, then only the last row.
// Observe real Woo reads; no mocked price provider or whole-job scan.
$current_page_ids = array();
for ( $i = 0; $i < Admin::ITEM_PAGE_SIZE + 1; ++$i ) { $current_page_ids[] = make_product( '18.00' ); }
$page_preview = Admin::process_preview( preview_post( array( 'ids' => implode( ',', $current_page_ids ), 'amount' => '14.40' ) ), 'POST' );
$page_job = Repo::read_by_public_id( $page_preview['public_id'] );
$current_reads = array();
$trace_reads = static function ( $id ) use ( &$current_reads ) { $current_reads[] = $id; };
add_action( 'woocommerce_product_read', $trace_reads );
try {
	$page_rows = wl122_current_rows( render_view( 'job', $page_job['public_id'], 0 ) );
	eq( count( $page_rows ), Admin::ITEM_PAGE_SIZE, 'first item page size' );
	eq( $current_reads, array_slice( $current_page_ids, 0, Admin::ITEM_PAGE_SIZE ), 'Current Woo reads bounded to first page IDs' );
	$current_reads = array();
	$page_rows = wl122_current_rows( render_view( 'job', $page_job['public_id'], Admin::ITEM_PAGE_SIZE ) );
	eq( count( $page_rows ), 1, 'last item page size' );
	eq( $current_reads, array_slice( $current_page_ids, Admin::ITEM_PAGE_SIZE ), 'Current Woo reads bounded to last page ID' );
	$current_reads = array();
	$page_rows = wl122_current_rows( render_view( 'job', $page_job['public_id'], 2 * Admin::ITEM_PAGE_SIZE ) );
	eq( count( $page_rows ), 0, 'empty item page size' );
	eq( $current_reads, array(), 'empty page makes zero Current Woo reads' );
} finally { remove_action( 'woocommerce_product_read', $trace_reads ); }
marker( 'Current reads bounded to rendered page only' );

// ---------------------------------------------------------------------------
// #179 variable products: preview freezes exact variations with attribute
// identity, Apply refreshes the Woo parent price range, a variation created
// after preview cannot execute, and Undo restores per variation while
// re-syncing the parent.
// ---------------------------------------------------------------------------
$var_name = 'WL179 Hoodie ' . wp_generate_uuid4();
$var_parent = new WC_Product_Variable();
$var_parent->set_name( $var_name );
$var_parent->set_status( 'publish' );
// A real variable product declares its variation attributes on the parent;
// Woo only surfaces variation attribute values declared here.
$var_color_attr = new WC_Product_Attribute();
$var_color_attr->set_id( 0 );
$var_color_attr->set_name( 'Color' );
$var_color_attr->set_options( array( 'Blue', 'Red', 'Green' ) );
$var_color_attr->set_position( 0 );
$var_color_attr->set_visible( true );
$var_color_attr->set_variation( true );
$var_size_attr = new WC_Product_Attribute();
$var_size_attr->set_id( 0 );
$var_size_attr->set_name( 'Size' );
$var_size_attr->set_options( array( 'M', 'L', 'S' ) );
$var_size_attr->set_position( 1 );
$var_size_attr->set_visible( true );
$var_size_attr->set_variation( true );
$var_parent->set_attributes( array( $var_color_attr, $var_size_attr ) );
$var_parent->save();
$var_parent_id = $var_parent->get_id();
$mk_variation179 = static function ( string $price, array $attrs ) use ( $var_parent_id ): int {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $var_parent_id );
	$v->set_regular_price( $price );
	$v->set_attributes( $attrs );
	$v->save();
	return $v->get_id();
};
$v_blue = $mk_variation179( '100.00', array( 'color' => 'Blue', 'size' => 'M' ) );
$v_red = $mk_variation179( '50.00', array( 'color' => 'Red', 'size' => 'L' ) );
$parent_lookup179 = static function ( int $parent_id ): array {
	$db = Verifier::observer();
	try {
		$row = $db->get_row( $db->prepare( 'SELECT min_price,max_price,onsale FROM ' . $db->wc_product_meta_lookup . ' WHERE product_id=%d', $parent_id ), ARRAY_A );
	} finally { $db->close(); }
	if ( ! is_array( $row ) ) { throw new RuntimeException( 'parent lookup row missing' ); }
	return $row;
};
$vpreview = Admin::process_preview( preview_post( array( 'ids' => (string) $var_parent_id, 'operation' => Operation::DECREASE_PERCENT, 'amount' => '20' ) ), 'POST' );
eq( $vpreview['status'], 'OK', 'variable parent creates a preview' );
$vjob = Repo::read_by_public_id( $vpreview['public_id'] );
$vplan = Repo::hydrate_plan( $vjob );
eq( $vplan->summary()['selected'], 2, 'parent selection freezes both variations' );
eq( in_array( $var_parent_id, $vplan->data()['resolved_product_ids'], true ), false, 'the variable parent is replaced by its variations' );
eq( $vplan->data()['selection']['ids'], array( $v_blue, $v_red ), 'frozen IDS selection holds the resolved variation IDs' );
$vfrozen = $vplan->item( $v_blue )->data()['snapshot'];
eq( $vfrozen['core_variation'], true, 'variation identity frozen' );
eq( $vfrozen['parent_id'], $var_parent_id, 'variation parent frozen' );
eq( $vfrozen['variation_label'], $var_name . ' — Blue / M', 'variation attribute identity frozen' );
$vhtml = render_view( 'preview', $vjob['public_id'], 0 );
ok( str_contains( $vhtml, 'Variation #' . $v_blue ), 'preview labels the row as a variation' );
ok( str_contains( $vhtml, '— Blue / M' ), 'preview shows human-readable attributes' );
ok( str_contains( $vhtml, '2 variations' ), 'task copy counts variations honestly' );
$vfound = \WriteLeash\Product_Discovery::products( $var_name );
$vresult_ids = array_map( 'intval', array_column( $vfound['results'], 'id' ) );
ok( in_array( $v_blue, $vresult_ids, true ) && in_array( $v_red, $vresult_ids, true ), 'discovery lists individual variations of the parent' );
$vblue_text = '';
$vparent_text = '';
foreach ( $vfound['results'] as $vrow ) {
	if ( (int) $vrow['id'] === $v_blue ) { $vblue_text = $vrow['text']; }
	if ( (int) $vrow['id'] === $var_parent_id ) { $vparent_text = $vrow['text']; }
}
ok( str_contains( $vblue_text, '— Blue / M' ) && str_contains( $vblue_text, 'ID: ' . $v_blue ), 'variation discovery label shows attributes and ID' );
ok( str_contains( $vparent_text, 'Targets all 2 variations' ), 'variable parent is offered as an all-variations choice' );

// A variation created after preview is not in the frozen ID list.
$v_green = $mk_variation179( '60.00', array( 'color' => 'Green', 'size' => 'S' ) );
ok( ! in_array( $v_green, $vplan->data()['resolved_product_ids'], true ), 'a later variation is outside the frozen population' );
try { $vplan->item( $v_green ); ok( false, 'frozen plan must refuse the later variation' ); }
catch ( WriteLeash\Price_Validation_Error $error ) { eq( $error->reason(), 'unpreviewed_product', 'frozen plan refuses the later variation' ); }

$vapprove = Admin::process_approve( approve_post( $vjob ), 'POST' );
eq( $vapprove['status'], 'OK', 'variation plan approves' );
run_job_terminal( (int) $vjob['id'] );
eq( fresh_price( $v_blue ), '80.00', 'first variation applied' );
eq( fresh_price( $v_red ), '40.00', 'sibling variation applied independently' );
eq( fresh_price( $v_green ), '60.00', 'post-preview variation untouched by Apply' );
$lookup = $parent_lookup179( $var_parent_id );
eq( Decimal::parse( $lookup['min_price'] ), Decimal::parse( '40.00' ), 'parent lookup min refreshed after Apply' );
eq( Decimal::parse( $lookup['max_price'] ), Decimal::parse( '80.00' ), 'parent lookup max refreshed after Apply' );
eq( (string) $lookup['onsale'], '0', 'parent stays offsale after Apply' );

Admin::process_undo( undo_post( Repo::read( (int) $vjob['id'] ) ), 'POST' );
run_undo_terminal( (int) UndoRepo::read_operation_by_job( (int) $vjob['id'] )['id'] );
price_eq( fresh_price( $v_blue ), '100.00', 'first variation restored' );
price_eq( fresh_price( $v_red ), '50.00', 'sibling variation restored' );
eq( fresh_price( $v_green ), '60.00', 'post-preview variation untouched by Undo' );
$lookup = $parent_lookup179( $var_parent_id );
eq( Decimal::parse( $lookup['min_price'] ), Decimal::parse( '50.00' ), 'parent lookup min refreshed after Undo' );
eq( Decimal::parse( $lookup['max_price'] ), Decimal::parse( '100.00' ), 'parent lookup max refreshed after Undo' );

// One conflicted variation never fails an unrelated sibling: the external edit
// conflicts on its own row while the explicitly selected sibling applies and
// the parent range still refreshes from the visible child prices.
$vpreview2 = Admin::process_preview( preview_post( array( 'ids' => $v_blue . ',' . $v_red, 'amount' => '30.00' ) ), 'POST' );
eq( $vpreview2['status'], 'OK', 'second variable preview created from explicit variation IDs' );
$vjob2 = Repo::read_by_public_id( $vpreview2['public_id'] );
eq( Repo::hydrate_plan( $vjob2 )->data()['resolved_product_ids'], array( $v_blue, $v_red ), 'explicit variation IDs stay the frozen pair' );
$vapprove2 = Admin::process_approve( approve_post( $vjob2 ), 'POST' );
eq( $vapprove2['status'], 'OK', 'second variation plan approves' );
$vred_product = wc_get_product( $v_red );
$vred_product->set_regular_price( '60.00' );
$vred_product->save();
run_job_terminal( (int) $vjob2['id'] );
eq( fresh_price( $v_blue ), '30.00', 'unrelated variation still applies' );
eq( fresh_price( $v_red ), '60.00', 'externally edited variation keeps its newer value' );
eq( fresh_price( $v_green ), '60.00', 'variation outside the frozen pair stays untouched' );
$vjob2_counts = Repo::counts( (int) $vjob2['id'] );
eq( $vjob2_counts['applied'], 1, 'exactly one sibling applied' );
eq( $vjob2_counts['conflict'], 1, 'exactly one sibling conflicted' );
$lookup = $parent_lookup179( $var_parent_id );
eq( Decimal::parse( $lookup['min_price'] ), Decimal::parse( '30.00' ), 'parent lookup min refreshed with a conflicted sibling' );
eq( Decimal::parse( $lookup['max_price'] ), Decimal::parse( '60.00' ), 'parent lookup max refreshed with a conflicted sibling' );
marker( 'variable products: expansion, variation Apply/Undo and parent range refresh' );

require __DIR__ . '/selection-integration.php';
require __DIR__ . '/presentation-integration.php';
require __DIR__ . '/polish-integration.php';
require __DIR__ . '/sale-operations.php';
require __DIR__ . '/preview-csv-integration.php';

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
