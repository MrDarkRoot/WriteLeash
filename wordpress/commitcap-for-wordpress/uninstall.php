<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) {
	exit;
}

// WordPress uninstall removes only CommitCap-owned local WordPress state.
// Trusted database objects, grants, triggers, routines and runtime accounts
// are intentionally left to the explicit operator lifecycle (PROVISIONING.md).
delete_option( 'commitcap_version' );
delete_option( 'commitcap_certified_operation_state' );
delete_option( 'commitcap_last_certified_outcome' );
// Stale #87 budget option; not an authority since #78 but removed if present.
delete_option( 'commitcap_operation_budget_redirection_5_5_2_bulk_disable' );
