<?php namespace BulkPriceEditor\History;

use BulkPriceEditor\PriceModifiers\Modifiers\PriceModifier;
use BulkPriceEditor\PriceModifiers\Modifiers\RemovePrice;
use WC_Product;

/**
 * Captures a per-product snapshot (prices before and after) while a run is
 * processing. The actions set the current run id; the modifiers fire
 * `bulk_price_editor/product/before_price_update` for every concrete product
 * (variations included) right before saving it.
 *
 * Snapshot semantics for the new_* columns:
 *  - NULL — the run left this field untouched (revert skips it),
 *  - ''   — the run cleared the field,
 *  - value — the run set this value.
 * The old_* columns always hold the raw value before the update ('' = empty).
 */
class HistoryManager {

	protected static ?int $currentRunId = null;

	public static function init(): void {
		add_action( 'bulk_price_editor/product/before_price_update',
			array( self::class, 'recordSnapshot' ), 10, 2 );
	}

	public static function setCurrentRunId( ?int $runId ): void {
		self::$currentRunId = $runId;
	}

	public static function getCurrentRunId(): ?int {
		return self::$currentRunId;
	}

	public static function recordSnapshot( WC_Product $product, PriceModifier $modifier ): void {

		$runId = self::$currentRunId;

		if ( ! $runId ) {
			return;
		}

		// A retried chunk must not overwrite the original "before" values.
		if ( RunsRepository::getPriceRow( $runId, $product->get_id() ) ) {
			return;
		}

		$datesChange = $modifier->getSaleDatesChange();

		RunsRepository::insertPriceRow( $runId, $product->get_id(), array(
			'old_regular'   => (string) $product->get_regular_price( 'edit' ),
			'old_sale'      => (string) $product->get_sale_price( 'edit' ),
			'new_regular'   => self::normalizeNewPrice( $modifier->getFinalRegularPrice( $product ) ),
			'new_sale'      => self::normalizeNewPrice( $modifier->getFinalSalePrice( $product ) ),
			'old_sale_from' => self::dateToTimestampString( $product->get_date_on_sale_from( 'edit' ) ),
			'old_sale_to'   => self::dateToTimestampString( $product->get_date_on_sale_to( 'edit' ) ),
			'new_sale_from' => is_null( $datesChange ) ? null : self::dateStringToTimestampString( $datesChange['from'] ),
			'new_sale_to'   => is_null( $datesChange ) ? null : self::dateStringToTimestampString( $datesChange['to'] ),
		) );
	}

	/**
	 * Modifier results map to snapshot columns: null = untouched,
	 * RemovePrice's sentinel = cleared, number = new value.
	 */
	protected static function normalizeNewPrice( $price ): ?string {

		if ( is_null( $price ) ) {
			return null;
		}

		if ( RemovePrice::REMOVE === $price ) {
			return '';
		}

		return is_numeric( $price ) ? (string) (float) $price : null;
	}

	/**
	 * Sale dates are stored as Unix timestamps so the revert round-trips
	 * exactly ('' = the product had no date set).
	 */
	public static function dateToTimestampString( $date ): string {
		return $date instanceof \WC_DateTime ? (string) $date->getTimestamp() : '';
	}

	/**
	 * A user-entered date ('Y-m-d', site timezone — same as the WooCommerce
	 * product screen) as a timestamp string; '' clears the date.
	 */
	public static function dateStringToTimestampString( string $date ): string {

		if ( '' === trim( $date ) ) {
			return '';
		}

		try {
			return (string) wc_string_to_datetime( $date )->getTimestamp();
		} catch ( \Exception $e ) {
			return '';
		}
	}
}
