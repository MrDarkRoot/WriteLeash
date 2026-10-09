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
// Genuine pre-#210 serialized plans captured from fd7a0dc's unchanged #178/#179
// harnesses (simple sale and regular variation), not recreated with localized code.
foreach ( array( 'simple', 'variation' ) as $kind ) {
	$raw = trim( file_get_contents( __DIR__ . '/i18n-baseline-' . $kind . '.json' ) );
	$data = json_decode( $raw, true );
	$legacy = WriteLeash\Change_Plan::hydrate( $data );
	wl179_equal( $legacy->json(), $raw, 'baseline plan bytes preserved under translation: ' . $kind );
	wl179_equal( $legacy->hash(), $data['plan_hash'], 'baseline plan hash preserved: ' . $kind );
	wl179_equal( $legacy->data()['resolved_product_ids'], $data['resolved_product_ids'], 'baseline selection IDs preserved: ' . $kind );
	foreach ( $legacy->data()['items'] as $i => $item ) { wl179_equal( $item['planned_regular_price'], $data['items'][$i]['planned_regular_price'], 'baseline canonical price preserved' ); }
}
unset( $GLOBALS['wl210_prefix'] );
echo "#210 localized plan compatibility and canonical invariance PASS\n";
