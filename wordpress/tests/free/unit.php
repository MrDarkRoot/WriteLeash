<?php
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/assertions.php';
require __DIR__ . '/../../writeleash/includes/free/class-price-decimal.php';
require __DIR__ . '/../../writeleash/includes/free/class-price-operation.php';
require __DIR__ . '/../../writeleash/includes/free/class-safety-policy.php';
require __DIR__ . '/../../writeleash/includes/free/class-product-snapshot.php';
require __DIR__ . '/../../writeleash/includes/free/class-product-selector.php';
require __DIR__ . '/../../writeleash/includes/free/class-change-plan.php';
use WriteLeash\Price_Decimal as D;
use WriteLeash\Price_Operation as O;
use WriteLeash\Price_Calculator as C;
use WriteLeash\Safety_Policy as P;
use WriteLeash\Policy_Evaluator as E;
$wl107_assertions = 0;

foreach ( array( O::SET => array( '80', '80' ), O::INCREASE_FIXED => array( '5', '105' ), O::DECREASE_FIXED => array( '5', '95' ), O::INCREASE_PERCENT => array( '10', '110' ), O::DECREASE_PERCENT => array( '20', '80' ) ) as $type => $case ) {
	foreach ( array( 0, 1, 2, 3, 6 ) as $dp ) {
		wl107_equal( C::calculate( '100.00', new O( $type, $case[0] ), $dp ), $case[1] . ( $dp ? '.' . str_repeat( '0', $dp ) : '' ), "$type precision $dp" );
	}
}
wl107_marker( 'five operations x 0/1/2/3/6 decimals' );
$cases = array(
	array( '1.5', O::SET, '1.5', 0, '2' ), array( '1.25', O::SET, '1.25', 1, '1.3' ),
	array( '1.005', O::SET, '1.005', 2, '1.01' ), array( '1.004999', O::SET, '1.004999', 2, '1.00' ),
	array( '0.000001', O::SET, '0.000001', 6, '0.000001' ), array( '0.000001', O::SET, '0.000001', 2, '0.00' ),
	array( '0.01', O::INCREASE_PERCENT, '50', 2, '0.02' ), array( '0.01', O::DECREASE_PERCENT, '50', 2, '0.01' ),
	array( '0.01', O::DECREASE_FIXED, '0.005', 2, '0.01' ), array( '0.01', O::INCREASE_FIXED, '0.004999', 2, '0.01' ),
	array( '0.001', O::INCREASE_PERCENT, '50', 3, '0.002' ), array( '999999999999', O::SET, '999999999999.999999', 6, '999999999999.999999' ),
	array( '123456789012.123456', O::INCREASE_PERCENT, '0.000001', 6, '123456790246.691346' ),
	array( '0', O::SET, '0', 2, '0.00' ), array( '0', O::INCREASE_FIXED, '1', 2, '1.00' ),
	array( '1', O::DECREASE_FIXED, '1', 2, '0.00' ), array( '1', O::DECREASE_PERCENT, '100', 2, '0.00' ),
);
foreach ( $cases as $case ) { wl107_equal( C::calculate( $case[0], new O( $case[1], $case[2] ), $case[3] ), $case[4], 'rounding/large/zero' ); }
wl107_error( static fn() => C::calculate( '', new O( O::SET, '0' ), 2 ), 'empty_regular_price' );
wl107_error( static fn() => C::calculate( 1.5, new O( O::SET, '1' ), 2 ), 'malformed_decimal' );
wl107_error( static fn() => C::delta( '1', 1.5 ), 'malformed_decimal' );
foreach ( array( O::INCREASE_PERCENT, O::DECREASE_PERCENT ) as $type ) { wl107_error( static fn() => C::calculate( '0', new O( $type, '10' ), 2 ), 'percent_from_zero_undefined' ); }
wl107_error( static fn() => C::calculate( '0.01', new O( O::DECREASE_FIXED, '0.010001' ), 2 ), 'negative_target' );
wl107_error( static fn() => C::calculate( '1', new O( O::DECREASE_PERCENT, '100.000001' ), 2 ), 'negative_target' );
wl107_error( static fn() => C::calculate( '999999999999.999999', new O( O::SET, '999999999999.999999' ), 2 ), 'price_overflow' );
wl107_error( static fn() => C::calculate( '999999999999', new O( O::INCREASE_FIXED, '1' ), 2 ), 'price_overflow' );
wl107_error( static fn() => C::calculate( '999999999999', new O( O::INCREASE_PERCENT, '100' ), 2 ), 'price_overflow' );
foreach ( array( -1, 7 ) as $dp ) { wl107_error( static fn() => C::calculate( '1', new O( O::SET, '1' ), $dp ), 'unsupported_price_decimals' ); }
wl107_marker( 'rounding ties, fractional cents, tiny/large values, empty/zero/negative/overflow' );
foreach ( array( '', '1,234.56', '1.234,56', '1 234,56', '12,5%', '$10', ' 10', '10 ', "10\n", '+10', '-1', '.5', '1.', '01', '1e2', 'NaN', 'INF', '١', 10, 1.5, null, array() ) as $input ) { wl107_error( static fn() => D::parse( $input ), 'malformed_decimal' ); }
wl107_error( static fn() => D::parse( '1.0000000' ), 'input_precision_exceeded' );
wl107_error( static fn() => D::parse( '1000000000000' ), 'price_overflow' );
wl107_equal( D::parse( '10.000000' ), '10', 'canonical insignificant zeros' );
wl107_equal( D::parse( '0.000000' ), '0', 'canonical numeric zero' );
wl107_error( static fn() => new O( 'OTHER', '1' ), 'unsupported_operation' );
wl107_marker( 'strict parser rejects locale, whitespace, symbols, floats and excess precision' );
$policy = new P( 50, '20', '20', true, '10' );
foreach ( array( array( '120', 'ALLOW_WITH_WARNINGS' ), array( '120.000001', 'BLOCKED' ), array( '80', 'ALLOW_WITH_WARNINGS' ), array( '79.999999', 'BLOCKED' ), array( '100', 'ALLOW' ) ) as $case ) {
	wl107_equal( E::item( '100', $case[0], $policy )->data()['state'], $case[1], 'exact policy boundary' );
}
wl107_equal( E::item( '100', '79.99', $policy )->data()['blockers'], array( 'max_decrease_exceeded' ), '20.01 percent' );
wl107_equal( E::item( '100', '0.00', $policy )->data()['blockers'], array( 'zero_target_blocked', 'max_decrease_exceeded' ), 'zero blocks plus cap' );
wl107_equal( E::item( '0', '0.00', $policy )->data()['state'], 'ALLOW', 'unchanged zero does not block' );
wl107_equal( E::item( '0', '1', $policy )->data()['blockers'], array( 'percentage_limit_from_zero_undefined' ), 'no cap bypass from zero' );
wl107_equal( E::item( '1', '0', new P( 1, '100', '100', false, '100' ) )->data()['state'], 'ALLOW', 'zero allowed explicitly and cap inclusive' );
wl107_equal( E::item( '100', '110', $policy )->data()['warnings'], array(), 'warning exactly threshold allowed' );
wl107_equal( C::delta( '3', '4' )['percentage_delta']['display'], '33.333333', 'rational display' );
wl107_equal( C::delta( '0', '1' )['percentage_delta'], null, 'undefined zero delta ratio' );
wl107_equal( E::item( '3', '4', new P( 1, '33.333333', '100', false, '100' ) )->data()['state'], 'BLOCKED', 'rounded display never used for cap' );
wl107_marker( 'policy boundaries, minimal unit over, warning semantics and exact rational comparisons' );

// Exhaust integer primitives against a safe, small integer oracle; catches carries/borrows.
for ( $i = 0; $i <= 120; $i += 3 ) {
	for ( $j = 1; $j <= 100; $j += 7 ) {
		wl107_equal( D::add( (string) $i, (string) $j ), (string) ( $i + $j ), 'digit addition' );
		wl107_equal( D::multiply( (string) $i, (string) $j ), (string) ( $i * $j ), 'digit multiplication' );
		wl107_equal( D::divide_round( (string) $i, (string) $j ), (string) intdiv( 2 * $i + $j, 2 * $j ), 'integer rounding oracle' );
		wl107_equal( D::subtract( (string) max( $i, $j ), (string) min( $i, $j ) ), (string) abs( $i - $j ), 'digit subtraction' );
	}
}
wl107_marker( 'string arithmetic integer-oracle matrix; no platform float expectations' );
wl107_equal( \WriteLeash\Plan_Hasher::hash( array( 'b' => array( 'z' => '2', 'a' => '1' ), 'a' => '0' ) ), \WriteLeash\Plan_Hasher::hash( array( 'a' => '0', 'b' => array( 'a' => '1', 'z' => '2' ) ) ), 'canonical map ordering' );
wl107_error( static fn() => \WriteLeash\Plan_Hasher::canonical_json( array( 'float' => 1.5 ) ), 'noncanonical_plan_value' );
wl107_error( static fn() => \WriteLeash\Plan_Hasher::canonical_json( array( 'bad_utf8' => "\xff" ) ), 'invalid_plan_encoding' );
wl107_error( static fn() => \WriteLeash\Price_Selection_Spec::ids( array_fill( 0, 1001, 1 ) ), 'invalid_selection_size' );
foreach ( array( '', '*', 'A B', '<bad>', "A\n" ) as $sku ) { wl107_error( static fn() => \WriteLeash\Price_Selection_Spec::sku( $sku ), 'invalid_sku' ); }
wl107_marker( 'canonical hash ordering, invalid encoding, bounded selector input' );
require __DIR__ . '/dirty-catalog-stubs.php';
