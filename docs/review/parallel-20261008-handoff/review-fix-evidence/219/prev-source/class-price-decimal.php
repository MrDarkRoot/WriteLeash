<?php
/** Deterministic decimal arithmetic; no optional extensions or native large integers. */
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

final class Price_Validation_Error extends \InvalidArgumentException {
	private string $reason;
	public function __construct( string $reason ) {
		$this->reason = $reason;
		parent::__construct( $reason );
	}
	public function reason(): string { return $this->reason; }
}

/** Prevent dynamic public properties on PHP 7.4 as well as newer runtimes. */
trait Immutable_Price_Value {
	final public function __set( $name, $value ): void {
		throw new Price_Validation_Error( 'immutable_plan' );
	}
	final public function __unset( $name ): void {
		throw new Price_Validation_Error( 'immutable_plan' );
	}
}

final class Price_Decimal {
	public const SCALE = 6;
	public const MAX_INTEGER_DIGITS = 12;

	/** Strict non-negative string grammar. Normalize only insignificant zeros. */
	public static function parse( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value ) ) {
			throw new Price_Validation_Error( 'malformed_decimal' );
		}
		$parts = explode( '.', $value );
		if ( strlen( $parts[0] ) > self::MAX_INTEGER_DIGITS ) {
			throw new Price_Validation_Error( 'price_overflow' );
		}
		if ( isset( $parts[1] ) && strlen( $parts[1] ) > self::SCALE ) {
			throw new Price_Validation_Error( 'input_precision_exceeded' );
		}
		return self::format( self::units( $value ), self::SCALE, true );
	}

	public static function decimals( int $decimals ): void {
		if ( $decimals < 0 || $decimals > self::SCALE ) {
			throw new Price_Validation_Error( 'unsupported_price_decimals' );
		}
	}

	/** Input must already have passed parse(). Six-place units remain strings. */
	public static function units( string $value ): string {
		$parts = explode( '.', $value );
		return self::natural( $parts[0] . str_pad( $parts[1] ?? '', self::SCALE, '0' ) );
	}

	public static function natural( string $value ): string {
		return ltrim( $value, '0' ) ?: '0';
	}
	public static function compare( string $a, string $b ): int {
		$a = self::natural( $a );
		$b = self::natural( $b );
		return strlen( $a ) === strlen( $b ) ? strcmp( $a, $b ) <=> 0 : strlen( $a ) <=> strlen( $b );
	}
	/**
	 * Canonical decimal equality. The empty string means "no stored price"
	 * and equals only itself; null and malformed values equal nothing.
	 */
	public static function equal( $a, $b ): bool {
		if ( $a === $b ) { return true; }
		if ( ! is_string( $a ) || ! is_string( $b ) || '' === $a || '' === $b ) { return false; }
		try { return self::parse( $a ) === self::parse( $b ); }
		catch ( Price_Validation_Error $e ) { return false; }
	}
	public static function add( string $a, string $b ): string {
		$out = '';
		$carry = 0;
		for ( $i = strlen( $a ) - 1, $j = strlen( $b ) - 1; $i >= 0 || $j >= 0 || $carry; --$i, --$j ) {
			$n = ( $i >= 0 ? (int) $a[$i] : 0 ) + ( $j >= 0 ? (int) $b[$j] : 0 ) + $carry;
			$out = (string) ( $n % 10 ) . $out;
			$carry = intdiv( $n, 10 );
		}
		return self::natural( $out );
	}
	/** Non-negative subtraction. */
	public static function subtract( string $a, string $b ): string {
		if ( self::compare( $a, $b ) < 0 ) {
			throw new Price_Validation_Error( 'negative_target' );
		}
		$b = str_pad( $b, strlen( $a ), '0', STR_PAD_LEFT );
		$out = '';
		$borrow = 0;
		for ( $i = strlen( $a ) - 1; $i >= 0; --$i ) {
			$n = (int) $a[$i] - (int) $b[$i] - $borrow;
			$borrow = $n < 0 ? 1 : 0;
			$out = (string) ( $n + 10 * $borrow ) . $out;
		}
		return self::natural( $out );
	}
	public static function multiply( string $a, string $b ): string {
		$out = '0';
		for ( $i = 0; $i < strlen( $b ); ++$i ) {
			$out .= '0';
			for ( $j = 0; $j < (int) $b[$i]; ++$j ) {
				$out = self::add( $out, $a );
			}
		}
		return self::natural( $out );
	}
	/** Integer long division, round half up; used for display only on ratios. */
	public static function divide_round( string $a, string $b ): string {
		if ( '0' === self::natural( $b ) ) {
			throw new Price_Validation_Error( 'percent_from_zero_undefined' );
		}
		$out = '';
		$remainder = '0';
		for ( $i = 0; $i < strlen( $a ); ++$i ) {
			$remainder = self::natural( $remainder . $a[$i] );
			$digit = 0;
			while ( self::compare( $remainder, $b ) >= 0 ) {
				$remainder = self::subtract( $remainder, $b );
				++$digit;
			}
			$out .= (string) $digit;
		}
		$out = self::natural( $out );
		return self::compare( self::multiply( $remainder, '2' ), $b ) >= 0 ? self::add( $out, '1' ) : $out;
	}
	public static function format( string $units, int $scale, bool $trim = false ): string {
		if ( 0 === $scale ) { return self::natural( $units ); }
		$units = str_pad( $units, $scale + 1, '0', STR_PAD_LEFT );
		$value = substr( $units, 0, -$scale ) . '.' . substr( $units, -$scale );
		return $trim ? rtrim( rtrim( $value, '0' ), '.' ) : $value;
	}
	/** Exact intermediate units at $scale; one final half-up rounding. */
	public static function target( string $units, int $scale, int $decimals ): string {
		self::decimals( $decimals );
		$rounded = self::divide_round( $units, '1' . str_repeat( '0', $scale - $decimals ) );
		$value = self::format( $rounded, $decimals );
		self::parse( $value ); // Enforce the target ceiling after rounding, too.
		return $value;
	}
}
