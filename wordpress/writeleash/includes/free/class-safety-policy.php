<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

final class Safety_Policy {
	use Immutable_Price_Value;
	private array $values;
	public function __construct( int $max_products, $max_increase, $max_decrease, bool $block_zero, $warning_threshold ) {
		if ( $max_products < 0 || $max_products > 1000 ) { throw new Price_Validation_Error( 'invalid_product_limit' ); }
		$this->values = array(
			'max_products_changed' => $max_products,
			'max_increase_percent' => Price_Decimal::parse( $max_increase ),
			'max_decrease_percent' => Price_Decimal::parse( $max_decrease ),
			'block_zero' => $block_zero,
			'warning_threshold_percent' => Price_Decimal::parse( $warning_threshold ),
		);
	}
	public function data(): array { return $this->values; }
}

final class Policy_Result {
	use Immutable_Price_Value;
	public const ALLOW = 'ALLOW';
	public const ALLOW_WITH_WARNINGS = 'ALLOW_WITH_WARNINGS';
	public const BLOCKED = 'BLOCKED';
	private array $values;
	public function __construct( array $blockers, array $warnings ) {
		$this->values = array( 'state' => $blockers ? self::BLOCKED : ( $warnings ? self::ALLOW_WITH_WARNINGS : self::ALLOW ), 'blockers' => $blockers, 'warnings' => $warnings );
	}
	public function data(): array { return $this->values; }
}

final class Policy_Evaluator {
	/** Compare exact ratios, never the rounded percentage display. */
	private static function exceeds( string $old, string $delta, string $percent ): bool {
		return Price_Decimal::compare(
			Price_Decimal::multiply( $delta, '100000000' ),
			Price_Decimal::multiply( $old, Price_Decimal::units( $percent ) )
		) > 0;
	}
	public static function item( string $expected, string $target, Safety_Policy $policy ): Policy_Result {
		$p = $policy->data();
		$old = Price_Decimal::units( Price_Decimal::parse( $expected ) );
		$new = Price_Decimal::units( Price_Decimal::parse( $target ) );
		$direction = Price_Decimal::compare( $new, $old );
		$blockers = array();
		$warnings = array();
		if ( 0 === $direction ) { return new Policy_Result( $blockers, $warnings ); }
		if ( $p['block_zero'] && '0' === $new ) { $blockers[] = 'zero_target_blocked'; }
		if ( '0' === $old ) {
			// A fixed/SET increase from zero has an undefined ratio: never bypass a cap.
			$blockers[] = 'percentage_limit_from_zero_undefined';
		} else {
			$delta = $direction > 0 ? Price_Decimal::subtract( $new, $old ) : Price_Decimal::subtract( $old, $new );
			$limit = $direction > 0 ? 'max_increase_percent' : 'max_decrease_percent';
			if ( self::exceeds( $old, $delta, $p[$limit] ) ) { $blockers[] = $direction > 0 ? 'max_increase_exceeded' : 'max_decrease_exceeded'; }
			if ( self::exceeds( $old, $delta, $p['warning_threshold_percent'] ) ) { $warnings[] = $direction > 0 ? 'large_price_increase' : 'large_price_decrease'; }
		}
		return new Policy_Result( $blockers, $warnings );
	}
	public static function plan( array $items, Safety_Policy $policy ): Policy_Result {
		$blockers = array();
		$warnings = array();
		$changing = 0;
		foreach ( $items as $item ) {
			$d = $item->data();
			if ( 'CHANGING' === $d['result'] ) { ++$changing; }
			foreach ( $d['blockers'] as $reason ) { $blockers[] = array( 'product_id' => $d['product_id'], 'reason' => $reason ); }
			foreach ( $d['warnings'] as $reason ) { $warnings[] = array( 'product_id' => $d['product_id'], 'reason' => $reason ); }
		}
		if ( $changing > $policy->data()['max_products_changed'] ) { $blockers[] = array( 'product_id' => null, 'reason' => 'max_products_exceeded' ); }
		return new Policy_Result( $blockers, $warnings );
	}
}
