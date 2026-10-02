<?php
// Repository-only CLI lab loader. Public source never references this file.
// Even copying it into a normal plugin cannot enable a historical surface:
// it requires the disposable container's external tree and lab entrypoint.
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ||
	! is_file( '/opt/tests/legacy/files.txt' ) ||
	! is_file( WP_PLUGIN_DIR . '/writeleash/includes/class-update-engine.php' ) ) {
	throw new RuntimeException( 'Historical bootstrap requires repository CLI regression staging.' );
}
foreach ( file( '/opt/tests/legacy/files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $entry ) {
	if ( '#' !== $entry[0] && 'php' === pathinfo( $entry, PATHINFO_EXTENSION ) ) {
		require_once WP_PLUGIN_DIR . '/writeleash/' . $entry;
	}
}
\WriteLeash\Redirection_Bulk_Disable_Rest::boot();
\WriteLeash\Admin_Page::boot();
\WP_CLI::add_command( 'writeleash', \WriteLeash\Product_CLI::class );
