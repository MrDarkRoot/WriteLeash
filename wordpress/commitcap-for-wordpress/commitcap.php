<?php
/**
 * Plugin Name: CommitCap
 * Description: Stop bulk database mistakes before they commit.
 * Version: 0.1.0
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: commitcap
 */

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * CommitCap is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 2 of the License, or (at your option) any later
 * version.
 *
 * CommitCap is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with CommitCap. If not, see <https://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COMMITCAP_VERSION', '0.1.0' );
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
require_once __DIR__ . '/includes/class-certified-operation.php';
require_once __DIR__ . '/includes/class-operation-config.php';
require_once __DIR__ . '/includes/class-certified-operation-status.php';
require_once __DIR__ . '/includes/class-last-outcome.php';
require_once __DIR__ . '/includes/class-disposable-demo.php';
require_once __DIR__ . '/includes/class-disposable-demo-setup.php';
require_once __DIR__ . '/includes/class-product-status.php';
require_once __DIR__ . '/includes/class-admin-page.php';
require_once __DIR__ . '/includes/class-product-cli.php';
require_once __DIR__ . '/includes/class-redirection-bulk-disable-rest.php';
require_once __DIR__ . '/includes/class-plugin.php';

\CommitCap\Plugin::boot();
