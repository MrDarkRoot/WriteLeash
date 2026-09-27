<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	public static function boot(): void {
		register_activation_hook( COMMITCAP_PLUGIN_FILE, array( Lifecycle::class, 'activate' ) );
		register_deactivation_hook( COMMITCAP_PLUGIN_FILE, array( Lifecycle::class, 'deactivate' ) );
	}

	public static function version(): string {
		return COMMITCAP_VERSION;
	}

	// Future admin-only hooks must check capability before acting.
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}
}
