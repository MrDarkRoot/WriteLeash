<?php
// #311 matrix aggregator (container gate).
// Runs after the four engine/cache combinations in in-container.sh:
//   php /opt/tests/jobs/matrix-aggregate.php /tmp/wl311-matrix
// Reads the per-combination MATRIX-JSON files written by matrix-integration.php,
// refuses to combine evidence that does not agree on the source revision or the
// shared environment, merges each cell across the four combinations, and emits
// exactly one headline MATRIX-JSON line. Exits non-zero on any failure so CI
// cannot report a green run over partial or contradictory evidence.
$dir = (string) ( $argv[1] ?? '/tmp/wl311-matrix' );
$expected = array(
	array( 'db_engine' => 'mysql', 'cache_mode' => 'default' ),
	array( 'db_engine' => 'mysql', 'cache_mode' => 'persistent' ),
	array( 'db_engine' => 'mariadb', 'cache_mode' => 'default' ),
	array( 'db_engine' => 'mariadb', 'cache_mode' => 'persistent' ),
);
$fail = static function ( string $message ) use ( $dir ): void {
	fwrite( STDERR, '#311 aggregate FAIL: ' . $message . "\n" );
	echo 'MATRIX-JSON: ' . json_encode( array( 'schema' => 1, 'source_sha' => 'UNKNOWN', 'status' => 'FAIL', 'reason' => $message, 'cells' => array(), 'summary' => array( 'pass' => 0, 'fail' => 1, 'blocked' => 0, 'not_tested' => 0, 'not_tested_combinations' => 0, 'total' => 0 ) ), JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 1 );
};
$docs = array();
$reasons = array();
foreach ( $expected as $combo ) {
	$file = $dir . '/' . $combo['db_engine'] . '-' . $combo['cache_mode'] . '.json';
	if ( ! is_file( $file ) ) { $reasons[] = 'missing ' . $file; continue; }
	$doc = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $doc ) || 1 !== (int) ( $doc['schema'] ?? 0 ) || ! is_array( $doc['cells'] ?? null ) ) { $reasons[] = 'unreadable ' . $file; continue; }
	if ( (string) ( $doc['environment']['db_engine'] ?? '' ) !== $combo['db_engine'] || (string) ( $doc['environment']['cache_mode'] ?? '' ) !== $combo['cache_mode'] ) {
		$reasons[] = 'combination mismatch in ' . $file;
		continue;
	}
	$docs[] = $doc;
}
if ( $reasons ) { $fail( implode( '; ', $reasons ) ); }

$sha = (string) ( $docs[0]['source_sha'] ?? '' );
if ( '' === $sha || 'UNKNOWN' === $sha ) { $fail( 'source_sha is UNKNOWN; evidence cannot be tied to a revision' ); }
foreach ( $docs as $doc ) {
	if ( (string) $doc['source_sha'] !== $sha ) { $fail( 'source_sha differs across combinations' ); }
}
foreach ( array( 'php', 'wordpress', 'woocommerce' ) as $key ) {
	$values = array_unique( array_map( static function ( $doc ) use ( $key ) { return (string) ( $doc['environment'][ $key ] ?? '' ); }, $docs ) );
	if ( count( $values ) !== 1 ) { $fail( 'environment.' . $key . ' differs across combinations' ); }
}
$versions = array();
foreach ( $docs as $doc ) {
	$engine = (string) $doc['environment']['db_engine'];
	$version = (string) ( $doc['environment']['db_version'] ?? '' );
	if ( isset( $versions[ $engine ] ) && $versions[ $engine ] !== $version ) { $fail( 'db_version differs within ' . $engine ); }
	$versions[ $engine ] = $version;
}

$combinations_not_tested = $docs[0]['combinations_not_tested'] ?? null;
if ( ! is_array( $combinations_not_tested ) || ! $combinations_not_tested ) { $fail( 'combinations_not_tested is missing or empty' ); }
foreach ( $combinations_not_tested as $entry ) {
	if ( ! is_array( $entry ) ) { $fail( 'combinations_not_tested entry is not an object' ); }
	foreach ( array( 'combination', 'reason', 'claim_withheld' ) as $key ) {
		if ( ! array_key_exists( $key, $entry ) ) { $fail( 'combinations_not_tested entry is missing ' . $key ); }
		if ( ! is_string( $entry[ $key ] ) || '' === trim( $entry[ $key ] ) ) { $fail( 'combinations_not_tested entry has an empty ' . $key ); }
	}
}
foreach ( $docs as $doc ) {
	if ( ( $doc['combinations_not_tested'] ?? null ) !== $combinations_not_tested ) { $fail( 'combinations_not_tested differs across combinations' ); }
}
$combination_markers = array( 'WordPress 7.0', 'WooCommerce 10.0', 'PHP 8.3' );
foreach ( $combination_markers as $marker ) {
	$found = false;
	foreach ( $combinations_not_tested as $entry ) {
		if ( false !== strpos( (string) $entry['combination'], $marker ) ) { $found = true; break; }
	}
	if ( ! $found ) { $fail( 'combinations_not_tested is missing marker: ' . $marker ); }
}

$ids = array();
$by_id = array();
foreach ( $docs as $doc ) {
	$key = $doc['environment']['db_engine'] . '-' . $doc['environment']['cache_mode'];
	$cell_ids = array_map( static function ( $cell ) { return (string) $cell['id']; }, $doc['cells'] );
	sort( $cell_ids );
	$ids[ $key ] = $cell_ids;
	foreach ( $doc['cells'] as $cell ) { $by_id[ (string) $cell['id'] ][ $key ] = $cell; }
}
$reference = reset( $ids );
foreach ( $ids as $key => $cell_ids ) {
	if ( $cell_ids !== $reference ) { $fail( 'cell set differs in ' . $key ); }
}
$rank = array( 'FAIL' => 4, 'BLOCKED' => 3, 'NOT_TESTED' => 2, 'PASS' => 1 );
$cells = array();
$summary = array( 'pass' => 0, 'fail' => 0, 'blocked' => 0, 'not_tested' => 0, 'not_tested_combinations' => count( $combinations_not_tested ), 'total' => 0 );
foreach ( $reference as $id ) {
	$by_combination = array();
	$statuses = array();
	$guard = '';
	$expect = '';
	$path = '';
	$evidence = array();
	foreach ( $expected as $combo ) {
		$key = $combo['db_engine'] . '-' . $combo['cache_mode'];
		$cell = $by_id[ $id ][ $key ] ?? null;
		$status = is_array( $cell ) ? (string) $cell['status'] : 'FAIL';
		$by_combination[ $key ] = $status;
		$statuses[ $status ] = true;
		if ( is_array( $cell ) ) {
			$guard = '' !== $guard ? $guard : (string) ( $cell['guard'] ?? '' );
			$expect = '' !== $expect ? $expect : (string) ( $cell['expect'] ?? '' );
			$path = '' !== $path ? $path : (string) ( $cell['path'] ?? '' );
			$evidence[] = (string) ( $cell['evidence'] ?? '' );
		}
	}
	if ( 1 === count( $statuses ) ) {
		$status = (string) array_key_first( $statuses );
		$reason = '';
	} else {
		$status = 'FAIL';
		$reason = 'status differs across combinations: ' . json_encode( $by_combination );
	}
	if ( ! isset( $rank[ $status ] ) ) { $status = 'FAIL'; $reason = 'unknown status'; }
	$key = strtolower( $status );
	++$summary[ $key ];
	++$summary['total'];
	$cells[] = array(
		'id' => $id,
		'path' => $path,
		'expect' => $expect,
		'status' => $status,
		'guard' => $guard,
		'by_combination' => $by_combination,
		'evidence' => 'sha256:' . hash( 'sha256', implode( '|', $evidence ) ),
		'reason' => $reason,
	);
}
$doc = array(
	'schema' => 1,
	'source_sha' => $sha,
	'captured_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
	'environment' => array(
		'php' => $docs[0]['environment']['php'],
		'wordpress' => $docs[0]['environment']['wordpress'],
		'woocommerce' => $docs[0]['environment']['woocommerce'],
		'db_versions' => $versions,
		'combinations' => array_map( static function ( $doc ) { return array( 'db_engine' => $doc['environment']['db_engine'], 'cache_mode' => $doc['environment']['cache_mode'], 'db_version' => $doc['environment']['db_version'] ); }, $docs ),
	),
	'combinations_ran' => array_map( static function ( $doc ) {
		return array(
			'db_engine' => $doc['environment']['db_engine'],
			'cache_mode' => $doc['environment']['cache_mode'],
			'cells' => count( $doc['cells'] ),
			'summary' => $doc['summary'] ?? array(),
			'captured_at' => $doc['captured_at'] ?? '',
		);
	}, $docs ),
	'combinations_not_tested' => $combinations_not_tested,
	'cells' => $cells,
	'summary' => $summary,
);
file_put_contents( $dir . '/aggregate.json', (string) json_encode( $doc, JSON_UNESCAPED_SLASHES ) );
echo 'MATRIX-JSON: ' . json_encode( $doc, JSON_UNESCAPED_SLASHES ) . "\n";
echo '#311 aggregate: ' . json_encode( $summary ) . ' combinations=' . count( $docs ) . "\n";
exit( $summary['fail'] > 0 || $summary['blocked'] > 0 ? 1 : 0 );
