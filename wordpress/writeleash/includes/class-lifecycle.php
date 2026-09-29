<?php
namespace WriteLeash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lifecycle {
	public static function activate( bool $network_wide = false ): void {
		// Network-wide behavior is not validated; do not create partial per-site state.
		if ( $network_wide ) {
			wp_die( 'WriteLeash network activation has not been validated.' );
		}
		if ( '' === Environment::wordpress_version() || ! function_exists( 'get_option' ) ) {
			wp_die( 'WriteLeash requires WordPress to be loaded.' );
		}
		if ( version_compare( Environment::php_version(), '7.4', '<' ) ) {
			wp_die( 'WriteLeash requires PHP 7.4 or newer.' );
		}
		if ( ! Environment::has_wpdb() ) {
			wp_die( 'WriteLeash requires a WordPress database connection.' );
		}
		if ( '' === Environment::database_version() ) {
			wp_die( 'WriteLeash could not read the database server version.' );
		}

		// This is the only persistent state created by the plugin foundation.
		if ( WRITELEASH_VERSION === get_option( 'writeleash_version' ) ) {
			return;
		}
		if ( ! update_option( 'writeleash_version', WRITELEASH_VERSION, false ) ) {
			wp_die( 'WriteLeash could not save its version metadata.' );
		}
	}

	public static function deactivate(): void {
		// No runtime state or DB objects are installed by this foundation.
	}
}
