<?php
require __DIR__ . '/i18n-stubs.php';
require __DIR__ . '/../free/variations.php';
$existing = WriteLeash\Change_Plan::hydrate( json_decode( $variation_plan->json(), true ) );
$english = array( $existing->json(), $existing->hash(), WriteLeash\Undo_Fingerprint::capture( $facts ), $existing->data()['selection'], $existing->data()['operation'] );
$GLOBALS['wl210_prefix'] = '[Ü] ';
$copy = WriteLeash\Change_Plan::hydrate( json_decode( $variation_plan->json(), true ) );
wl179_equal( array( $copy->json(), $copy->hash(), WriteLeash\Undo_Fingerprint::capture( $facts ), $copy->data()['selection'], $copy->data()['operation'] ), $english, 'locale leaves pre-existing variation plan, hash, selector, operation and Undo evidence byte-identical' );
wl179_equal( WriteLeash\Price_Operation::label( 'sale_price' ), '[Ü] Sale price', 'effective translated field display' );
wl179_equal( $copy->item( 11 )->data()['planned_regular_price'], $variation_plan->item( 11 )->data()['planned_regular_price'], 'canonical price unchanged' );
unset( $GLOBALS['wl210_prefix'] );
echo "#210 localized plan compatibility and canonical invariance PASS\n";
