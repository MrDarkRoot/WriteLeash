<?php
/**
 * Front controller for PHP's built-in server.
 *
 * A normal Apache or nginx site rewrites /wp-json/ to index.php. The built-in
 * server does not. This router only supplies that rewrite, and copies a
 * visible Authorization header through, so application-password basic auth
 * reaches WordPress. It is not part of WriteLeash and it does not decide
 * whether a write is allowed.
 */
if ( empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
	if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) && is_string( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		$_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
	} elseif ( function_exists( 'getallheaders' ) ) {
		$headers = getallheaders();
		if ( is_array( $headers ) ) {
			foreach ( $headers as $name => $value ) {
				if ( is_string( $name ) && 0 === strcasecmp( $name, 'Authorization' ) && is_string( $value ) ) {
					$_SERVER['HTTP_AUTHORIZATION'] = $value;
					break;
				}
			}
		}
	}
}
$root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) $_SERVER['DOCUMENT_ROOT'] : getcwd();
$path = parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );
$path = is_string( $path ) ? rawurldecode( $path ) : '/';
$file = rtrim( $root, '/' ) . $path;
if ( '/' !== $path && is_file( $file ) ) {
	return false;
}
require rtrim( $root, '/' ) . '/index.php';
