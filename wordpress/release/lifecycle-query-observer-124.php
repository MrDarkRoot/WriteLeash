<?php
// Repository-only WP-CLI observer. Persist counts, never query text or credentials.
WP_CLI::add_hook( 'after_wp_load', static function () {
    $counts = array( 'DDL_DCL' => 0, 'PRODUCT_DML' => 0 );
    add_filter( 'query', static function ( $query ) use ( &$counts ) {
        global $wpdb;
        if ( preg_match( '/^\s*(?:CREATE|ALTER|DROP|TRUNCATE|GRANT|REVOKE)\b/i', $query ) ) { ++$counts['DDL_DCL']; }
        if ( preg_match( '/^\s*(?:UPDATE|DELETE|INSERT|REPLACE)\b/i', $query ) ) {
            foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->wc_product_meta_lookup ) as $table ) {
                if ( preg_match( '/(?<![A-Za-z0-9_])`?' . preg_quote( $table, '/' ) . '`?(?![A-Za-z0-9_])/', $query ) ) { ++$counts['PRODUCT_DML']; break; }
            }
        }
        return $query;
    } );
    register_shutdown_function( static function () use ( &$counts ) { echo 'LIFECYCLE_QUERY_COUNTS=' . json_encode( $counts ) . "\n"; } );
} );
