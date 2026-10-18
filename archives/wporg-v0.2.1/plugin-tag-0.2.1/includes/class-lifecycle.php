<?php
namespace WriteLeash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lifecycle {
	public static function activate( bool $network_wide = false ): void {
		// Network-wide behavior is not validated; do not create partial per-site state.
		if ( $network_wide ) {
			wp_die( esc_html__( 'WriteLeash network activation has not been validated.', 'writeleash' ) );
		}
		if ( '' === Environment::wordpress_version() || ! function_exists( 'get_option' ) ) {
			wp_die( esc_html__( 'WriteLeash requires WordPress to be loaded.', 'writeleash' ) );
		}
		if ( version_compare( Environment::php_version(), '7.4', '<' ) ) {
			wp_die( esc_html__( 'WriteLeash requires PHP 7.4 or newer.', 'writeleash' ) );
		}
		if ( ! Environment::has_wpdb() ) {
			wp_die( esc_html__( 'WriteLeash requires a WordPress database connection.', 'writeleash' ) );
		}
		if ( '' === Environment::database_version() ) {
			wp_die( esc_html__( 'WriteLeash could not read the database server version.', 'writeleash' ) );
		}

		// This is the only persistent state created by the plugin foundation.
		if ( WRITELEASH_VERSION !== get_option( 'writeleash_version' ) ) {
			if ( ! update_option( 'writeleash_version', WRITELEASH_VERSION, false ) ) {
				wp_die( esc_html__( 'WriteLeash could not save its version metadata.', 'writeleash' ) );
			}
		}
		// Activation never creates job tables: plugin-owned durable schema is
		// installed idempotently on first job/setup use. Reactivation only
		// reconciles stale leases; it never mutates products.
		update_option( 'writeleash_runner_state', 'active', false );
		self::reconcile_stale_jobs();
	}

	public static function deactivate(): void {
		// Durable truth is retained: jobs, applied item facts and Undo
		// operations/items stay readable. No new apply or Undo claim may
		// start; any in-flight item completes only at its transactional
		// fence boundary.
		try { Runner_Authority::deactivate(); }
		catch ( \Throwable $error ) { wp_die( esc_html__( 'WriteLeash could not establish the deactivation boundary.', 'writeleash' ) ); }
		try {
			Job_Scheduler::unschedule_all();
		} catch ( \Throwable $error ) {
			// A scheduler that is not loaded cannot own scheduled callbacks anyway.
		}
		try {
			Undo_Scheduler::unschedule_all();
		} catch ( \Throwable $error ) {
			// A scheduler that is not loaded cannot own scheduled callbacks anyway.
		}
	}

	/** Inspect active/paused jobs and Undo operations; release dead leases without touching products. */
	private static function reconcile_stale_jobs(): void {
		try {
			if ( ! function_exists( 'get_option' ) ) { return; }
			global $wpdb;
			// Best-effort lease recovery: standard wpdb subclasses and
			// drop-ins are ordinary supported setups, not a reason to skip.
			if ( ! ( $wpdb instanceof \wpdb ) || ! Job_Schema::ready( $wpdb ) ) { return; }
			Job_Repository::reap_stalled_leases();
			if ( Undo_Schema::ready( $wpdb ) ) { Undo_Repository::reap_stalled_leases(); }
		} catch ( \Throwable $error ) {
			// Activation must remain usable; the workers re-check schema before any claim.
		}
	}
}
