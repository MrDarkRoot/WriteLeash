<?php namespace BulkPriceEditor\ProductsTable;

use BulkPriceEditor\ProductsTable\Columns\RegularPriceColumn;
use BulkPriceEditor\ProductsTable\Columns\ProductTypeColumn;
use BulkPriceEditor\ProductsTable\Columns\StockStatusColumn;
use BulkPriceEditor\ProductsTable\Columns\CategoriesColumn;
use BulkPriceEditor\ProductsTable\Columns\SalePriceColumn;
use BulkPriceEditor\PriceModifiers\Modifiers\PriceModifier;
use BulkPriceEditor\ProductsTable\Columns\TitleColumn;
use BulkPriceEditor\ProductsTable\Columns\ImageColumn;
use BulkPriceEditor\ProductsTable\Columns\DateColumn;
use BulkPriceEditor\ProductQuery\ProductQuery;
use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WPListProductsTable extends WP_List_Table {
	
	const PRODUCT_PER_PAGE = 15;
	
	public ProductQuery $query;
	public ?PriceModifier $priceModifier;
	public array $columns = array();
	
	public function __construct( ProductQuery $query, ?PriceModifier $priceModifier = null ) {
		parent::__construct( [
			'singular' => __( 'Product', 'bulk-price-editor' ),
			'plural'   => __( 'Products', 'bulk-price-editor' ),
			'ajax'     => true,
			'screen'   => 'products',
		] );
		
		$this->query         = $query;
		$this->priceModifier = $priceModifier;
		
		$this->columns = apply_filters( 'bulk_price_editor/products_table/columns', array(
			new ImageColumn(),
			new TitleColumn(),
			new CategoriesColumn(),
			new RegularPriceColumn(),
			new SalePriceColumn(),
			new StockStatusColumn(),
			new ProductTypeColumn(),
			new DateColumn(),
		), $this );
	}
	
	public function get_columns(): array {
		
		$_columns = array();
		
		foreach ( $this->columns as $column ) {
			$_columns[ $column->getSlug() ] = $column->getName();
		}
		
		return $_columns;
	}
	
	protected function get_sortable_columns(): array {
		
		$_columns = array();
		
		foreach ( $this->columns as $column ) {
			if ( $column->sortable() ) {
				$_columns[ $column->getSlug() ] = array( $column->getSlug(), false );
			}
		}
		
		return $_columns;
	}
	
	protected function column_default( $item, $column_name ) {
		foreach ( $this->columns as $column ) {
			if ( $column->getSlug() === $column_name ) {
				return $column->render( $item, $this );
			}
		}
		
		return '';
	}
	
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="product[]" value="%s" />', $item['ID'] );
	}
	
	public function display() {
		?>
		<div class="bulk-price-editor-product-table-data-provider"
			 data-total-products="<?php echo esc_attr( $this->get_pagination_arg( 'total_items' ) ); ?>"
			 data-current-page="<?php echo esc_attr( $this->get_pagenum() ); ?>">
			<?php parent::display(); ?>
		</div>
		<?php
	}
	
	public function prepare_items( ?ProductQuery $query = null ) {
		$per_page     = $this->get_items_per_page( 'products_per_page', self::PRODUCT_PER_PAGE );
		$current_page = $this->get_pagenum();
		
		$this->query->args['page']           = $current_page;
		$this->query->args['posts_per_page'] = $per_page;
		// 'limit' is what WC_Product_Query actually paginates by.
		$this->query->args['limit']          = $per_page;
		
		$productQuery = $this->query->build();
		
		$products = $productQuery->get_products();

		$this->items = $products->products;

		// One grouped query for the "Price history (N)" row actions of this page.
		\BulkPriceEditor\History\PriceLogRepository::primeCounts( array_map( function ( $product ) {
			return $product instanceof \WC_Product ? $product->get_id() : 0;
		}, (array) $this->items ) );
		
		$this->set_pagination_args( [
			'total_items' => $products->total,
			'per_page'    => $per_page,
			'total_pages' => $products->max_num_pages,
		] );
	}
	
	public function no_items() {
		esc_html_e( 'No products found.', 'bulk-price-editor' );
	}
	
	public function addSorting() {
		$orderBy = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( $_REQUEST['orderby'] ) : '';
		$order   = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_text_field( $_REQUEST['order'] ) ) : '';
		
		$order = in_array( $order, array( 'ASC', 'DESC' ) ) ? $order : 'DESC';
		
		$this->query->args['order'] = $order;
		
		switch ( $orderBy ) {
			case 'title':
				$this->query->args['orderby'] = 'title';
				break;
			case 'regular_price':
				$this->query->args['orderby']  = 'meta_value_num';
				$this->query->args['meta_key'] = '_regular_price';
				break;
			case 'sale_price':
				$this->query->args['orderby']  = 'meta_value_num';
				$this->query->args['meta_key'] = '_sale_price';
				break;
			case 'stock_status':
				$this->query->args['orderby']  = 'meta_value';
				$this->query->args['meta_key'] = '_stock_status';
				break;
			case 'date':
				$this->query->args['orderby'] = 'date';
				break;
		}
	}
}