<?php namespace BulkPriceEditor\ProductQuery;

use BulkPriceEditor\BulkPriceEditorPlugin;
use BulkPriceEditor\ProductFilters\FilterManager;
use WC_Product_Query;

class ProductQuery {
	
	protected ?FilterManager $filterManager;
	
	protected array $defaultArgs = array(
		'paginate'       => true,
		'limit'          => 10,
		'page'           => 1,
		'posts_per_page' => 10,
	);
	
	public array $args = array();
	
	public function __construct( ?FilterManager $filterManager = null, array $args = array() ) {
		$this->args          = wp_parse_args( $args, $this->defaultArgs );
		$this->filterManager = $filterManager;
	}
	
	public function build(): WC_Product_Query {
		
		$this->args['type'] = BulkPriceEditorPlugin::getDefaultProductTypes();
		
		if ( $this->filterManager ) {
			$this->filterManager->filterQuery( $this );
		}
		
		return new WC_Product_Query( $this->args );
	}
}
