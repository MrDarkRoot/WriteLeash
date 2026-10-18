<?php namespace BulkPriceEditor\ProductsTable\Columns;

use BulkPriceEditor\ProductsTable\WPListProductsTable;
use WC_Product;

abstract class Column {
	
	abstract public function getSlug(): string;
	
	abstract public function getName(): string;
	
	abstract public function render( WC_Product $product, WPListProductsTable $table ): string;
	
	abstract public function sortable(): bool;
}
