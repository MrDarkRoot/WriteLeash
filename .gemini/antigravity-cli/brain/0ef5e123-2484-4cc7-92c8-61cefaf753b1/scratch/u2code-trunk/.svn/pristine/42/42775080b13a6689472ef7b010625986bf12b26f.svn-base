<?php namespace BulkPriceEditor\PriceEditorPage\Services;

use BulkPriceEditor\Core\ServiceContainerTrait;
use BulkPriceEditor\ProductFilters\Filters\FilterByBrands;
use BulkPriceEditor\ProductFilters\Filters\FilterByShippingClass;
use BulkPriceEditor\ProductFilters\Filters\FilterByTags;
use WP_Term;

class LookupService {
	
	use ServiceContainerTrait;
	
	const CATEGORIES_SEARCH_ACTION = 'woocommerce_json_search_bpe_categories';
	const ATTRIBUTES_SEARCH_ACTION = 'woocommerce_json_search_bpe_attribute';
	const BRANDS_SEARCH_ACTION = 'woocommerce_json_search_bpe_brands';
	const TAGS_SEARCH_ACTION = 'woocommerce_json_search_bpe_tags';
	const SHIPPING_CLASSES_SEARCH_ACTION = 'woocommerce_json_search_bpe_shipping_classes';

	public function __construct() {
		add_action( 'wp_ajax_' . self::CATEGORIES_SEARCH_ACTION, array( $this, 'categoriesSearchHandler' ) );
		add_action( 'wp_ajax_' . self::ATTRIBUTES_SEARCH_ACTION, array( $this, 'attributesSearchHandler' ) );
		add_action( 'wp_ajax_' . self::BRANDS_SEARCH_ACTION, array( $this, 'brandsSearchHandler' ) );
		add_action( 'wp_ajax_' . self::TAGS_SEARCH_ACTION, array( $this, 'tagsSearchHandler' ) );
		add_action( 'wp_ajax_' . self::SHIPPING_CLASSES_SEARCH_ACTION, array( $this, 'shippingClassesSearchHandler' ) );
	}

	public function shippingClassesSearchHandler() {

		if ( ! current_user_can( \BulkPriceEditor\BulkPriceEditorPlugin::capability() ) ) {
			wp_send_json( array() );
		}

		check_ajax_referer( 'search-products', 'security' );

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : false;

		if ( $term ) {
			wp_send_json( $this->termSearchHandler( FilterByShippingClass::TAXONOMY, $term ) );
		}

		wp_send_json( array() );
	}

	public function tagsSearchHandler() {

		if ( ! current_user_can( \BulkPriceEditor\BulkPriceEditorPlugin::capability() ) ) {
			wp_send_json( array() );
		}

		check_ajax_referer( 'search-products', 'security' );

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : false;

		if ( $term ) {
			wp_send_json( $this->termSearchHandler( FilterByTags::TAXONOMY, $term ) );
		}

		wp_send_json( array() );
	}

	public function brandsSearchHandler() {

		if ( ! current_user_can( \BulkPriceEditor\BulkPriceEditorPlugin::capability() ) ) {
			wp_send_json( array() );
		}

		check_ajax_referer( 'search-products', 'security' );

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : false;

		if ( $term && FilterByBrands::isSupported() ) {
			wp_send_json( $this->termSearchHandler( FilterByBrands::TAXONOMY, $term ) );
		}

		wp_send_json( array() );
	}
	
	public function attributesSearchHandler() {
		
		if ( ! current_user_can( \BulkPriceEditor\BulkPriceEditorPlugin::capability() ) ) {
			wp_send_json( array() );
		}
		
		check_ajax_referer( 'search-products', 'security' );
		
		$term      = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : false;
		$attribute = isset( $_GET['include'] ) ? sanitize_text_field( wp_unslash( $_GET['include'] ) ) : false;
		
		if ( $term ) {
			wp_send_json( $this->termSearchHandler( $attribute, $term ) );
		}
		
		wp_send_json( array() );
	}
	
	public function categoriesSearchHandler() {
		
		if ( ! current_user_can( \BulkPriceEditor\BulkPriceEditorPlugin::capability() ) ) {
			wp_send_json( array() );
		}
		
		check_ajax_referer( 'search-products', 'security' );
		
		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : false;
		
		if ( $term ) {
			wp_send_json( $this->termSearchHandler( 'product_cat', $term ) );
		}
		
		wp_send_json( array() );
	}
	
	protected function termSearchHandler( string $taxonomy, string $term ): array {
		
		$args = array(
			'taxonomy'   => array( $taxonomy ),
			'orderby'    => 'id',
			'order'      => 'ASC',
			'limit'      => 5,
			'hide_empty' => false,
			'fields'     => 'all',
			'name__like' => $term,
		);
		
		$terms = get_terms( $args );

		if ( $terms && ! is_wp_error( $terms ) ) {
			$_terms = array();
			
			foreach ( $terms as $term ) {
				if ( $term instanceof WP_Term ) {
					$_terms[ $term->term_id ] = self::getTermLabel( $term );
				}
			}
			
			return $_terms;
		}
		
		return array();
	}
	
	public static function getTermLabel( WP_Term $category ): string {
		$parentTermName = '';
		
		if ( $category->parent ) {
			$parentTerm = get_term( $category->parent );
			
			if ( $parentTerm ) {
				$parentTermName = ' (' . $parentTerm->name . ')';
			}
		}
		
		return $category->name . $parentTermName;
	}
}
