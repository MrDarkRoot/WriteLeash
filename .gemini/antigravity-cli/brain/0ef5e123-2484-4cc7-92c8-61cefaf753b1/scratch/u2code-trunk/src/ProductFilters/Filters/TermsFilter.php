<?php

namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\ProductQuery\ProductQuery;
use WP_Term;
/**
 * Base for filters that match products by taxonomy terms (brands, tags, ...).
 *
 * Terms are assigned to parent products only, so a plain tax_query would never
 * match product variations. The WHERE clause built here matches products
 * carrying the term AND products whose parent carries it.
 */
abstract class TermsFilter extends Filter {
    const QUERY_VAR = 'bpe_term_relationship_clauses';

    protected static bool $queryFilterRegistered = false;

    public abstract function getTaxonomy() : string;

    protected abstract function getIncludeFilterKey() : string;

    protected abstract function getExcludeFilterKey() : string;

    /**
     * The include/exclude fields are rendered inside the Included Products and
     * Excluded Products sections rather than in an own section.
     */
    public abstract function renderIncludeField( Widget $widget );

    public abstract function renderExcludeField( Widget $widget );

    public function renderFields( Widget $widget ) {
        $this->renderIncludeField( $widget );
        $this->renderExcludeField( $widget );
    }

    public function getIncludedTerms( string $return = 'ids', bool $includeChild = false ) : array {
        return $this->getTerms( $this->getIncludeFilterKey(), $return, $includeChild );
    }

    public function getExcludedTerms( string $return = 'ids', bool $includeChild = false ) : array {
        return $this->getTerms( $this->getExcludeFilterKey(), $return, $includeChild );
    }

    protected function getTerms( string $key, string $return = 'ids', bool $includeChild = false ) : array {
        $termIds = $this->sanitizeArrayIds( $this->getFilterValue( $key, array() ) );
        if ( $includeChild ) {
            $withChildren = array();
            foreach ( $termIds as $termId ) {
                $withChildren[] = $termId;
                $children = get_term_children( $termId, $this->getTaxonomy() );
                if ( is_array( $children ) ) {
                    $withChildren = array_merge( $withChildren, array_map( 'intval', $children ) );
                }
            }
            $termIds = array_values( array_unique( $withChildren ) );
        }
        if ( 'ids' === $return ) {
            return $termIds;
        }
        $terms = array();
        foreach ( $termIds as $termId ) {
            $term = get_term( $termId, $this->getTaxonomy() );
            if ( $term instanceof WP_Term ) {
                $terms[$term->term_id] = $term->name;
            }
        }
        return $terms;
    }

    public function filterQuery( ProductQuery $query ) : void {
    }

    /**
     * Selected term ids -> term_taxonomy_ids for the SQL clause.
     * Falls back to array( 0 ) (matches nothing) when none of the terms exist anymore.
     */
    protected function getTermTaxonomyIds( array $termIds ) : array {
        return self::termTaxonomyIds( $this->getTaxonomy(), $termIds );
    }

    public static function termTaxonomyIds( string $taxonomy, array $termIds ) : array {
        $ttIds = get_terms( array(
            'taxonomy'   => $taxonomy,
            'include'    => $termIds,
            'hide_empty' => false,
            'fields'     => 'tt_ids',
        ) );
        if ( is_wp_error( $ttIds ) || empty( $ttIds ) ) {
            return array(0);
        }
        return array_map( 'intval', $ttIds );
    }

    /**
     * Public so that non-TermsFilter filters (e.g. Featured) can push their
     * own term clauses through the same variation-aware WHERE handler.
     */
    public static function registerQueryFilter() : void {
        if ( self::$queryFilterRegistered ) {
            return;
        }
        self::$queryFilterRegistered = true;
        add_filter(
            'posts_where',
            function ( $where, $query ) {
                global $wpdb;
                $clauses = ( isset( $query->query[self::QUERY_VAR] ) ? (array) $query->query[self::QUERY_VAR] : array() );
                foreach ( $clauses as $clause ) {
                    $ttIds = implode( ',', array_map( 'intval', (array) ($clause['tt_ids'] ?? array()) ) );
                    if ( '' === $ttIds ) {
                        continue;
                    }
                    if ( 'include' === ($clause['type'] ?? '') ) {
                        $where .= " AND ( {$wpdb->posts}.ID IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$ttIds}) )";
                        $where .= " OR {$wpdb->posts}.post_parent IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$ttIds}) ) )";
                    } elseif ( 'exclude' === ($clause['type'] ?? '') ) {
                        $where .= " AND {$wpdb->posts}.ID NOT IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$ttIds}) )";
                        $where .= " AND {$wpdb->posts}.post_parent NOT IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$ttIds}) )";
                    }
                }
                return $where;
            },
            10,
            2
        );
    }

    public function getSectionArgs() : array {
        return array(
            'open' => !empty( $this->getIncludedTerms() ) || !empty( $this->getExcludedTerms() ),
        );
    }

}
