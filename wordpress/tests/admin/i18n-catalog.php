<?php
// No substitute extractor: inspect the committed output of official WP-CLI.
$root = dirname( __DIR__, 2 );
$pot = file_get_contents( $root . '/writeleash/languages/writeleash.pot' );
$required = array( 'writeleash', 'msgid_plural', 'msgctxt', '#. translators:', 'includes/free/free-progress.js', 'includes/free/free-selection.js', 'Re-preview conflicted products', 'Resume remaining products', 'No products match this selection.', 'Processed %d product in this step.', 'Selected %1$d product: %2$s.', 'Choose at most %d product.', 'Session or permission expired.', 'Download job CSV', 'Review the product, then %1$sCreate a new preview%2$s', 'Undo restores eligible %s values' );
foreach ( $required as $text ) { if ( ! str_contains( $pot, $text ) ) { throw new RuntimeException( 'Catalog missing ' . $text ); } }
if ( substr_count( $pot, "\nmsgid " ) < 250 || str_contains( $pot, 'msgid "<a href=' ) || str_contains( $pot, 'msgid "Planned "' ) ) { throw new RuntimeException( 'Empty or fragment catalog' ); }
foreach ( array( 'SET', 'INCREASE_PERCENT', 'CONFLICT', 'UNDO_CONFLICT', 'plan_json', 'product_ids', 'expected_price' ) as $machine ) {
	if ( str_contains( $pot, 'msgid "' . $machine . '"' ) ) { throw new RuntimeException( 'Machine contract extracted as UI: ' . $machine ); }
}
$manifest = file( $root . '/release/writeleash-distribution-files.txt', FILE_IGNORE_NEW_LINES );
if ( ! in_array( 'languages/writeleash.pot', $manifest, true ) || preg_grep( '/(?:i18n-fixture|de_DE|\.mo$|\.po$)/', $manifest ) ) { throw new RuntimeException( 'Catalog/fixture staging boundary' ); }
$admin = file_get_contents( $root . '/writeleash/includes/free/class-free-admin.php' );
foreach ( array( 'writeleash-free-selection', 'writeleash-free-progress' ) as $handle ) {
	if ( ! str_contains( $admin, "wp_set_script_translations( '$handle', 'writeleash'" ) ) { throw new RuntimeException( 'Missing script translations' ); }
}
echo '#210 catalog: runtime PHP/JS, plurals/context/comments, progress/recovery/accessibility, machine exclusion and distribution PASS (' . substr_count( $pot, "\nmsgid " ) . " entries)\n";
