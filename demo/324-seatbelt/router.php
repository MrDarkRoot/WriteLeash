<?php
/**
 * Front controller for PHP's built-in server.
 *
 * A normal Apache or nginx site rewrites /wp-json/ to index.php. The built-in
 * server does not. This router only supplies that rewrite for the disposable
 * demo so PUT /wp-json/wc/v3/products/{id} reaches WordPress. It is not part
 * of WriteLeash and it does not decide whether the write is allowed.
 */
$root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) $_SERVER['DOCUMENT_ROOT'] : getcwd();
$path = parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );
$path = is_string( $path ) ? rawurldecode( $path ) : '/';
$file = rtrim( $root, '/' ) . $path;
if ( '/' !== $path && is_file( $file ) ) {
	return false;
}
require rtrim( $root, '/' ) . '/index.php';
