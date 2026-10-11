<?php
require __DIR__ . '/selection-count.php';
require __DIR__ . '/../../writeleash/includes/free/class-job-repository.php';
function wp_generate_uuid4() { static $n = 0; return sprintf( '00000000-0000-4000-8000-%012d', ++$n ); }
use WriteLeash\Selection_Refinement as R231;
use WriteLeash\Woo_Price_Planner as P231;
use WriteLeash\Price_Selection_Spec as S231;
$GLOBALS['terms206'] = array( 1 => 0, 2 => 1, 3 => 2 );
$GLOBALS['products206'] = array( 1 => new WC_Product_Simple( 1, array( 'categories' => array( 1 ) ) ), 2 => new WC_Product_Simple( 2, array( 'categories' => array( 2 ) ) ), 3 => new WC_Product_Simple( 3, array( 'categories' => array( 3 ) ) ) );
foreach ( array( false, true ) as $descendants231 ) {
	$selection231 = S231::category( 1, $descendants231 );
	$plan231 = P231::preview( $selection231, new WriteLeash\Price_Operation( 'SET', '80' ), new WriteLeash\Safety_Policy( 1000, '100', '100', false, '100' ) );
	$final231 = R231::preview( $plan231, $descendants231 ? array( 2 ) : array(), '00000000-0000-4000-8000-000000000231' );
	eq206( $final231->data()['resolved_product_ids'], $descendants231 ? array( 1, 3 ) : array( 1 ), '#231 category with and without descendants' );
	eq206( $final231->data()['selection_refinement']['source_selection']['include_children'], $descendants231, '#231 honest persisted source scope' );
}
echo "#231 category exclusions: PASS\n";
