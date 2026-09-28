<?php
// Shared instrumentation for Gate #85 real-plugin runtime research.
// Captures the MySQL general log for the WordPress connection, every WordPress
// hook fired during the operation window, and attempted mail/HTTP side effects.

function ccr_root() {
	$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', 'mysql' );
	$root->suppress_errors( true );
	return $root;
}

function ccr_trace_start( $label ) {
	global $wpdb;
	$root = ccr_root();
	$GLOBALS['ccr_root']   = $root;
	$GLOBALS['ccr_thread'] = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
	$GLOBALS['ccr_label']  = $label;
	$GLOBALS['ccr_hooks']  = array();
	$GLOBALS['ccr_mail']   = 0;
	$GLOBALS['ccr_http']   = array();

	$GLOBALS['ccr_all'] = function ( $hook ) {
		if ( ! isset( $GLOBALS['ccr_hooks'][ $hook ] ) ) {
			$GLOBALS['ccr_hooks'][ $hook ] = 0;
		}
		$GLOBALS['ccr_hooks'][ $hook ]++;
	};
	add_action( 'all', $GLOBALS['ccr_all'] );
	add_filter( 'pre_wp_mail', function () {
		$GLOBALS['ccr_mail']++;
		return true;
	}, 999 );
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
		$GLOBALS['ccr_http'][] = $url;
		return new WP_Error( 'ccr_blocked', 'blocked by research harness' );
	}, 999, 3 );

	$root->query( 'SET GLOBAL general_log = 0' );
	$root->query( 'TRUNCATE TABLE mysql.general_log' );
	$root->query( "SET GLOBAL log_output = 'TABLE'" );
	$root->query( 'SET GLOBAL general_log = 1' );
	$wpdb->query( "SELECT 'CCR_TRACE_START'" );
}

function ccr_trace_stop() {
	global $wpdb;
	$root = $GLOBALS['ccr_root'];
	$wpdb->query( "SELECT 'CCR_TRACE_END'" );
	$root->query( 'SET GLOBAL general_log = 0' );
	remove_action( 'all', $GLOBALS['ccr_all'] );

	$order = 'event_time';
	$columns = $root->get_col( "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mysql' AND TABLE_NAME = 'general_log'" );
	if ( is_array( $columns ) && in_array( 'event_time_usec', $columns, true ) ) {
		$order .= ', event_time_usec';
	}
	$rows = $root->get_results( $root->prepare(
		"SELECT argument FROM mysql.general_log WHERE thread_id = %d AND command_type = 'Query' ORDER BY $order",
		$GLOBALS['ccr_thread']
	) );

	$inside = false;
	$sql    = array();
	foreach ( (array) $rows as $row ) {
		$arg = (string) $row->argument;
		if ( false !== strpos( $arg, 'CCR_TRACE_START' ) ) {
			$inside = true;
			continue;
		}
		if ( false !== strpos( $arg, 'CCR_TRACE_END' ) ) {
			break;
		}
		if ( $inside ) {
			$sql[] = preg_replace( '/\s+/', ' ', $arg );
		}
	}

	echo "--- TRACE {$GLOBALS['ccr_label']} (thread {$GLOBALS['ccr_thread']}) ---\n";
	foreach ( $sql as $statement ) {
		echo 'SQL: ' . $statement . "\n";
	}
	echo 'HOOKS: ' . json_encode( $GLOBALS['ccr_hooks'] ) . "\n";
	echo 'MAIL: ' . $GLOBALS['ccr_mail'] . "\n";
	echo 'HTTP: ' . json_encode( $GLOBALS['ccr_http'] ) . "\n";
	echo "--- END TRACE {$GLOBALS['ccr_label']} ---\n";
}

function ccr_table_verbs( $root, $prefix ) {
	$rows = $root->get_results( $root->prepare(
		"SELECT argument FROM mysql.general_log WHERE thread_id = %d AND command_type = 'Query'",
		$GLOBALS['ccr_thread']
	) );
	$verbs = array();
	foreach ( (array) $rows as $row ) {
		$sql = preg_replace( '/\s+/', ' ', (string) $row->argument );
		if ( preg_match( '/\A(INSERT|REPLACE|UPDATE|DELETE|TRUNCATE|SELECT|START TRANSACTION|COMMIT|ROLLBACK)\b/i', trim( $sql ), $m ) ) {
			$verb = strtoupper( $m[1] );
			if ( false !== strpos( $sql, $prefix ) ) {
				if ( ! isset( $verbs[ $verb ] ) ) {
					$verbs[ $verb ] = 0;
				}
				$verbs[ $verb ]++;
			}
		}
	}
	echo 'TABLE VERBS (' . $prefix . '): ' . json_encode( $verbs ) . "\n";
}
