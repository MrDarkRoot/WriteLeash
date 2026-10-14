<?php
// #281 merchant outcome copy. Presentation only: public item_label / result_summary stay put.
require __DIR__ . '/progress-unit.php';

$outcome = new ReflectionClass( WriteLeash\Free_Admin::class );
$line = $outcome->getMethod( 'merchant_item_line' );
$line->setAccessible( true );
$summary = $outcome->getMethod( 'merchant_outcome_summary' );
$summary->setAccessible( true );
$next = $outcome->getMethod( 'merchant_next_step' );
$next->setAccessible( true );
$cell = $outcome->getMethod( 'render_item_outcome' );
$cell->setAccessible( true );

$expect = array(
	'APPLIED' => 'Changed',
	'UNCHANGED' => 'Not changed',
	'UNSUPPORTED' => 'Skipped',
	'CONFLICT' => 'Newer price kept',
	'FAILED' => 'Not changed; needs attention',
	'NEEDS_REVIEW' => 'Needs review; outcome uncertain',
	'UNDONE' => 'Restored',
	'UNDO_CONFLICT' => 'Not restored; newer price kept',
	'UNDO_FAILED' => 'Not restored; needs attention',
	'UNDO_NEEDS_REVIEW' => 'Needs review; restoration uncertain',
);
foreach ( $expect as $state => $copy ) {
	check204( $line->invoke( null, $state, 'COMPLETED_WITH_ISSUES' ), $copy, 'merchant line ' . $state );
	check204( preg_match( '/rolled back|full rollback|accessibility certified/i', $copy ), 0, 'merchant line stays bounded: ' . $state );
}
check204( WriteLeash\Free_Admin::item_label( 'FAILED', 'COMPLETED_WITH_ISSUES' ), 'Needs attention; not changed', 'item_label contract unchanged' );
check204( WriteLeash\Free_Admin::item_label( 'UNSUPPORTED', 'PLANNED' ), 'Skipped at preview', 'item_label skip contract unchanged' );
check204( WriteLeash\Free_Admin::item_label( 'CONFLICT', 'COMPLETED_WITH_ISSUES' ), 'Not changed', 'item_label conflict contract unchanged' );
check204( str_contains( WriteLeash\Free_Admin::result_summary( array( 'planned' => 4, 'pending' => 0, 'applying' => 0, 'applied' => 1, 'unchanged' => 1, 'conflict' => 2, 'failed' => 0, 'needs_review' => 0, 'unsupported' => 1 ), 'COMPLETED_WITH_ISSUES', true ), '2 conflicts' ), true, 'result_summary contract unchanged' );

$counts = array( 'planned' => 6, 'pending' => 1, 'applying' => 1, 'applied' => 1, 'unchanged' => 1, 'conflict' => 1, 'failed' => 1, 'needs_review' => 1, 'unsupported' => 1 );
check204( $summary->invoke( null, $counts, 'RUNNING' ), 'Outcomes: 1 changed · 1 not changed · 1 skipped · 1 newer price kept · 1 not changed; needs attention · 1 needs review; outcome uncertain · 1 in progress; outcome not yet confirmed · 1 remaining.', 'partial summary uses persisted counts' );
check204( $summary->invoke( null, $counts, 'COMPLETED_WITH_ISSUES' ), 'Outcomes: 1 changed · 1 not changed · 1 skipped · 1 newer price kept · 1 not changed; needs attention · 1 needs review; outcome uncertain · 1 in progress; outcome not yet confirmed · 1 not processed.', 'terminal pending is not processed' );
check204( $summary->invoke( null, array( 'planned' => 2, 'pending' => 2, 'applied' => 0, 'unchanged' => 0, 'unsupported' => 0, 'conflict' => 0, 'failed' => 0, 'needs_review' => 0 ), 'PLANNED' ), '', 'planned pending is not invented as an outcome' );
check204( str_contains( $summary->invoke( null, $counts, 'RUNNING' ), '%' ), false, 'merchant summary does not infer a percent' );

check204( $next->invoke( null, true, true, true ), 'Next: use Resume remaining products. Completed changes are kept.', 'one next step prefers the existing Resume control' );
check204( $next->invoke( null, false, true, true ), 'Next: use Re-preview conflicted products to build a fresh preview. Prices do not change until you approve that preview.', 'conflict next step is a fresh preview' );
check204( $next->invoke( null, false, false, true ), 'Next: Undo restores eligible prices only. It does not reverse orders, sales, emails, webhooks, or other plugins.', 'Undo next step is not a full rollback' );
check204( $next->invoke( null, false, false, false ), 'Next: review the product.', 'default next step is inspect the product' );

ob_start();
$cell->invoke( null, 'FAILED', null, 'COMPLETED_WITH_ISSUES', array( 'context' => '' ) );
$failed_html = (string) ob_get_clean();
check204( str_contains( $failed_html, '<strong>Not changed; needs attention</strong>' ), true, 'failed cell headline' );
check204( str_contains( $failed_html, 'Review the product. This outcome is not confirmed as unchanged.' ), true, 'failed cell one next step' );
check204( str_contains( $failed_html, 'FAILED ·' ), true, 'typed failed state stays in Details' );
check204( str_contains( $failed_html, 'rolled back' ), false, 'failed cell does not claim a rollback' );
check204( str_contains( $failed_html, 'name="action"' ), false, 'outcome cell adds no form' );

ob_start();
$cell->invoke( null, 'CONFLICT', null, 'COMPLETED_WITH_ISSUES', array( 'context' => '' ) );
$conflict_html = (string) ob_get_clean();
check204( str_contains( $conflict_html, '<strong>Newer price kept</strong>' ), true, 'conflict cell headline' );
check204( str_contains( $conflict_html, 'Create a new preview' ), true, 'conflict cell keeps the existing preview link' );
check204( substr_count( $conflict_html, '<a ' ), 1, 'conflict cell has one next-step link' );
check204( str_contains( $conflict_html, 'CONFLICT ·' ), true, 'typed conflict state stays in Details' );

$admin_source = (string) file_get_contents( __DIR__ . '/../../writeleash/includes/free/class-free-admin.php' );
check204( str_contains( $admin_source, 'writeleash_free_resume' ), true, 'no new resume action; existing hook remains' );
check204( substr_count( $admin_source, 'function render_item_outcome' ), 1, 'item outcome renderer stays singular' );
echo "#281 merchant outcome copy: item lines, persisted counts, one existing next step, Details retained PASS\n";
