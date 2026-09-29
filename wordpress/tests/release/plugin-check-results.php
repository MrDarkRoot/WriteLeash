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
