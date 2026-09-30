<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) {
	exit;
}

// WordPress uninstall removes only WriteLeash-owned local WordPress state.
// Trusted database objects, grants, triggers, routines and runtime accounts
// are intentionally left to the explicit operator lifecycle (PROVISIONING.md).
//
// Exact-name deletion only: no wildcard or LIKE-based option cleanup runs here.
delete_option( 'writeleash_version' );
delete_option( 'writeleash_certified_operation_state' );
delete_option( 'writeleash_last_certified_outcome' );
// Stale #87 writeleash budget option; not an authority since #78 but removed if present.
delete_option( 'writeleash_operation_budget_redirection_5_5_2_bulk_disable' );
// #109 small schema/lifecycle metadata. Durable job tables and any
// already-scheduled Action Scheduler rows are retained for the operator
// lifecycle; deactivation is what cancels WriteLeash-owned wake-ups. No DDL
// runs here, so uninstall never drops or rewrites job evidence.
delete_option( 'writeleash_job_schema' );
delete_option( 'writeleash_job_setup' );
delete_option( 'writeleash_runner_state' );
// #110 Undo/history/retention metadata, deleted by exact name only. Durable
// Undo tables are retained under the same operator lifecycle as #109: the
// reviewed uninstall SQL classifier forbids DDL here, so no surviving worker
// can lose its Undo journal while still holding mutation authority, and no
// Woo product row is ever touched. Retention expiry (not uninstall)
// is what honestly disables Restore.
delete_option( 'writeleash_undo_schema' );
delete_option( 'writeleash_undo_setup' );

// Pre-release development cleanup (not compatibility support). These four
// older, exact option names were never a released product state: they are
// deleted by exact name without being read, never migrated and never used as
// authority. The unfriendly-looking literals below are intentional.
delete_option( 'commitcap_version' );
delete_option( 'commitcap_certified_operation_state' );
delete_option( 'commitcap_last_certified_outcome' );
delete_option( 'commitcap_operation_budget_redirection_5_5_2_bulk_disable' );
