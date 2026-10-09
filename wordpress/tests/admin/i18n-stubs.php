<?php
// Standalone model tests only. Runtime/browser tests use real WordPress gettext.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return ( 'writeleash' === $domain ? ( $GLOBALS['wl210_prefix'] ?? '' ) : '' ) . $text; }
	function _x( $text, $context, $domain = 'default' ) { return __( $text, $domain ); }
	function _n( $single, $plural, $number, $domain = 'default' ) { return __( 1 === (int) $number ? $single : $plural, $domain ); }
	function esc_html__( $text, $domain = 'default' ) { return esc_html( __( $text, $domain ) ); }
	function esc_attr__( $text, $domain = 'default' ) { return esc_attr( __( $text, $domain ) ); }
}
