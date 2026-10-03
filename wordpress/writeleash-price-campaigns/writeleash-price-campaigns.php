<?php
/**
 * Plugin Name: WriteLeash Price Campaigns (isolation scaffold)
 * Description: Internal portfolio isolation scaffold. No product functionality.
 * Version: 0.0.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: writeleash-price-campaigns
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
namespace WriteLeash\PriceCampaigns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/includes/class-plugin.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
