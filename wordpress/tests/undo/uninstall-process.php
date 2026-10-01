<?php
// Test-only real WordPress uninstall request for the #110 lifecycle races.
// Each invocation is a fresh process, exactly like the WordPress admin
// uninstall flow: uninstall_plugin() defines WP_UNINSTALL_PLUGIN and includes
// the standalone uninstall.php once.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$writeleash_uninstalled = uninstall_plugin( 'writeleash/writeleash.php' );
if ( true !== $writeleash_uninstalled ) {
	throw new RuntimeException( 'uninstall_plugin did not execute the WriteLeash uninstall path' );
}
echo "writeleash-uninstall-complete\n";
