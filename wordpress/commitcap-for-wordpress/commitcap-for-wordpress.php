<?php
/**
 * Plugin Name: CommitCap for WordPress
 * Description: Stop bulk database mistakes before they commit.
 * Version: 0.1.0-dev
 * Requires PHP: 7.4
 * Text Domain: commitcap-for-wordpress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COMMITCAP_VERSION', '0.1.0-dev' );
define( 'COMMITCAP_PLUGIN_FILE', __FILE__ );

// Avoid parsing PHP 7.4 class files on older runtimes; activation must fail.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	register_activation_hook(
		__FILE__,
		static function () {
			wp_die( 'CommitCap requires PHP 7.4 or newer.' );
		}
	);
	return;
}

require_once __DIR__ . '/includes/class-environment.php';
require_once __DIR__ . '/includes/class-lifecycle.php';
require_once __DIR__ . '/includes/class-update-engine.php';
require_once __DIR__ . '/includes/class-guard-error.php';
require_once __DIR__ . '/includes/class-unsupported-transaction-state.php';
require_once __DIR__ . '/includes/class-budget-denied.php';
require_once __DIR__ . '/includes/class-guard-transaction.php';
require_once __DIR__ . '/includes/class-guard-sql.php';
require_once __DIR__ . '/includes/class-guard-monitor.php';
require_once __DIR__ . '/includes/class-guard.php';
require_once __DIR__ . '/includes/class-compatibility-grants.php';
require_once __DIR__ . '/includes/class-compatibility-doctor.php';
require_once __DIR__ . '/includes/class-provisioning-plan.php';
require_once __DIR__ . '/includes/class-redirection-bulk-disable.php';
require_once __DIR__ . '/includes/class-plugin.php';

\CommitCap\Plugin::boot();
