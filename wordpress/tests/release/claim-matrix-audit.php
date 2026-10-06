<?php
// #121 repository-only release claim audit. The claim matrix itself is not
// packaged; this gate proves the public listing stays inside accepted evidence
// and that every required claim topic has a reviewed matrix entry.
if ( 2 !== $argc ) {
	throw new RuntimeException( 'Usage: claim-matrix-audit.php <repo-root>' );
}
$repo     = rtrim( $argv[1], '/' );
$readme   = $repo . '/wordpress/writeleash/readme.txt';
$matrix   = $repo . '/wordpress/release/CLAIM-MATRIX.md';
$manifest = $repo . '/wordpress/release/writeleash-distribution-files.txt';
$fail     = static function ( string $message ): void {
	throw new RuntimeException( '#121 claim matrix audit: ' . $message );
};
foreach ( array( $readme, $matrix, $manifest ) as $file ) {
	if ( ! is_file( $file ) ) {
		$fail( 'missing required file: ' . $file );
	}
}
$read = (string) file_get_contents( $readme );
$mx   = (string) file_get_contents( $matrix );
$mf   = (string) file_get_contents( $manifest );
require_once __DIR__ . '/conflict-copy.php';
writeleash_conflict_copy_audit( $read );
writeleash_conflict_copy_audit( $mx );
// Appending an overbroad claim must fail even when the bounded wording remains.
foreach ( array(
	'If a product is edited after approval, it conflicts.',
	'Any later product edit causes a conflict.',
	'A later product edit becomes a conflict instead of a blind overwrite.',
	'A product edited after approval becomes a conflict.',
	'Later edits always conflict.',
	'Later edits are reported as conflicts instead of being overwritten.',
	'If the product no longer matches the approved plan, it conflicts.',
) as $regression ) {
	try {
		writeleash_conflict_copy_audit( $read . "\n" . $regression );
	} catch ( RuntimeException $expected ) {
		continue;
	}
	$fail( 'overbroad conflict regression accepted: ' . $regression );
}
foreach ( array( 'SKU', 'product name', 'category', 'reference details only' ) as $reference ) {
	if ( false === strpos( $read, $reference ) || false === strpos( $mx, $reference ) ) {
		$fail( 'missing reference-only limitation: ' . $reference );
	}
}
foreach ( array( 'https://downloads.wordpress.org/plugin/woocommerce.11.1.2.zip', '9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e' ) as $artifact ) {
	if ( false === strpos( $mx, $artifact ) ) { $fail( 'missing immutable Woo evidence: ' . $artifact ); }
}

if ( false !== strpos( $mf, 'CLAIM-MATRIX' ) ) {
	$fail( 'internal claim matrix must not enter the distribution manifest' );
}

$required_topics = array(
	'1,000-product ceiling'    => '1,000 selected products',
	'five operations'          => 'Five price operations',
	'simple-product scope'     => 'Supported product scope',
	'Woo exact version'        => 'WooCommerce version',
	'WordPress versions'       => 'WordPress versions and minimum metadata',
	'PHP versions'             => 'PHP versions and minimum metadata',
	'DB engines'               => 'Database engines',
	'persistent cache'         => 'Persistent object cache',
	'conflict behavior'        => 'Conflict behavior',
	'Resume'                   => 'partial outcomes and Resume',
	'Undo'                     => 'Undo eligibility',
	'ordinary DB privileges'   => 'Ordinary database privileges',
	'multisite'                => 'Multisite',
	'real-host status'         => 'Real managed/shared hosting',
	'external side effects'    => 'External side effects',
);
foreach ( $required_topics as $label => $needle ) {
	if ( false === stripos( $mx, $needle ) ) {
		$fail( 'claim matrix is missing required topic: ' . $label );
	}
}

$unsupported = array( '10,000', '10000', 'universal rollback', 'all bad edits', 'every shared host', 'all WooCommerce versions', 'any WooCommerce version' );
foreach ( $unsupported as $claim ) {
	if ( false !== stripos( $read, $claim ) ) {
		$fail( 'unsupported public claim in readme: ' . $claim );
	}
}

foreach ( array(
	'Up to 1,000 selected products per new job in the tested configuration.',
	'WooCommerce 10.0 through 11.x',
	'Multisite is unsupported.',
	'Real managed or shared hosting has not been tested.',
) as $exact ) {
	if ( false === stripos( $read, $exact ) || false === stripos( $mx, $exact ) ) {
		$fail( 'readme and claim matrix do not share the exact supported wording: ' . $exact );
	}
}

echo '#121 claim matrix audit: ' . count( $required_topics ) . " required topics, forbidden-claim guard, readme/matrix wording and packaging boundary PASS\n";
