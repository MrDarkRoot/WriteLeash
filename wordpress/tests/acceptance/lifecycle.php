<?php
// Run after the HTTP scale journeys. No direct Woo price writes.
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Undo_Repository as U;
global $wpdb;
$facts = array( 'git_sha' => getenv( 'WL112_SHA' ), 'wp' => get_bloginfo( 'version' ), 'woo' => WC_VERSION, 'php' => PHP_VERSION, 'db' => $wpdb->get_var( 'SELECT VERSION()' ), 'cache' => wp_using_ext_object_cache() ? 'persistent' : 'default' );
$ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}writeleash_jobs" );
deactivate_plugins( 'writeleash/writeleash.php' );
$result = activate_plugin( 'writeleash/writeleash.php', '', false );
if ( is_wp_error( $result ) ) { throw new RuntimeException( 'reactivation failed' ); }
foreach ( $ids as $id ) { if ( ! Repo::read( (int) $id ) ) { throw new RuntimeException( 'reactivation lost history' ); } }
$facts['deactivate_reactivate_history'] = 'PASS';
// Explicit repeated schema install models idempotent current-version migration,
// not an invented legacy package upgrade.
WriteLeash\Job_Schema::install();
WriteLeash\Undo_Schema::install();
$facts['current_schema_idempotent_migration'] = 'PASS';
$wpdb->query( "UPDATE {$wpdb->prefix}writeleash_jobs SET completed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY),updated_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY) WHERE status='COMPLETED'" );
$wpdb->query( "UPDATE {$wpdb->prefix}writeleash_undo_operations SET completed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY),updated_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY) WHERE status='UNDO_COMPLETED'" );
$t = microtime( true );
$facts['purge'] = U::purge_expired( 100 );
$facts['purge_seconds'] = microtime( true ) - $t;
deactivate_plugins( 'writeleash/writeleash.php' );
if ( ! uninstall_plugin( 'writeleash/writeleash.php' ) ) { throw new RuntimeException( 'uninstall failed' ); }
$result = activate_plugin( 'writeleash/writeleash.php', '', false );
if ( is_wp_error( $result ) ) { throw new RuntimeException( 'reinstall failed' ); }
$facts['uninstall_reinstall'] = 'PASS';
file_put_contents( '/evidence/' . DB_HOST . '-lifecycle.json', json_encode( $facts, JSON_PRETTY_PRINT ) . "\n" );
