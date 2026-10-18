<?php

namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\ProductQuery\ProductQuery;
class DateRange extends Filter {
    public function getTitle() : string {
        return __( 'Date Range', 'bulk-price-editor' );
    }

    public function getDateType() : string {
        $type = (string) $this->getFilterValue( 'bpe_date_range_type', 'created' );
        return ( in_array( $type, array('created', 'modified'), true ) ? $type : 'created' );
    }

    public function getFromDate() : ?string {
        return $this->sanitizeDate( $this->getFilterValue( 'bpe_date_range_from', '' ) );
    }

    public function getToDate() : ?string {
        return $this->sanitizeDate( $this->getFilterValue( 'bpe_date_range_to', '' ) );
    }

    protected function sanitizeDate( $value ) : ?string {
        $value = (string) $value;
        if ( !preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $value ) || !strtotime( $value ) ) {
            return null;
        }
        return $value;
    }

    public function filterQuery( ProductQuery $query ) : void {
    }

    public function getSectionArgs() : array {
        return array(
            'open' => !is_null( $this->getFromDate() ) || !is_null( $this->getToDate() ),
        );
    }

    public function renderFields( Widget $widget ) {
        $widget->renderRadioButtons( array(
            'id'                => 'bpe_date_range_type',
            'label'             => __( 'Filter by', 'bulk-price-editor' ),
            'options'           => array(
                'created'  => __( 'Date added', 'bulk-price-editor' ),
                'modified' => __( 'Date modified', 'bulk-price-editor' ),
            ),
            'value'             => $this->getDateType(),
            'custom_attributes' => array(
                'data-product-filter' => 'yes',
            ),
        ) );
        $widget->renderTextInput( array(
            'id'                => 'bpe_date_range_from',
            'type'              => 'date',
            'label'             => __( 'From', 'bulk-price-editor' ),
            'value'             => $this->getFromDate(),
            'custom_attributes' => array(
                'data-product-filter' => 'yes',
            ),
        ) );
        $widget->renderTextInput( array(
            'id'                => 'bpe_date_range_to',
            'type'              => 'date',
            'label'             => __( 'To', 'bulk-price-editor' ),
            'value'             => $this->getToDate(),
            'custom_attributes' => array(
                'data-product-filter' => 'yes',
            ),
        ) );
    }

    public function isPremium() : bool {
        return true;
    }

}
