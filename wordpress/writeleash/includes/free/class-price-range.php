<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * #234 optional inclusive price-range selection filter.
 *
 * A selection filter, not a mutation operation. When enabled, only final
 * targets whose own selected-field snapshot price falls within the inclusive
 * bounds (`min <= price <= max`) enter the frozen Preview. Bounds are
 * independently optional; an enabled filter requires at least one bound and
 * refuses `min > max`.
 *
 * Semantics:
 * - The range basis (Regular Price or Sale Price) is explicit and
 *   independent of the operation's target field. A blank basis price (in
 *   particular a blank Sale Price) never matches an enabled range.
 * - Zero is a valid numeric bound, not an empty input.
 * - Bounds and comparisons use exact Price_Decimal parsing and units;
 *   PHP floats are never involved.
 * - Matching reads only fresh snapshot rows; it never mutates product data
 *   and never replaces snapshot validation (DB price sorting/lookup hints
 *   stay optimizations, never approval evidence).
 */
final class Price_Range_Filter {
	use Immutable_Price_Value;
	private array $values;
	private function __construct( array $values ) { $this->values = $values; }

	/** No filtering; every readable snapshot matches. */
	public static function disabled(): self {
		return new self( array( 'enabled' => false, 'min' => null, 'max' => null, 'basis' => Price_Operation::FIELD_REGULAR ) );
	}

	/**
	 * Strict typed factory for merchant input.
	 *
	 * `$enabled_raw` enables only on the exact checkbox value `'1'`; `null`
	 * or `''` (unchecked/absent control) disables. Any other shape is a
	 * malformed request. When disabled, every other input is ignored so a
	 * stale or forged bound can never narrow a population silently.
	 *
	 * `$min_raw` / `$max_raw` accept `null` or `''` as absent, otherwise the
	 * strict unsigned decimal grammar (`Price_Decimal::parse`). An empty
	 * basis falls back to `$default_basis` (the caller's target field).
	 */
	public static function from_inputs( $enabled_raw, $min_raw, $max_raw, $basis_raw, string $default_basis = Price_Operation::FIELD_REGULAR ): self {
		if ( null === $enabled_raw || '' === $enabled_raw || false === $enabled_raw ) { return self::disabled(); }
		if ( true === $enabled_raw || '1' === $enabled_raw ) {
			if ( ! in_array( $default_basis, Price_Operation::FIELDS, true ) ) { throw new Price_Validation_Error( 'invalid_price_range' ); }
			$basis = ( null === $basis_raw || '' === $basis_raw ) ? $default_basis : $basis_raw;
			if ( ! is_string( $basis ) || ! in_array( $basis, Price_Operation::FIELDS, true ) ) { throw new Price_Validation_Error( 'invalid_price_range' ); }
			$min = self::bound( $min_raw );
			$max = self::bound( $max_raw );
			if ( null === $min && null === $max ) { throw new Price_Validation_Error( 'invalid_price_range' ); }
			if ( null !== $min && null !== $max && Price_Decimal::compare( Price_Decimal::units( $min ), Price_Decimal::units( $max ) ) > 0 ) {
				throw new Price_Validation_Error( 'invalid_price_range' );
			}
			return new self( array( 'enabled' => true, 'min' => $min, 'max' => $max, 'basis' => $basis ) );
		}
		throw new Price_Validation_Error( 'invalid_price_range' );
	}

	/** Strict rehydration of persisted `data()`; malformed material is refused, never coerced. */
	public static function from_data( array $data ): self {
		$enabled = $data['enabled'] ?? null;
		if ( ! is_bool( $enabled ) ) { throw new Price_Validation_Error( 'invalid_price_range' ); }
		if ( ! $enabled ) { return self::disabled(); }
		$basis = $data['basis'] ?? null;
		if ( ! is_string( $basis ) || ! in_array( $basis, Price_Operation::FIELDS, true ) ) { throw new Price_Validation_Error( 'invalid_price_range' ); }
		$min = self::stored_bound( $data['min'] ?? null );
		$max = self::stored_bound( $data['max'] ?? null );
		if ( null === $min && null === $max ) { throw new Price_Validation_Error( 'invalid_price_range' ); }
		if ( null !== $min && null !== $max && Price_Decimal::compare( Price_Decimal::units( $min ), Price_Decimal::units( $max ) ) > 0 ) {
			throw new Price_Validation_Error( 'invalid_price_range' );
		}
		return new self( array( 'enabled' => true, 'min' => $min, 'max' => $max, 'basis' => $basis ) );
	}

	/** Merchant bound: absent on null/'', otherwise canonical or a typed refusal. */
	private static function bound( $raw ): ?string {
		if ( null === $raw || '' === $raw ) { return null; }
		if ( ! is_string( $raw ) ) { throw new Price_Validation_Error( 'invalid_price_range' ); }
		try { return Price_Decimal::parse( $raw ); }
		catch ( Price_Validation_Error $error ) { throw new Price_Validation_Error( 'invalid_price_range' ); }
	}

	/** Stored bound: identical grammar to the merchant input, so persisted filters revalidate exactly. */
	private static function stored_bound( $raw ): ?string {
		return self::bound( $raw );
	}

	/**
	 * Price predicate over one fresh snapshot row (`Product_Price_Snapshot::data()`).
	 * Disabled filters match everything. Otherwise the row matches only when
	 * its own basis-field price is present, parseable and inside the
	 * inclusive bounds. A blank Sale Price therefore never matches, and a
	 * malformed stored price is a determinate non-match (counted as excluded
	 * by range, never silently dropped or escalated).
	 */
	public function matches( array $snapshot ): bool {
		if ( ! $this->values['enabled'] ) { return true; }
		$key = Price_Operation::meta_key( $this->values['basis'] );
		$price = $snapshot[ $key ] ?? null;
		if ( ! is_string( $price ) || '' === $price ) { return false; }
		try { $units = Price_Decimal::units( Price_Decimal::parse( $price ) ); }
		catch ( Price_Validation_Error $error ) { return false; }
		if ( null !== $this->values['min'] && Price_Decimal::compare( $units, Price_Decimal::units( $this->values['min'] ) ) < 0 ) { return false; }
		if ( null !== $this->values['max'] && Price_Decimal::compare( $units, Price_Decimal::units( $this->values['max'] ) ) > 0 ) { return false; }
		return true;
	}

	public function data(): array { return $this->values; }
}
