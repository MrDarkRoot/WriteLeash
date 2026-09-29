<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

	final class Plugin {
	public static function boot(): void {
		register_activation_hook( COMMITCAP_PLUGIN_FILE, array( Lifecycle::class, 'activate' ) );
		register_deactivation_hook( COMMITCAP_PLUGIN_FILE, array( Lifecycle::class, 'deactivate' ) );
		Redirection_Bulk_Disable_Rest::boot();
		Admin_Page::boot();
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( 'commitcap', Product_CLI::class );
		}
	}

	public static function version(): string {
		return COMMITCAP_VERSION;
	}

	// Admin action handlers check this capability on every mutation.
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}
}
