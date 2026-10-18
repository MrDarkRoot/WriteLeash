<?php namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\ProductQuery\ProductQuery;

class PriceRange extends Filter {

	const QUERY_VAR = 'bpe_price_range';

	protected static bool $queryFilterRegistered = false;

	public function getTitle(): string {
		return __( 'Price Range', 'bulk-price-editor' );
	}

	public function getPriceType(): string {
		$type = (string) $this->getFilterValue( 'bpe_price_range_type', 'regular_price' );

		return in_array( $type, array( 'regular_price', 'sale_price' ), true ) ? $type : 'regular_price';
	}

	public function getMinPrice(): ?float {
		$value = $this->getFilterValue( 'bpe_price_range_min', '' );

		return is_numeric( $value ) ? (float) $value : null;
	}

	public function getMaxPrice(): ?float {
		$value = $this->getFilterValue( 'bpe_price_range_max', '' );

		return is_numeric( $value ) ? (float) $value : null;
	}

	public function filterQuery( ProductQuery $query ): void {

		$min = $this->getMinPrice();
		$max = $this->getMaxPrice();

		if ( is_null( $min ) && is_null( $max ) ) {
			return;
		}

		$query->args[ self::QUERY_VAR ] = array(
			'meta_key' => 'sale_price' === $this->getPriceType() ? '_sale_price' : '_regular_price',
			'min'      => $min,
			'max'      => $max,
		);

		self::registerQueryFilter();
	}

	/**
	 * WC_Product_Query ignores a meta_query passed through its query vars,
	 * so the range is carried in an own var and converted to a meta query
	 * through the product data store filter.
	 */
	protected static function registerQueryFilter(): void {

		if ( self::$queryFilterRegistered ) {
			return;
		}

		self::$queryFilterRegistered = true;

		add_filter( 'woocommerce_product_data_store_cpt_get_products_query',
			function ( $wpQueryArgs, $queryVars ) {

				if ( empty( $queryVars[ self::QUERY_VAR ] ) ) {
					return $wpQueryArgs;
				}

				$range = (array) $queryVars[ self::QUERY_VAR ];

				$metaKey = in_array( $range['meta_key'] ?? '', array( '_regular_price', '_sale_price' ), true )
					? $range['meta_key'] : '_regular_price';

				$min = isset( $range['min'] ) && is_numeric( $range['min'] ) ? (float) $range['min'] : null;
				$max = isset( $range['max'] ) && is_numeric( $range['max'] ) ? (float) $range['max'] : null;

				// A product without the price stores '' which a DECIMAL cast
				// would compare as 0, so require a real value first.
				$wpQueryArgs['meta_query'][] = array(
					'key'     => $metaKey,
					'value'   => '',
					'compare' => '!=',
				);

				if ( ! is_null( $min ) && ! is_null( $max ) ) {
					$wpQueryArgs['meta_query'][] = array(
						'key'     => $metaKey,
						'value'   => array( $min, $max ),
						'compare' => 'BETWEEN',
						'type'    => 'DECIMAL(19,4)',
					);
				} elseif ( ! is_null( $min ) ) {
					$wpQueryArgs['meta_query'][] = array(
						'key'     => $metaKey,
						'value'   => $min,
						'compare' => '>=',
						'type'    => 'DECIMAL(19,4)',
					);
				} elseif ( ! is_null( $max ) ) {
					$wpQueryArgs['meta_query'][] = array(
						'key'     => $metaKey,
						'value'   => $max,
						'compare' => '<=',
						'type'    => 'DECIMAL(19,4)',
					);
				}

				return $wpQueryArgs;
			}, 10, 2 );
	}

	public function getSectionArgs(): array {
		return array(
			'open' => ! is_null( $this->getMinPrice() ) || ! is_null( $this->getMaxPrice() ),
		);
	}

	public function renderFields( Widget $widget ) {

		$widget->renderRadioButtons( array(
			'id'                => 'bpe_price_range_type',
			'label'             => __( 'Filter by', 'bulk-price-editor' ),
			'options'           => array(
				'regular_price' => __( 'Regular price', 'bulk-price-editor' ),
				'sale_price'    => __( 'Sale price', 'bulk-price-editor' ),
			),
			'value'             => $this->getPriceType(),
			'custom_attributes' => array(
				'data-product-filter' => 'yes',
			),
		) );

		$widget->renderTextInput( array(
			'id'                => 'bpe_price_range_min',
			'type'              => 'number',
			/* translators: %s: Currency symbol */
			'label'             => sprintf( __( 'Min price (%s)', 'bulk-price-editor' ),
				get_woocommerce_currency_symbol() ),
			'placeholder'       => __( 'No minimum', 'bulk-price-editor' ),
			'value'             => $this->getMinPrice(),
			'custom_attributes' => array(
				'data-product-filter' => 'yes',
				'min'                 => '0',
				'step'                => 'any',
			),
		) );

		$widget->renderTextInput( array(
			'id'                => 'bpe_price_range_max',
			'type'              => 'number',
			/* translators: %s: Currency symbol */
			'label'             => sprintf( __( 'Max price (%s)', 'bulk-price-editor' ),
				get_woocommerce_currency_symbol() ),
			'placeholder'       => __( 'No maximum', 'bulk-price-editor' ),
			'value'             => $this->getMaxPrice(),
			'custom_attributes' => array(
				'data-product-filter' => 'yes',
				'min'                 => '0',
				'step'                => 'any',
			),
		) );
	}
}
