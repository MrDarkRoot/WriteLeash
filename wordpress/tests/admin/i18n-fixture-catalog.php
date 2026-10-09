<?php
// Disposable test only: build effective local MO and per-asset Jed JSON from the
// real WP-CLI POT, using WordPress's own POMO parser/exporter. Never staged.
require_once ABSPATH . 'wp-includes/pomo/po.php';
require_once ABSPATH . 'wp-includes/pomo/mo.php';
$language_dir = WP_PLUGIN_DIR . '/writeleash/languages';
$po = new PO();
if ( ! $po->import_from_file( $language_dir . '/writeleash.pot' ) ) { throw new RuntimeException( 'Source catalog unavailable' ); }
$mo = new MO();
$headers = array( 'Language' => 'de_DE', 'Content-Type' => 'text/plain; charset=UTF-8', 'Plural-Forms' => 'nplurals=2; plural=(n != 1);' );
foreach ( $headers as $key => $value ) { $mo->set_header( $key, $value ); }
$assets = array( 'includes/free/free-selection.js', 'includes/free/free-progress.js' );
$jed = array();
foreach ( $assets as $asset ) { $jed[$asset] = array( '' => array( 'domain' => 'writeleash', 'lang' => 'de_DE', 'plural-forms' => 'nplurals=2; plural=(n != 1);' ) ); }
foreach ( $po->entries as $entry ) {
	$entry = clone $entry;
	$translate = static function ( $text ) {
		if ( 'Preview price changes' === $text ) { return '[Ü] Preisänderungen mit ausführlicher Sicherheitsprüfung vor der ausdrücklichen Freigabe ansehen'; }
		return '[Ü] ' . $text;
	};
	$entry->translations = array( $translate( $entry->singular ) );
	if ( $entry->is_plural ) { $entry->translations[] = $translate( $entry->plural ); }
	$mo->add_entry( $entry );
	foreach ( $assets as $asset ) {
		foreach ( $entry->references as $reference ) {
			if ( str_starts_with( $reference, $asset . ':' ) || $reference === $asset ) {
				$key = $entry->context ? $entry->context . "\4" . $entry->singular : $entry->singular;
				$jed[$asset][$key] = $entry->translations;
			}
		}
	}
}
if ( ! $mo->export_to_file( $language_dir . '/writeleash-de_DE.mo' ) ) { throw new RuntimeException( 'Test MO generation failed' ); }
// Make the controlled locale discoverable without downloading core translations.
if ( ! is_dir( WP_LANG_DIR ) ) { mkdir( WP_LANG_DIR, 0755, true ); }
$core = new MO(); foreach ( $headers as $key => $value ) { $core->set_header( $key, $value ); }
$core->export_to_file( WP_LANG_DIR . '/de_DE.mo' );
foreach ( $jed as $asset => $messages ) {
	if ( count( $messages ) < 10 ) { throw new RuntimeException( 'Missing JS fixture messages' ); }
	file_put_contents( $language_dir . '/writeleash-de_DE-' . md5( $asset ) . '.json', wp_json_encode( array( 'domain' => 'writeleash', 'locale_data' => array( 'messages' => $messages ) ) ) );
}
echo '#210 effective pseudo de_DE catalogs generated (' . count( $po->entries ) . " messages)\n";
