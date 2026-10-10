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
	public const CLEAR_SALE = 'CLEAR_SALE';
	public const SALE_DISCOUNT_PERCENT = 'SALE_DISCOUNT_PERCENT';
	public const FIELD_REGULAR = 'regular_price';
	public const FIELD_SALE = 'sale_price';
	/** The two first-class price fields a plan may target. */
	public const FIELDS = array( self::FIELD_REGULAR, self::FIELD_SALE );
	private string $type;
	private string $input;
	private string $field;
	public function __construct( string $type, $input, string $field = self::FIELD_REGULAR ) {
		if ( ! in_array( $type, array( self::SET, self::INCREASE_FIXED, self::DECREASE_FIXED, self::INCREASE_PERCENT, self::DECREASE_PERCENT, self::CLEAR_SALE, self::SALE_DISCOUNT_PERCENT ), true ) ) {
			throw new Price_Validation_Error( 'unsupported_operation' );
		}
		if ( ! in_array( $field, self::FIELDS, true ) ) {
			throw new Price_Validation_Error( 'unsupported_price_field' );
		}
		$this->type = $type;
		if ( in_array( $type, array( self::CLEAR_SALE, self::SALE_DISCOUNT_PERCENT ), true ) && self::FIELD_SALE !== $field ) { throw new Price_Validation_Error( 'sale_operation_requires_sale_field' ); }
		if ( self::CLEAR_SALE === $type ) {
			if ( '' !== $input ) { throw new Price_Validation_Error( 'clear_sale_requires_empty_input' ); }
			$this->input = '';
		} else {
			$this->input = Price_Decimal::parse( $input );
			if ( self::SALE_DISCOUNT_PERCENT === $type && Price_Decimal::compare( Price_Decimal::units( $this->input ), '100000000' ) > 0 ) { throw new Price_Validation_Error( 'sale_discount_out_of_range' ); }
		}
		$this->field = $field;
	}
	public function data(): array { return array( 'type' => $this->type, 'input' => $this->input, 'field' => $this->field ); }
	/** Snapshot/item meta key for a price field. */
	public static function meta_key( string $field ): string { return self::FIELD_SALE === $field ? 'sale_price' : 'regular_price'; }
	/** Fail-closed field validation for trusted persistence boundaries. */
	public static function assert_field( $field ): string {
		if ( ! in_array( $field, self::FIELDS, true ) ) { throw new Price_Validation_Error( 'unsupported_price_field' ); }
		return $field;
	}
	/** Merchant-facing field label; display only. */
	public static function label( string $field ): string { return self::FIELD_SALE === $field ? __( 'Sale price', 'writeleash' ) : __( 'Regular price', 'writeleash' ); }
}

final class Price_Calculator {
	public static function calculate( $old, Price_Operation $operation, int $decimals, ?string $regular_basis = null ): string {
		Price_Decimal::decimals( $decimals );
		$op = $operation->data();
		if ( Price_Operation::CLEAR_SALE === $op['type'] ) { return ''; }
		if ( Price_Operation::SALE_DISCOUNT_PERCENT === $op['type'] ) {
			if ( null === $regular_basis || '' === $regular_basis ) { throw new Price_Validation_Error( 'empty_regular_price' ); }
			$basis = Price_Decimal::units( Price_Decimal::parse( $regular_basis ) );
			$factor = Price_Decimal::subtract( '100000000', Price_Decimal::units( $op['input'] ) );
			return Price_Decimal::target( Price_Decimal::multiply( $basis, $factor ), 14, $decimals );
		}
		if ( '' === $old ) {
			// No stored baseline: only an absolute SET has a defined target.
			if ( Price_Operation::SET !== $op['type'] ) { throw new Price_Validation_Error( 'empty_sale_price' ); }
			return Price_Decimal::target( Price_Decimal::units( $op['input'] ), Price_Decimal::SCALE, $decimals );
		}
		$old = Price_Decimal::units( Price_Decimal::parse( $old ) );
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
