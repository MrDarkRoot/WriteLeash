<?php
namespace WriteLeash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

	final class Plugin {
	public static function boot(): void {
		register_activation_hook( WRITELEASH_PLUGIN_FILE, array( Lifecycle::class, 'activate' ) );
		register_deactivation_hook( WRITELEASH_PLUGIN_FILE, array( Lifecycle::class, 'deactivate' ) );
		Redirection_Bulk_Disable_Rest::boot();
		Admin_Page::boot();
		Job_Resume_Rest::boot();
		Undo_Rest::boot();
		// Action Scheduler 4.0.0 executes WriteLeash-owned wake-ups only.
		add_action( Job_Scheduler::HOOK, array( Job_Worker::class, 'callback' ), 10, 1 );
		add_action( Undo_Scheduler::HOOK, array( Undo_Worker::class, 'callback' ), 10, 1 );
		add_action( Undo_Scheduler::PURGE_HOOK, array( Undo_Scheduler::class, 'purge_callback' ), 10, 0 );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( 'writeleash', Product_CLI::class );
		}
	}

	public static function version(): string {
		return WRITELEASH_VERSION;
	}

	// Admin action handlers check this capability on every mutation.
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}
}
