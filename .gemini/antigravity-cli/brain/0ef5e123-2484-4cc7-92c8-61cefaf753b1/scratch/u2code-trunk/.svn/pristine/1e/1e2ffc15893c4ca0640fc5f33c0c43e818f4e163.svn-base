<?php namespace BulkPriceEditor\Actions;

use BulkPriceEditor\BulkPriceEditorPlugin;
use BulkPriceEditor\History\RunsRepository;
use BulkPriceEditor\PriceModifiers\Modifiers\PriceModifier;
use BulkPriceEditor\PriceModifiers\Modifiers\RemovePrice;
use BulkPriceEditor\ProductFilters\FilterManager;
use BulkPriceEditor\ProductQuery\ProductQuery;
use BulkPriceEditor\Request\Request;
use WC_Product;
use WC_Product_Variable;

/**
 * CSV downloads: the current preview (current vs. new prices for everything
 * the filters match) and the snapshot of a past run from the history.
 */
class ExportCsvAction {

	const EXPORT_PREVIEW__ACTION_NAME = 'bulk_price_editor_export_preview';
	const EXPORT_RUN__ACTION_NAME = 'bulk_price_editor_export_run';

	const BATCH_SIZE = 200;

	public function __construct() {
		add_action( 'admin_post_' . self::EXPORT_PREVIEW__ACTION_NAME, array( $this, 'exportPreview' ) );
		add_action( 'admin_post_' . self::EXPORT_RUN__ACTION_NAME, array( $this, 'exportRun' ) );
	}

	public function exportPreview(): void {

		if ( ! current_user_can( BulkPriceEditorPlugin::capability() ) ) {
			wp_die( 'Access denied' );
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : null;

		if ( ! wp_verify_nonce( $nonce, self::EXPORT_PREVIEW__ACTION_NAME ) ) {
			wp_die( 'Access denied' );
		}

		$priceModifier = Request::getPriceModifier( $_REQUEST );

		$productQuery = new ProductQuery( Request::getFilterManager( $_REQUEST ), array(
			'posts_per_page' => - 1,
			'limit'          => - 1,
			'return'         => 'ids',
			'paginate'       => false,
		) );

		$productIds = SchedulePriceUpdatesAction::excludeVariationsCoveredByParent(
			$productQuery->build()->get_products() );

		$output = $this->startCsv( 'bulk-price-editor-preview-' . gmdate( 'Ymd-Hi' ) . '.csv' );

		$this->writeRow( $output, array(
			__( 'ID', 'bulk-price-editor' ),
			__( 'Product', 'bulk-price-editor' ),
			__( 'SKU', 'bulk-price-editor' ),
			__( 'Type', 'bulk-price-editor' ),
			__( 'Current regular price', 'bulk-price-editor' ),
			__( 'Current sale price', 'bulk-price-editor' ),
			__( 'New regular price', 'bulk-price-editor' ),
			__( 'New sale price', 'bulk-price-editor' ),
		) );

		foreach ( array_chunk( $productIds, self::BATCH_SIZE ) as $chunk ) {

			$products = wc_get_products( array(
				'limit'    => - 1,
				'type'     => array_keys( BulkPriceEditorPlugin::getSupportedProductTypes() ),
				'include'  => $chunk,
				'paginate' => false,
			) );

			foreach ( $products as $product ) {

				if ( ! $product instanceof WC_Product ) {
					continue;
				}

				$this->writeProductRow( $output, $product, $priceModifier );

				// The update recurses into variations — the export mirrors that.
				if ( $product instanceof WC_Product_Variable ) {
					foreach ( $product->get_children() as $childId ) {

						$child = wc_get_product( $childId );

						if ( $child instanceof WC_Product ) {
							$this->writeProductRow( $output, $child, $priceModifier );
						}
					}
				}
			}
		}

		exit;
	}

	protected function writeProductRow( $output, WC_Product $product, ?PriceModifier $priceModifier ): void {

		$newRegular = '';
		$newSale    = '';

		if ( $priceModifier ) {
			$newRegular = $this->formatNewPrice( $priceModifier->getFinalRegularPrice( $product ) );
			$newSale    = $this->formatNewPrice( $priceModifier->getFinalSalePrice( $product ) );
		}

		$this->writeRow( $output, array(
			$product->get_id(),
			$product->get_name(),
			$product->get_sku(),
			$product->get_type(),
			(string) $product->get_regular_price( 'edit' ),
			(string) $product->get_sale_price( 'edit' ),
			$newRegular,
			$newSale,
		) );
	}

	/**
	 * Blank cell = the update leaves this price unchanged.
	 */
	protected function formatNewPrice( $price ): string {

		if ( is_null( $price ) ) {
			return '';
		}

		if ( RemovePrice::REMOVE === $price ) {
			return __( '(removed)', 'bulk-price-editor' );
		}

		return is_numeric( $price ) ? (string) (float) $price : '';
	}

	public function exportRun(): void {

		if ( ! current_user_can( BulkPriceEditorPlugin::capability() ) ) {
			wp_die( 'Access denied' );
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : null;

		if ( ! wp_verify_nonce( $nonce, self::EXPORT_RUN__ACTION_NAME ) ) {
			wp_die( 'Access denied' );
		}

		$runId = isset( $_REQUEST['run_id'] ) ? absint( $_REQUEST['run_id'] ) : 0;

		if ( ! $runId || ! RunsRepository::getRun( $runId ) ) {
			wp_die( 'Invalid request' );
		}

		$output = $this->startCsv( 'bulk-price-editor-run-' . $runId . '.csv' );

		$this->writeRow( $output, array(
			__( 'ID', 'bulk-price-editor' ),
			__( 'Product', 'bulk-price-editor' ),
			__( 'Regular price before', 'bulk-price-editor' ),
			__( 'Regular price after', 'bulk-price-editor' ),
			__( 'Sale price before', 'bulk-price-editor' ),
			__( 'Sale price after', 'bulk-price-editor' ),
			__( 'Sale start after', 'bulk-price-editor' ),
			__( 'Sale end after', 'bulk-price-editor' ),
		) );

		$offset = 0;

		while ( $rows = RunsRepository::getPriceRows( $runId, 500, $offset ) ) {

			foreach ( $rows as $row ) {

				$product = wc_get_product( (int) $row['product_id'] );

				$this->writeRow( $output, array(
					$row['product_id'],
					$product instanceof WC_Product ? $product->get_name() : __( '(deleted product)', 'bulk-price-editor' ),
					(string) $row['old_regular'],
					is_null( $row['new_regular'] ) ? (string) $row['old_regular'] : (string) $row['new_regular'],
					(string) $row['old_sale'],
					is_null( $row['new_sale'] ) ? (string) $row['old_sale'] : (string) $row['new_sale'],
					$this->formatSnapshotDate( $row['new_sale_from'] ),
					$this->formatSnapshotDate( $row['new_sale_to'] ),
				) );
			}

			$offset += 500;
		}

		exit;
	}

	protected function formatSnapshotDate( $timestamp ): string {

		if ( is_null( $timestamp ) || '' === $timestamp ) {
			return '';
		}

		return (string) wp_date( 'Y-m-d H:i', (int) $timestamp );
	}

	/**
	 * @return resource
	 */
	protected function startCsv( string $filename ) {

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		return fopen( 'php://output', 'w' );
	}

	/**
	 * One CSV line. The escape character is passed explicitly (empty = plain
	 * RFC 4180 quoting) because relying on PHP's default is deprecated.
	 *
	 * @param  resource  $output
	 */
	protected function writeRow( $output, array $row ): void {
		fputcsv( $output, $row, ',', '"', '' );
	}

	public static function getExportPreviewURL(): string {
		return add_query_arg( array(
			'action'   => self::EXPORT_PREVIEW__ACTION_NAME,
			'_wpnonce' => wp_create_nonce( self::EXPORT_PREVIEW__ACTION_NAME ),
		), admin_url( 'admin-post.php' ) );
	}

	public static function getExportRunURL( int $runId ): string {
		return add_query_arg( array(
			'action'   => self::EXPORT_RUN__ACTION_NAME,
			'run_id'   => $runId,
			'_wpnonce' => wp_create_nonce( self::EXPORT_RUN__ACTION_NAME ),
		), admin_url( 'admin-post.php' ) );
	}
}
