<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lifecycle {
	public static function activate( bool $network_wide = false ): void {
		// Network-wide behavior is not validated; do not create partial per-site state.
		if ( $network_wide ) {
			wp_die( 'CommitCap network activation has not been validated.' );
		}
		if ( '' === Environment::wordpress_version() || ! function_exists( 'get_option' ) ) {
			wp_die( 'CommitCap requires WordPress to be loaded.' );
		}
		if ( version_compare( Environment::php_version(), '7.4', '<' ) ) {
			wp_die( 'CommitCap requires PHP 7.4 or newer.' );
		}
		if ( ! Environment::has_wpdb() ) {
			wp_die( 'CommitCap requires a WordPress database connection.' );
		}
		if ( '' === Environment::database_version() ) {
			wp_die( 'CommitCap could not read the database server version.' );
		}

		// This is the only persistent state created by the plugin foundation.
		if ( COMMITCAP_VERSION === get_option( 'commitcap_version' ) ) {
			return;
		}
		if ( ! update_option( 'commitcap_version', COMMITCAP_VERSION, false ) ) {
			wp_die( 'CommitCap could not save its version metadata.' );
		}
	}

	public static function deactivate(): void {
		// No runtime state or DB objects are installed by this foundation.
	}
}
