<?php
// #109 worker-path audit: the execution path may not re-plan or re-select.
// Only trusted persisted/frozen item material and the #108 primitive execute.
$root = $argv[1] ?? __DIR__ . '/../../writeleash';
$files = array(
	'includes/free/class-job-transaction-fence.php',
	'includes/free/class-job-worker.php',
	'includes/free/class-job-repository.php',
	'includes/free/class-job-schema.php',
	'includes/free/class-job-scheduler.php',
	'includes/free/class-job-resume-rest.php',
	// #110 Undo path: same no-replan rule plus no direct Woo price SQL.
	'includes/free/class-undo-transaction-fence.php',
	'includes/free/class-undo-worker.php',
	'includes/free/class-undo-repository.php',
	'includes/free/class-undo-schema.php',
	'includes/free/class-undo-scheduler.php',
	'includes/free/class-undo-rest.php',
	'includes/free/class-undo-fingerprint.php',
	'includes/free/class-woo-undo-mutator.php',
);
$forbidden = array(
	'Woo_Price_Planner',
	'Product_Price_Selector',
	'Price_Selection_Spec',
	'Price_Calculator',
	// Field identity constants/helpers (regular_price vs sale_price) are frozen
	// plan vocabulary, not planning: forbid construction and operation payload
	// reads instead of the class name.
	'new Price_Operation',
	'Price_Operation::data',
	'Price_Operation::calculate',
	'Price_Store_Context::current',
	'WP_Query',
	'wc_get_products',
	'get_terms',
);
// Undo/apply workers restore only through Woo CRUD: direct Woo metadata or
// post writes are forbidden everywhere on these paths. Meta-key *reads* for
// verification remain allowed; these tokens only match write primitives.
$no_direct_price_sql = array(
	'update_post_meta',
	'delete_post_meta',
	'add_post_meta',
	'update_metadata',
	'delete_metadata',
	'add_metadata',
	'wp_update_post',
	'wp_insert_post',
	'wp_delete_post',
);
$worker = '';
$repository = '';
$undo_worker = '';
foreach ( $files as $relative ) {
	$source = file_get_contents( $root . '/' . $relative );
	if ( ! is_string( $source ) ) { throw new RuntimeException( 'audit target missing: ' . $relative ); }
	foreach ( $forbidden as $token ) {
		if ( str_contains( $source, $token ) ) { throw new RuntimeException( 'worker path re-plans with ' . $token . ' in ' . $relative ); }
	}
	if ( str_starts_with( $relative, 'includes/free/class-undo-' ) || str_contains( $relative, 'class-woo-undo-mutator.php' ) ) {
		foreach ( $no_direct_price_sql as $token ) {
			if ( str_contains( $source, $token ) ) { throw new RuntimeException( 'undo path writes Woo price storage directly with ' . $token . ' in ' . $relative ); }
		}
	}
	if ( false !== strpos( $relative, 'class-job-worker.php' ) ) { $worker = $source; }
	if ( false !== strpos( $relative, 'class-job-repository.php' ) ) { $repository = $source; }
	if ( false !== strpos( $relative, 'class-undo-worker.php' ) ) { $undo_worker = $source; }
}
if ( ! str_contains( $worker, 'Woo_Price_Mutator::apply' ) ) { throw new RuntimeException( 'worker does not use the #108 primitive' ); }
if ( ! str_contains( $worker, '$plan->data()' ) || ! str_contains( $repository, 'Change_Plan::hydrate' ) || ! str_contains( $repository, 'assert_item_material' ) ) {
	throw new RuntimeException( 'worker path does not consume hydrated frozen material' );
}
if ( ! str_contains( $undo_worker, 'Woo_Undo_Mutator::restore' ) ) { throw new RuntimeException( 'undo worker does not use the #110 restore primitive' ); }
if ( ! str_contains( $undo_worker, 'Undo_Transaction_Fence' ) ) { throw new RuntimeException( 'undo worker does not fence the restore transaction' ); }
echo "#109 no-replan audit: worker/repository/schema/scheduler contain no selector, planner, arithmetic or catalog-query call; execution uses hydrated #107 material and #108 Woo_Price_Mutator::apply PASS\n";
echo "#110 no-replan audit: undo worker/repository/schema/scheduler contain no selector, planner, arithmetic, catalog-query or direct Woo price SQL; restore uses hydrated #107 material, Woo_Undo_Mutator::restore and the Undo fence PASS\n";
