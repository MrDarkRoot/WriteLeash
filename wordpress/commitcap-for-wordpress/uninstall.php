<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only the option created by the #55 foundation belongs to this uninstall.
delete_option( 'commitcap_version' );
