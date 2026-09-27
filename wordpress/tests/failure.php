<?php
// Executed through WP-CLI against real, fully bootstrapped WordPress.
require_once WP_PLUGIN_DIR . '/commitcap/commitcap.php';
$mode = getenv( 'CC_FAILURE' );
global $wpdb;

switch ( $mode ) {
	case 'null':
		$wpdb = null;
		break;
	case 'invalid':
		$wpdb = new stdClass();
		break;
	case 'version':
		$wpdb = new class extends wpdb {
			public function __construct() {}
			public function db_version() {
				return '';
			}
		};
		break;
	case 'query-exception':
		$wpdb = new class extends wpdb {
			public function __construct() {}
			public function db_version() {
				throw new RuntimeException( 'Simulated database version query failure' );
			}
		};
		break;
	case 'write':
		add_filter( 'pre_update_option_commitcap_version', static function ( $new, $old ) { return $old; }, 10, 2 );
		break;
	case 'network':
		\CommitCap\Lifecycle::activate( true );
		break;
	default:
		throw new RuntimeException( 'Unknown failure case' );
}

\CommitCap\Lifecycle::activate();
throw new RuntimeException( 'Failed activation returned normally' );
