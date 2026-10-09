<?php
// Real gettext, late inactive-plugin include: activation occurs after init.
// Only wp_die is intercepted, before the refused activation can create state.
namespace WriteLeash;
function wp_die( $message ): void { throw new \RuntimeException( $message ); }
if ( ! \did_action( 'init' ) || \class_exists( Lifecycle::class, false ) || ! \switch_to_locale( 'de_DE' ) ) { throw new \RuntimeException( 'Late activation fixture prerequisites missing' ); }
// A prior catalog miss must not prevent the newly registered local lookup.
\__( 'WriteLeash network activation has not been validated.', 'writeleash' );
require WP_PLUGIN_DIR . '/writeleash/writeleash.php';
try { Lifecycle::activate( true ); throw new \RuntimeException( 'Network activation did not refuse' ); }
catch ( \RuntimeException $error ) {
	if ( '[Ü] WriteLeash network activation has not been validated.' !== $error->getMessage() ) { throw new \RuntimeException( 'First activation did not load effective local gettext: ' . $error->getMessage() ); }
}
\restore_previous_locale();
echo "#210 real late activation gettext PASS\n";
