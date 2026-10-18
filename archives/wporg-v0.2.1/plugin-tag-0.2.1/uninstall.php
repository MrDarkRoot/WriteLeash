<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) {
	exit;
}

// An inactive plugin's uninstall request may not have registered its init hook.
global $wp_textdomain_registry, $l10n;
$wp_textdomain_registry->set_custom_path( 'writeleash', __DIR__ . '/languages' );
if ( isset( $l10n['writeleash'] ) && $l10n['writeleash'] instanceof \NOOP_Translations ) { unset( $l10n['writeleash'] ); }

// WordPress uninstall removes only WriteLeash-owned local WordPress state.
// Durable journal/job/Undo evidence is retained; no product prices are changed.
//
// #110 uninstall lifecycle order:
//   1. durably fail-close the runner gate by deleting the exact lifecycle
//      options below: a missing `writeleash_runner_state` is NOT active, so a
//      surviving worker stops before its next claim (the in-flight item
//      boundary still completes under the reviewed #108/#109/#110 fence);
//   2. cancel only WriteLeash-owned Action Scheduler wake-ups (wake-up layer;
//      never mutation authority);
//   3. durable Free job/journal/Undo tables are retained (no DDL here), so no
//      surviving worker can lose its evidence while still mutating.
// Action Scheduler remains a scheduler: if its public API is unavailable the
// cleanup is skipped safely and the fail-closed runner gate still stops new
// claims. No Action Scheduler table is dropped, truncated or deleted.

// Shutdown must be the FIRST write. delete_option() issues the indexed DELETE
// against the very record locked by an in-flight claim/item transaction. It
// waits for that transaction to finish; once deleted, later locking reads
// find no active row and refuse. A missing row is already fail-closed. Check
// DB truth independently because a stale WP option cache could make
// delete_option() return without issuing a DELETE.
global $wpdb;
delete_option( 'writeleash_runner_state' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Independent uncached DB verification of the safety-critical shutdown; the option cache can be stale across processes.
$writeleash_state = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s LIMIT 1', $wpdb->options, 'writeleash_runner_state' ) );
if ( '' !== (string) $wpdb->last_error || 'active' === $writeleash_state ) {
	wp_die( esc_html__( 'WriteLeash uninstall could not establish the runner shutdown boundary.', 'writeleash' ) );
}

// Exact-name deletion only: no wildcard or LIKE-based option cleanup runs here.
delete_option( 'writeleash_version' );
// Historical exact-name local option cleanup only. These values are never
// read or migrated by Free and cannot load or enable any historical runtime.
delete_option( 'writeleash_certified_operation_state' );
delete_option( 'writeleash_last_certified_outcome' );
// Stale #87 writeleash budget option; not an authority since #78 but removed if present.
delete_option( 'writeleash_operation_budget_redirection_5_5_2_bulk_disable' );
// #109 small schema/lifecycle metadata. Durable job tables are retained for
// the operator lifecycle. No DDL runs here, so uninstall never drops or
// rewrites job evidence; deleting writeleash_runner_state is the durable
// fail-closed shutdown signal.
delete_option( 'writeleash_job_schema' );
delete_option( 'writeleash_job_setup' );
// #110 Undo/history/retention metadata, deleted by exact name only. Durable
// Undo tables are retained under the same operator lifecycle as #109: the
// reviewed uninstall SQL classifier forbids DDL here, so no surviving worker
// can lose its Undo journal while still holding mutation authority, and no
// Woo product row is ever touched. Retention expiry (not uninstall)
// is what honestly disables Restore.
delete_option( 'writeleash_undo_schema' );
delete_option( 'writeleash_undo_setup' );

// Runner authority is now durably fail-closed. Wake-up cleanup is
// defense-in-depth only and must never be required for safety.
$writeleash_scheduler_groups = array(
	'writeleash-jobs',        // Job_Scheduler::GROUP
	'writeleash-undo',        // Undo_Scheduler::GROUP
	'writeleash-maintenance', // Undo_Scheduler::PURGE_GROUP
);
if ( class_exists( '\ActionScheduler' ) && method_exists( '\ActionScheduler', 'is_initialized' ) && \ActionScheduler::is_initialized() ) {
	try {
		$writeleash_store = \ActionScheduler::store();
		foreach ( $writeleash_scheduler_groups as $writeleash_group ) {
			$writeleash_store->cancel_actions_by_group( $writeleash_group );
		}
	} catch ( \Throwable $writeleash_error ) {
		// Scheduler cleanup is best effort and defense-in-depth only; the
		// durable fail-closed runner gate above is the actual authority.
	}
	unset( $writeleash_store, $writeleash_group );
}
unset( $writeleash_scheduler_groups );

// Pre-release development cleanup (not compatibility support). These four
// older, exact option names were never a released product state: they are
// deleted by exact name without being read, never migrated and never used as
// authority. The unfriendly-looking literals below are intentional.
delete_option( 'commitcap_version' );
delete_option( 'commitcap_certified_operation_state' );
delete_option( 'commitcap_last_certified_outcome' );
delete_option( 'commitcap_operation_budget_redirection_5_5_2_bulk_disable' );
