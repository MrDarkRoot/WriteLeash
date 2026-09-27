<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Raw facts only; compatibility decisions belong to #57. */
final class Environment {
	public static function wordpress_version(): string {
		global $wp_version;
		return is_string( $wp_version ) ? $wp_version : '';
	}

	public static function php_version(): string {
		return PHP_VERSION;
	}

	public static function has_wpdb(): bool {
		global $wpdb;
		return $wpdb instanceof \wpdb;
	}

	public static function database_version(): string {
		global $wpdb;
		if ( ! self::has_wpdb() ) {
			return '';
		}
		try {
			$version = $wpdb->db_version();
		} catch ( \Throwable $error ) {
			return '';
		}
		return is_string( $version ) ? trim( $version ) : '';
	}
}
