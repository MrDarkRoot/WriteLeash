<?php
// Shared helpers for the #87 Redirection 5.5.2 Bulk Disable adapter suite.
// Executed by WP-CLI after WordPress, WriteLeash and Redirection are loaded.

function cc87_assert( $value, $label ) {
	if ( ! $value ) {
		throw new RuntimeException( 'ASSERTION FAILED: ' . $label );
	}
}

function cc87_query( $db, $sql ) {
	$res = $db->query( $sql );
	cc87_assert( false !== $res, 'SQL failed: ' . $sql . ': ' . $db->last_error );
	return $res;
}

/** True total/disabled durable counts from the independent trusted observer. */
function cc87_counts( $root ) {
	$row = $root->get_row( "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'disabled'), 0) AS disabled FROM wp_redirection_items", ARRAY_A );
	cc87_assert( is_array( $row ), 'observer count failed: ' . $root->last_error );
	return array( (int) $row['total'], (int) $row['disabled'] );
}

/**
 * All general-log Query statements in the run window grouped by thread id.
 * Used for REST end-to-end proofs where the WriteLeash runtime connection is
 * opened internally by the plugin and its thread id is not known up front.
 *
 * @return array{0: mixed, 1: array<int, array<int, string>>}
 */
function cc87_trace_all( $root, callable $invoke ) {
	cc87_query( $root, 'SET GLOBAL general_log = 0' );
	cc87_query( $root, 'TRUNCATE TABLE mysql.general_log' );
	cc87_query( $root, "SET GLOBAL log_output = 'TABLE'" );
	cc87_query( $root, 'SET GLOBAL general_log = 1' );

	try {
		$result = $invoke();
	} finally {
		cc87_query( $root, 'SET GLOBAL general_log = 0' );
	}

	$rows = $root->get_results(
		"SELECT thread_id, argument FROM mysql.general_log WHERE command_type = 'Query'",
		ARRAY_A
	);
	$by_thread = array();
	foreach ( (array) $rows as $row ) {
		$sql = preg_replace( '/\s+/', ' ', trim( (string) $row['argument'] ) );
		if ( '' === $sql || preg_match( "/^SELECT 'CC87_TRACE_(START|END)'$/i", $sql ) ) {
			continue;
		}
		$by_thread[ (int) $row['thread_id'] ][] = $sql;
	}
	return array( $result, $by_thread );
}

/**
 * All general-log Query statements for one connection in the run window,
 * whitespace-normalized. The general log is authoritative at the DB level: it
 * sees prepared statements as sent, including direct mysqli probes.
 *
 * The log is truncated immediately before the window, and the traced
 * connection is idle outside the window, so every captured row belongs to the
 * run. Rows are not ordered: the MySQL fixture's general_log has only
 * one-second event_time resolution and no usec column, so sentinel/time
 * ordering is unreliable. CC87_TRACE sentinels are excluded by exact shape.
 */
function cc87_trace( $root, $thread, callable $invoke ) {
	cc87_query( $root, 'SET GLOBAL general_log = 0' );
	cc87_query( $root, 'TRUNCATE TABLE mysql.general_log' );
	cc87_query( $root, "SET GLOBAL log_output = 'TABLE'" );
	cc87_query( $root, 'SET GLOBAL general_log = 1' );

	try {
		$result = $invoke();
	} finally {
		cc87_query( $root, 'SET GLOBAL general_log = 0' );
	}

	$rows = $root->get_col( $root->prepare(
		"SELECT argument FROM mysql.general_log WHERE thread_id = %d AND command_type = 'Query'",
		(int) $thread
	) );

	$statements = array();
	foreach ( (array) $rows as $argument ) {
		$sql = preg_replace( '/\s+/', ' ', trim( (string) $argument ) );
		if ( preg_match( "/^SELECT 'CC87_TRACE_(START|END)'$/i", $sql ) ) {
			continue;
		}
		if ( '' !== $sql ) {
			$statements[] = $sql;
		}
	}
	return array( $result, $statements );
}

/**
 * Connection-isolation gate: every statement that reached the restricted
 * connection must be reviewed WriteLeash evidence SQL or the exact reviewed
 * Redirection target-table SQL. Destructive verbs and any other wp_ table
 * reference fail closed.
 */
function cc87_assert_isolated( array $statements, $label, $expect_plugin_update = true ) {
	$allowed = array(
		'/^SELECT VERSION\(\)$/i',
		'/^SELECT @@[A-Za-z0-9_.]+$/i',
		'/^SELECT CURRENT_USER\(\)$/i',
		'/^SELECT USER\(\)$/i',
		'/^SELECT DATABASE\(\)$/i',
		'/^SELECT CONNECTION_ID\(\)$/i',
		"/^SELECT SUBSTRING_INDEX\\(USER\\(\\), '@', 1\\)$/i",
		'/^SELECT 1$/i',
		"/^SELECT 'CC87_TRACE_(START|END)'$/i",
		'/^SELECT @[A-Za-z0-9_]+$/i',
		'/^SHOW GRANTS/i',
		'/^CALL writeleash_v01_(open|close|count|policy|attest)\(/i',
		'/^SELECT .+ FROM information_schema\.[A-Z_]+/i',
		'/^SELECT .+ FROM writeleash_v01_state/i',
		'/^SELECT .+ FROM `?wp_redirection_items`?/i',
		'/^SET @writeleash_v01_denied/i',
		"/^SET NAMES '?[A-Za-z0-9_]+'?( COLLATE '?[A-Za-z0-9_]+'?)?$/i",
		"/^SET SESSION sql_mode='[^']*'$/i",
		'/^START TRANSACTION$/i',
		'/^COMMIT$/i',
		'/^ROLLBACK$/i',
		'/^SAVEPOINT [A-Za-z0-9_`]+$/i',
		'/^ROLLBACK TO SAVEPOINT [A-Za-z0-9_`]+$/i',
		'/^RELEASE SAVEPOINT [A-Za-z0-9_`]+$/i',
		'/^UPDATE `?wp_redirection_items`? SET `?[a-z_]+`? = `?[a-z_]+`? LIMIT 1$/i',
		"/^UPDATE wp_redirection_items SET status='disabled'$/i",
		// Executed by the reviewed BEFORE UPDATE trigger inside the server, one
		// statement per accounted row event, plus its physical denial signal.
		"/^UPDATE writeleash_v01_state SET consumed = consumed \\+ 1 WHERE connection_id = CONNECTION_ID\\(\\) AND policy_id = '[a-f0-9]{64}' AND consumed < [0-9]+$/i",
		"/^SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 1644, MESSAGE_TEXT = 'CC54_DENIED'$/i",
	);
	$plugin_updates = 0;
	foreach ( $statements as $sql ) {
		$matched = false;
		foreach ( $allowed as $pattern ) {
			if ( preg_match( $pattern, $sql ) ) {
				$matched = true;
				break;
			}
		}
		cc87_assert( $matched, $label . ' unexpected SQL on restricted connection: ' . $sql );
		cc87_assert(
			! preg_match( '/\b(INSERT|DELETE|REPLACE|DROP|ALTER|CREATE|GRANT|REVOKE|TRUNCATE|RENAME)\b/i', $sql ),
			$label . ' destructive SQL on restricted connection: ' . $sql
		);
		if ( preg_match_all( '/\bwp_[A-Za-z0-9_]+/i', $sql, $matches ) ) {
			foreach ( $matches[0] as $name ) {
				cc87_assert(
					in_array( strtolower( $name ), array( 'wp_redirection_items', 'wp_test' ), true ),
					$label . ' unexpected table reference on restricted connection: ' . $sql
				);
			}
		}
		if ( preg_match( "/^UPDATE wp_redirection_items SET status='disabled'$/i", $sql ) ) {
			++$plugin_updates;
		}
	}
	if ( $expect_plugin_update ) {
		cc87_assert( 1 === $plugin_updates, $label . ' expected exactly one plugin UPDATE, observed ' . $plugin_updates . '; statements=' . json_encode( $statements ) );
	} else {
		cc87_assert( 0 === $plugin_updates, $label . ' plugin UPDATE ran although the callback must not execute; statements=' . json_encode( $statements ) );
	}
}

/** Count the reviewed global Disable UPDATE and the threads that ran it. */
function cc87_disable_updates( array $by_thread ): array {
	$count   = 0;
	$threads = array();
	foreach ( $by_thread as $thread => $statements ) {
		foreach ( $statements as $sql ) {
			if ( preg_match( "/^UPDATE wp_redirection_items SET status='disabled'$/i", $sql ) ) {
				++$count;
				$threads[] = (int) $thread;
			}
		}
	}
	return array( $count, array_values( array_unique( $threads ) ) );
}

/** Adapter/Doctor/Guard markers proving the certified path ran on a thread. */
function cc87_adapter_statements( array $by_thread ): array {
	$found = array();
	foreach ( $by_thread as $thread => $statements ) {
		foreach ( $statements as $sql ) {
			if ( preg_match( '/^CALL writeleash_v01_/i', $sql ) || preg_match( "/^SELECT SUBSTRING_INDEX\\(USER\\(\\), '@', 1\\)$/i", $sql ) ) {
				$found[] = array( (int) $thread, $sql );
			}
		}
	}
	return $found;
}

/** REST request matching Redirection's own bulk action call shape. */
function cc87_rest_bulk_request( $action, array $extra = array() ) {
	$request = new WP_REST_Request( 'POST', '/redirection/v1/bulk/redirect/' . $action );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$request->set_body_params( array_merge( array( 'bulk' => $action ), $extra ) );
	return $request;
}

/** Bulk-seed $count enabled redirects directly (large-N fixtures only). */
function cc87_seed_bulk( $root, $count ) {
	cc87_query( $root, 'DELETE FROM wp_redirection_items' );
	$values = array();
	for ( $i = 0; $i < $count; ++$i ) {
		$values[] = "('/cc87-bulk-" . $i . "','url',301,'url')";
		if ( 500 === count( $values ) ) {
			cc87_query( $root, 'INSERT INTO wp_redirection_items (url, action_type, action_code, match_type) VALUES ' . implode( ',', $values ) );
			$values = array();
		}
	}
	if ( $values ) {
		cc87_query( $root, 'INSERT INTO wp_redirection_items (url, action_type, action_code, match_type) VALUES ' . implode( ',', $values ) );
	}
	$counts = cc87_counts( $root );
	cc87_assert( $count === $counts[0] && 0 === $counts[1], 'bulk seed state is not all-enabled: ' . json_encode( $counts ) );
}

/** The normal WordPress connection must be restored and still be the same identity. */
function cc87_assert_normal( $normal, $normal_id, $label ) {
	cc87_assert( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] === $normal, $label . ' global $wpdb was not restored' );
	cc87_assert( (int) $normal->get_var( 'SELECT CONNECTION_ID()' ) === $normal_id, $label . ' normal connection identity changed' );
	cc87_assert( 'wp_test' === $normal->get_var( 'SELECT DATABASE()' ), $label . ' normal connection schema changed' );
}

/**
 * Seed $count enabled redirects through the plugin's own model API.
 *
 * INSERT is used because an installed #54 physical policy trigger denies every
 * unguarded UPDATE to the protected table - including writes from the normal
 * WordPress connection. Seeding therefore never depends on an UPDATE.
 */
function cc87_seed( $root, $count ) {
	cc87_query( $root, 'DELETE FROM wp_redirection_items' );
	$group_id = (int) $root->get_var( 'SELECT id FROM wp_redirection_groups ORDER BY id LIMIT 1' );
	if ( $group_id < 1 ) {
		$group = Red_Group::create( 'CC87', 1 );
		cc87_assert( false !== $group && $group instanceof Red_Group, 'seed group create failed' );
		$group_id = (int) $group->get_id();
	}
	for ( $i = 0; $i < $count; ++$i ) {
		$item = Red_Item::create( array(
			'url'         => '/cc87-' . $i,
			'action_data' => 'https://example.test/cc87-' . $i,
			'action_type' => 'url',
			'action_code' => 301,
			'match_type'  => 'url',
			'group_id'    => $group_id,
			'status'      => 'enabled',
			'regex'       => 0,
		) );
		cc87_assert( ! is_wp_error( $item ), 'seed item create failed: ' . ( is_wp_error( $item ) ? $item->get_error_message() : '?' ) );
	}
	$counts = cc87_counts( $root );
	cc87_assert( $count === $counts[0] && 0 === $counts[1], 'seed state is not all-enabled: ' . json_encode( $counts ) );
}

/** The runtime account must hold no leftover accounting rows after a run. */
function cc87_assert_no_accounting( $root, $runtime_id, $label ) {
	$rows = (int) $root->get_var( $root->prepare( 'SELECT COUNT(*) FROM writeleash_v01_state WHERE connection_id = %d', $runtime_id ) );
	cc87_assert( 0 === $rows, $label . ' left accounting state behind' );
}

function cc87_expect( array $result, $outcome, $reason, $kind, $label ) {
	cc87_assert( 'redirection-5.5.2-bulk-disable-global' === $result['operation_id'], $label . ' wrong operation id' );
	cc87_assert( $outcome === $result['outcome'], $label . ' outcome: ' . json_encode( $result ) );
	cc87_assert( $reason === $result['reason'], $label . ' reason: ' . json_encode( $result['reason'] ) );
	cc87_assert( $kind === $result['denial_kind'], $label . ' denial_kind: ' . json_encode( $result['denial_kind'] ) );
}
