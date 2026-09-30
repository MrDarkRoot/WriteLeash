<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

final class Price_Operation {
	use Immutable_Price_Value;
	public const SET = 'SET';
	public const INCREASE_FIXED = 'INCREASE_FIXED';
	public const DECREASE_FIXED = 'DECREASE_FIXED';
	public const INCREASE_PERCENT = 'INCREASE_PERCENT';
	public const DECREASE_PERCENT = 'DECREASE_PERCENT';
	private string $type;
	private string $input;
	public function __construct( string $type, $input ) {
		if ( ! in_array( $type, array( self::SET, self::INCREASE_FIXED, self::DECREASE_FIXED, self::INCREASE_PERCENT, self::DECREASE_PERCENT ), true ) ) {
			throw new Price_Validation_Error( 'unsupported_operation' );
		}
		$this->type = $type;
		$this->input = Price_Decimal::parse( $input );
	}
	public function data(): array { return array( 'type' => $this->type, 'input' => $this->input ); }
}

final class Price_Calculator {
	public static function calculate( $old, Price_Operation $operation, int $decimals ): string {
		Price_Decimal::decimals( $decimals );
		if ( '' === $old ) { throw new Price_Validation_Error( 'empty_regular_price' ); }
		$old = Price_Decimal::units( Price_Decimal::parse( $old ) );
		$op = $operation->data();
		$input = Price_Decimal::units( $op['input'] );
		$scale = Price_Decimal::SCALE;
		switch ( $op['type'] ) {
			case Price_Operation::SET: $target = $input; break;
			case Price_Operation::INCREASE_FIXED: $target = Price_Decimal::add( $old, $input ); break;
			case Price_Operation::DECREASE_FIXED: $target = Price_Decimal::subtract( $old, $input ); break;
			default:
				if ( '0' === $old ) { throw new Price_Validation_Error( 'percent_from_zero_undefined' ); }
				// old has scale 6; percent has scale 6 + division by 100: scale 14.
				$hundred = '100000000';
				$factor = Price_Operation::INCREASE_PERCENT === $op['type'] ? Price_Decimal::add( $hundred, $input ) : Price_Decimal::subtract( $hundred, $input );
				$target = Price_Decimal::multiply( $old, $factor );
				$scale = 14;
		}
		return Price_Decimal::target( $target, $scale, $decimals );
	}

	/** Exact signed delta and exact percentage ratio, with separately rounded display. */
	public static function delta( $expected, $target ): array {
		$old = Price_Decimal::units( Price_Decimal::parse( $expected ) );
		$new = Price_Decimal::units( Price_Decimal::parse( $target ) );
		$direction = Price_Decimal::compare( $new, $old );
		$delta = $direction >= 0 ? Price_Decimal::subtract( $new, $old ) : Price_Decimal::subtract( $old, $new );
		$sign = $direction < 0 ? '-' : '';
		$percent = '0' === $old ? null : array(
			'numerator' => $sign . Price_Decimal::multiply( $delta, '100' ),
			'denominator' => $old,
			'display' => $sign . Price_Decimal::format( Price_Decimal::divide_round( Price_Decimal::multiply( $delta, '100000000' ), $old ), 6 ),
		);
		return array( 'absolute_delta' => $sign . Price_Decimal::format( $delta, 6, true ), 'percentage_delta' => $percent );
	}
}
