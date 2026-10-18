<?php

namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\ProductQuery\ProductQuery;
class FilterByAttributes extends Filter {
    public function getTitle() : string {
        return __( 'Product Attributes', 'bulk-price-editor' );
    }

    public function filterQuery( ProductQuery $query ) : void {
    }

    public function getAttributeValue( string $attribute, string $return = 'ids' ) : array {
        $termIds = $this->sanitizeArrayIds( $this->getFilterValue( 'bpe_product_attribute_pa_' . $attribute, array() ) );
        if ( 'ids' === $return ) {
            return $termIds;
        }
        $terms = array();
        foreach ( $termIds as $termId ) {
            $term = get_term( $termId );
            if ( $term ) {
                $terms[$term->term_id] = $term->name;
            }
        }
        return $terms;
    }

    public function renderFields( Widget $widget ) {
        foreach ( $this->getAvailableAttributes() as $attributeKey => $attributeLabel ) {
            $attributes = $this->getAttributeValue( $attributeKey, 'names' );
            $widget->renderSelect2( array(
                'id'                => 'bpe_product_attribute_pa_' . $attributeKey,
                'label'             => $attributeLabel,
                'search_action'     => 'woocommerce_json_search_bpe_attribute',
                'placeholder'       => __( 'Select', 'bulk-price-editor' ) . ' ' . $attributeLabel,
                'value'             => $attributes,
                'custom_attributes' => array(
                    'data-product-filter' => 'yes',
                    'data-include'        => 'pa_' . $attributeKey,
                ),
            ) );
        }
    }

    public function getSectionArgs() : array {
        $isOpen = false;
        foreach ( $this->getAvailableAttributes() as $attributeKey => $attributeLabel ) {
            if ( !empty( $this->getAttributeValue( $attributeKey ) ) ) {
                $isOpen = true;
                break;
            }
        }
        return array(
            'open' => $isOpen,
        );
    }

    protected function getAvailableAttributes() : array {
        $attributes = array();
        foreach ( wc_get_attribute_taxonomies() as $attribute ) {
            $attributes[$attribute->attribute_name] = $attribute->attribute_label;
        }
        return $attributes;
    }

    public function isPremium() : bool {
        return true;
    }

}
