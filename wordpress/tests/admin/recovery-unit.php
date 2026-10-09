<?php
// Focused hash/provenance compatibility test on the actual immutable plan contract.
require __DIR__ . '/../free/unit.php';
require __DIR__ . '/../../writeleash/includes/free/class-job-repository.php';
$source = '12345678-1234-1234-1234-123456789abc';
$old = $thousand_plan->json();
$fresh = $thousand_plan->with_source_job( $source );
wl107_equal( $thousand_plan->json(), $old, 'provenance never changes input plan' );
wl107_equal( $fresh->hash() !== $thousand_plan->hash(), true, 'provenance hash bound' );
wl107_equal( WriteLeash\Change_Plan::hydrate( $fresh->data() )->data()['source_job'], $source, 'new plan survives trusted hydration' );
wl107_equal( WriteLeash\Change_Plan::hydrate( $thousand_plan->data() )->hash(), $thousand_plan->hash(), 'old hash unchanged' );
wl107_error( static fn() => $thousand_plan->with_source_job( 'forged' ), 'invalid_source_job' );
wl107_error( static fn() => $fresh->with_source_job( $source ), 'invalid_source_job' );
$d = $fresh->data(); $d['source_job'] = 'abcdefab-1234-1234-1234-123456789abc';
wl107_error( static fn() => WriteLeash\Change_Plan::hydrate( $d ), 'plan_hash_mismatch' );
foreach ( array( null, array(), 'forged', str_repeat( 'a', 1000 ) ) as $bad ) {
 $d = $fresh->data(); $d['source_job'] = $bad;
 wl107_error( static fn() => WriteLeash\Change_Plan::hydrate( $d ), 'invalid_source_job' );
}
wl107_marker( '#205 provenance immutable, bounded, tamper-resistant and old-plan compatible' );
