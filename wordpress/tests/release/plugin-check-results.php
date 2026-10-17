<?php
// Classify official Plugin Check findings after #63/#64; unknown findings fail.
if ( 4 !== $argc || ! in_array( $argv[1], array( 'mysql', 'mariadb' ), true ) ) {
	throw new RuntimeException( 'Usage: plugin-check-results.php engine exit-code result.json' );
}
$json = file_get_contents( $argv[3] );
// WP-CLI's JSON format prints one JSON array per "FILE: path" block.
$blocks = preg_split( '/^FILE: ([^\r\n]+)\r?\n/m', trim( $json ), -1, PREG_SPLIT_DELIM_CAPTURE );
if ( count( $blocks ) < 3 || '' !== trim( $blocks[0] ) ) {
	throw new RuntimeException( 'Unrecognized Plugin Check format: ' . substr( $json, 0, 400 ) );
}
$data = array();
for ( $i = 1; $i < count( $blocks ); $i += 2 ) {
	$block = $blocks[ $i + 1 ];
	$items = json_decode( trim( $block ), true );
	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $items ) || ! array_is_list( $items ) ) {
		throw new RuntimeException( 'Unrecognized Plugin Check JSON block: ' . substr( $block, 0, 400 ) );
	}
	foreach ( $items as $item ) {
		$item['file'] = $blocks[ $i ];
		$data[] = $item;
	}
}
$failures = array();
$reviewed = array();
$warnings = 0;
$errors = 0;
foreach ( $data as $item ) {
	if ( ! is_array( $item ) || ! isset( $item['code'], $item['type'] ) || ! is_string( $item['code'] ) || ! is_string( $item['type'] ) ) {
		throw new RuntimeException( 'Plugin Check result lacks stable code/type fields' );
	}
	$code = $item['code'];
	$type = strtoupper( $item['type'] );
	if ( str_contains( $type, 'ERROR' ) ) {
		++$errors;
	} elseif ( str_contains( $type, 'WARNING' ) ) {
		++$warnings;
	} else {
		throw new RuntimeException( 'Unexpected Plugin Check result type: ' . $type );
	}
	$path = $item['file'];
	$source = file_get_contents( dirname( $argv[3] ) . '/cc87-' . $argv[1] . '/wp-content/plugins/writeleash/' . $path );
	$lines = is_string( $source ) ? explode( "\n", $source ) : array();

	// PCP's escape sniff treats thrown exception constructors as HTML output.
	// They are NOT echoed; Admin/REST/CLI paths are exercised independently.
	$exception_files = array( 'class-guard-transaction.php', 'class-update-engine.php', 'class-provisioning-plan.php', 'class-guard.php', 'class-operation-config.php' );
	if ( 'WordPress.Security.EscapeOutput.ExceptionNotEscaped' === $code &&
		in_array( basename( $path ), $exception_files, true ) &&
		is_string( $source ) && isset( $item['line'] ) ) {
		$context = implode( "\n", array_slice( $lines, max( 0, (int) $item['line'] - 7 ), 7 ) );
		if ( ( str_contains( $context, 'throw new ' ) || str_contains( $context, 'throw $error' ) ) &&
			! preg_match( '/\b(?:echo|print)\b/', $context ) ) {
			$reviewed[] = $code . ' (' . $path . ') exception construction, not HTML';
			continue;
		}
	}
	if ( 'WordPress.Security.EscapeOutput.OutputNotEscaped' === $code && 'includes/class-admin-page.php' === $path &&
		is_string( $source ) && isset( $lines[ (int) $item['line'] - 1 ] ) &&
		str_contains( $lines[ (int) $item['line'] - 1 ], 'echo $extra;' ) &&
		str_contains( $source, "self::form( 'budget', 'Save logical budget', \$budget );" ) ) {
		$reviewed[] = "$code ($path) private form markup built from esc_attr values";
		continue;
	}
	if ( 'WordPress.Security.NonceVerification.Missing' === $code && 'includes/class-admin-page.php' === $path &&
		isset( $item['line'], $lines[ (int) $item['line'] - 1 ] ) &&
		str_contains( $lines[ (int) $item['line'] - 1 ], '$post = isset( $_POST )' ) &&
		str_contains( $source, 'self::process( $post, $method )' ) && str_contains( $source, 'current_user_can( self::CAPABILITY )' ) &&
		str_contains( $source, 'wp_verify_nonce(' ) ) {
		$reviewed[] = "$code ($path) post array passed to guarded process before mutation";
		continue;
	}
	// The compact-journal upgrade must inspect and alter its own durable schema.
	// These are fixed information_schema/SHOW statements and three literal
	// ALTERs; the table name is %i-bound after table() validates its
	// identifier. Such metadata/DDL calls must not be object-cached.
	//
	// The expected literals are matched against the live source line rather
	// than hardcoded line numbers. The previous hardcoded map drifted from the
	// merged source (it expected get_col and an implode($changes) ALTER that no
	// longer exists), which made this required gate fail on unchanged files.
	$line = (int) ( $item['line'] ?? 0 );
	$journal_install = 'includes/free/class-price-apply-journal.php' === $path && is_string( $source ) &&
		str_contains( $source, "preg_match( '/\\A[a-zA-Z0-9_]+\\z/D', \$name )" ) &&
		str_contains( $source, 'self::upgrade_v1( $wpdb );' ) &&
		str_contains( $source, "ALTER TABLE %i ADD price_field varchar(16) NOT NULL DEFAULT ''" );
	$journal_sql_literals = array(
		"\$wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', \$table )",
		"\$wpdb->prepare( 'SHOW COLUMNS FROM %i', \$table )",
		"\$wpdb->prepare( 'ALTER TABLE %i MODIFY plan_json longtext NULL', \$table )",
		"\$wpdb->prepare( \"ALTER TABLE %i ADD plan_fingerprint char(64) NOT NULL DEFAULT ''\", \$table )",
		"\$wpdb->prepare( \"ALTER TABLE %i ADD price_field varchar(16) NOT NULL DEFAULT ''\", \$table )",
	);
	$journal_line = isset( $lines[ $line - 1 ] ) ? $lines[ $line - 1 ] : '';
	$journal_matched = false;
	foreach ( $journal_sql_literals as $literal ) {
		if ( str_contains( $journal_line, $literal ) ) { $journal_matched = true; break; }
	}
	if ( $journal_install && $journal_matched &&
		in_array( $code, array( 'WordPress.DB.DirectDatabaseQuery.DirectQuery', 'WordPress.DB.DirectDatabaseQuery.NoCaching', 'WordPress.DB.DirectDatabaseQuery.SchemaChange', 'PluginCheck.Security.DirectDB.UnescapedDBParameter' ), true ) ) {
		$reviewed[] = "$code ($path:$line) fixed journal schema introspection/DDL with validated table identifier";
		continue;
	}
	// #230 preset slots use an explicit option-row compare-and-swap with
	// phpcs-reviewed prepared SQL (the hidden option name is built from an
	// internal prefix plus a validated integer slot). PCP's DirectQuery-family
	// sniff still fires on the prepared $wpdb->query( $sql ) calls. Matched by
	// exact reviewed source line, never by a bare line number.
	$preset_cas = 'includes/free/class-price-presets.php' === $path && is_string( $source ) &&
		str_contains( $source, 'private static function replace( int $slot, array $old, ?array $new ): void' ) &&
		str_contains( $source, '$inserted = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared' );
	$preset_line_literals = array(
		'$inserted = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared',
		"if ( 1 !== \$wpdb->query( \$sql ) ) { throw new Price_Validation_Error( 'preset_changed' ); } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared",
	);
	$preset_line = isset( $lines[ $line - 1 ] ) ? $lines[ $line - 1 ] : '';
	$preset_matched = false;
	foreach ( $preset_line_literals as $literal ) {
		if ( str_contains( $preset_line, $literal ) ) { $preset_matched = true; break; }
	}
	if ( $preset_cas && $preset_matched &&
		in_array( $code, array( 'WordPress.DB.DirectDatabaseQuery.DirectQuery', 'WordPress.DB.DirectDatabaseQuery.NoCaching', 'WordPress.DB.PreparedSQL.NotPrepared', 'PluginCheck.Security.DirectDB.UnescapedDBParameter' ), true ) ) {
		$reviewed[] = "$code ($path:$line) preset option-row compare-and-swap with reviewed prepared SQL";
		continue;
	}
	// The #298 job v1->v2 upgrade backfills approval_mode on already-approved
	// human jobs. Same installer class as the journal precedent: a fixed
	// UPDATE assembled only from literal column definitions, the table name
	// %i-bound after jobs_table() validates its identifier. Such setup writes
	// must not be object-cached.
	$job_v2_backfill = 'includes/free/class-job-schema.php' === $path && is_string( $source ) &&
		str_contains( $source, 'upgrade_v1_to_v2' ) &&
		str_contains( $source, "UPDATE %i SET approval_mode='human' WHERE approver_id>0" );
	if ( $job_v2_backfill && isset( $lines[ $line - 1 ] ) &&
		str_contains( $lines[ $line - 1 ], "UPDATE %i SET approval_mode='human' WHERE approver_id>0 AND approval_mode='none'" ) &&
		in_array( $code, array( 'WordPress.DB.DirectDatabaseQuery.DirectQuery', 'WordPress.DB.DirectDatabaseQuery.NoCaching' ), true ) ) {
		$reviewed[] = "$code ($path:$line) fixed job v2 approval-mode backfill with validated table identifier";
		continue;
	}

	$failures[] = $type . ':' . $code . ' (' . $item['file'] . ':' . ( $item['line'] ?? '?' ) . ') ' . strip_tags( $item['message'] ?? '' );
}
foreach ( array_unique( $reviewed ) as $finding ) {
	echo '#62/#63 ' . $argv[1] . ' Plugin Check reviewed: ' . $finding . "\n";
}
foreach ( array_unique( $failures ) as $finding ) {
	fwrite( STDERR, '#62/#63 ' . $argv[1] . ' Plugin Check FAIL ' . $finding . "\n" );
}
if ( $failures || ( '0' !== $argv[2] && ! $reviewed ) ) {
	throw new RuntimeException( 'Plugin Check unclassified/error result; errors=' . $errors . ' warnings=' . $warnings . ' exit=' . $argv[2] );
}
echo '#62/#63 ' . $argv[1] . ' Plugin Check 2.1.0 stable/static: errors=' . $errors . ' warnings=' . $warnings . ' reviewed=' . count( $reviewed ) . ' security/runtime blockers=0 PASS' . "\n";
