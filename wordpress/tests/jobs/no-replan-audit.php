<?php
// #109 worker-path audit: the execution path may not re-plan or re-select.
// Only trusted persisted/frozen item material and the #108 primitive execute.
$root = $argv[1] ?? __DIR__ . '/../../writeleash';
$files = array(
	'includes/free/class-job-worker.php',
	'includes/free/class-job-repository.php',
	'includes/free/class-job-schema.php',
	'includes/free/class-job-scheduler.php',
	'includes/free/class-job-resume-rest.php',
);
$forbidden = array(
	'Woo_Price_Planner',
	'Product_Price_Selector',
	'Price_Selection_Spec',
	'Price_Calculator',
	'Price_Operation',
	'Price_Store_Context::current',
	'WP_Query',
	'wc_get_products',
	'get_terms',
);
$worker = '';
$repository = '';
foreach ( $files as $relative ) {
	$source = file_get_contents( $root . '/' . $relative );
	if ( ! is_string( $source ) ) { throw new RuntimeException( 'audit target missing: ' . $relative ); }
	foreach ( $forbidden as $token ) {
		if ( str_contains( $source, $token ) ) { throw new RuntimeException( 'worker path re-plans with ' . $token . ' in ' . $relative ); }
	}
	if ( false !== strpos( $relative, 'class-job-worker.php' ) ) { $worker = $source; }
	if ( false !== strpos( $relative, 'class-job-repository.php' ) ) { $repository = $source; }
}
if ( ! str_contains( $worker, 'Woo_Price_Mutator::apply' ) ) { throw new RuntimeException( 'worker does not use the #108 primitive' ); }
if ( ! str_contains( $worker, '$plan->data()' ) || ! str_contains( $repository, 'Change_Plan::hydrate' ) || ! str_contains( $repository, 'assert_item_material' ) ) {
	throw new RuntimeException( 'worker path does not consume hydrated frozen material' );
}
echo "#109 no-replan audit: worker/repository/schema/scheduler contain no selector, planner, arithmetic or catalog-query call; execution uses hydrated #107 material and #108 Woo_Price_Mutator::apply PASS\n";
