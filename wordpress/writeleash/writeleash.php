<?php
/**
 * Plugin Name: WriteLeash
 * Description: WooCommerce bulk regular-price changes with frozen previews, safety limits and Undo.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: writeleash
 */

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * WriteLeash is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 2 of the License, or (at your option) any later
 * version.
 *
 * WriteLeash is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with WriteLeash. If not, see <https://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

define( 'WRITELEASH_VERSION', '0.1.0' );
define( 'WRITELEASH_PLUGIN_FILE', __FILE__ );

// Avoid parsing PHP 7.4 class files on older runtimes; activation must fail.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	register_activation_hook(
		__FILE__,
		static function () {
			wp_die( 'WriteLeash requires PHP 7.4 or newer.' );
		}
	);
	return;
}

require_once __DIR__ . '/includes/class-environment.php';
require_once __DIR__ . '/includes/class-lifecycle.php';
require_once __DIR__ . '/includes/class-plugin.php';

// Read-only Free planning values load without Woo; invocation checks its context.
require_once __DIR__ . '/includes/free/class-free-support-contract.php';
require_once __DIR__ . '/includes/free/class-price-decimal.php';
require_once __DIR__ . '/includes/free/class-price-operation.php';
require_once __DIR__ . '/includes/free/class-safety-policy.php';
require_once __DIR__ . '/includes/free/class-product-snapshot.php';
require_once __DIR__ . '/includes/free/class-product-selector.php';
require_once __DIR__ . '/includes/free/class-change-plan.php';

// #108 controlled item primitive. No public mutation endpoint or automatic installer.
require_once __DIR__ . '/includes/free/class-price-apply-connection.php';
require_once __DIR__ . '/includes/free/class-runner-authority.php';
require_once __DIR__ . '/includes/free/class-price-apply-journal.php';
require_once __DIR__ . '/includes/free/class-price-cache-verifier.php';
require_once __DIR__ . '/includes/free/class-price-apply-transaction-guard.php';
require_once __DIR__ . '/includes/free/class-woo-price-mutator.php';

// #109 durable job engine. Action Scheduler is only the wake-up mechanism.
require_once __DIR__ . '/includes/free/class-job-state.php';
require_once __DIR__ . '/includes/free/class-job-schema.php';
require_once __DIR__ . '/includes/free/class-job-transaction-fence.php';
require_once __DIR__ . '/includes/free/class-job-repository.php';
require_once __DIR__ . '/includes/free/class-job-scheduler.php';
require_once __DIR__ . '/includes/free/class-job-worker.php';
require_once __DIR__ . '/includes/free/class-job-resume-rest.php';

// #110 conflict-aware Undo, bounded history and retention. No Admin UX.
require_once __DIR__ . '/includes/free/class-undo-state.php';
require_once __DIR__ . '/includes/free/class-undo-schema.php';
require_once __DIR__ . '/includes/free/class-undo-fingerprint.php';
require_once __DIR__ . '/includes/free/class-undo-transaction-fence.php';
require_once __DIR__ . '/includes/free/class-woo-undo-mutator.php';
require_once __DIR__ . '/includes/free/class-undo-repository.php';
require_once __DIR__ . '/includes/free/class-undo-scheduler.php';
require_once __DIR__ . '/includes/free/class-undo-worker.php';
require_once __DIR__ . '/includes/free/class-undo-rest.php';

// #111 complete Free Admin workflow over the #107 plan, #108 primitive,
// #109 job engine and #110 history/Undo contracts. No scale certification.
require_once __DIR__ . '/includes/free/class-free-admin.php';

\WriteLeash\Plugin::boot();
