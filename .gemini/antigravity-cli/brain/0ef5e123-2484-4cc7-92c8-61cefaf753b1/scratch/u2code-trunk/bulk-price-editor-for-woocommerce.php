<?php

/**
 * Plugin Name:       Bulk Price Editor for WooCommerce
 * Requires Plugins:  woocommerce
 * Description:       Edit product prices in bulk with ease. Change prices by fixed amount, percentage or replace with new prices.
 * Version:           2.5.0
 * Plugin URI:        https://woocommerce.com/products/bulk-price-editor
 * Author:            U2Code
 * Author URI:        https://woocommerce.com/vendor/u2code-kolya-lukin/
 * License:           GNU General Public License v3.0
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       bulk-price-editor
 * Domain Path:       /languages/
 *
 * WC requires at least: 7.1
 * WC tested up to: 11.1
 **/
use BulkPriceEditor\BulkPriceEditorPlugin;
// If this file is called directly, abort.
if ( !defined( 'WPINC' ) ) {
    die;
}
if ( version_compare( phpversion(), '7.4.0', '<' ) ) {
    add_action( 'admin_notices', function () {
        ?>
			<div class='notice notice-error'>
				<p>
					Bulk Price Editor requires PHP version to be <b>7.4 or higher</b>. You run PHP
					version <?php 
        echo esc_attr( phpversion() );
        ?>
				</p>
			</div>
			<?php 
    } );
    return;
}
if ( !function_exists( 'bpe_initFreemius' ) ) {
    function bpe_initFreemius() {
        if ( !function_exists( 'bpefw_fs' ) ) {
            // Create a helper function for easy SDK access.
            function bpefw_fs() {
                global $bpefw_fs;
                if ( !isset( $bpefw_fs ) ) {
                    // Include Freemius SDK.
                    require_once dirname( __FILE__ ) . '/freemius/start.php';
                    $bpefw_fs = fs_dynamic_init( array(
                        'id'               => '18826',
                        'slug'             => 'bulk-price-editor-for-woocommerce',
                        'type'             => 'plugin',
                        'public_key'       => 'pk_3bd73fbc7b562186e694c2208c2b6',
                        'is_premium'       => false,
                        'premium_suffix'   => 'Premium',
                        'has_addons'       => false,
                        'has_paid_plans'   => true,
                        'trial'            => array(
                            'days'               => 7,
                            'is_require_payment' => false,
                        ),
                        'menu'             => array(
                            'first-path' => 'plugins.php',
                            'contact'    => false,
                            'support'    => false,
                        ),
                        'is_live'          => true,
                        'is_org_compliant' => true,
                    ) );
                }
                return $bpefw_fs;
            }

            // Init Freemius.
            bpefw_fs();
            // Signal that SDK was initiated.
            do_action( 'bpefw_fs_loaded' );
        }
    }

}
if ( !function_exists( 'bpefw_fs_upgrade_url' ) ) {
    function bpefw_fs_upgrade_url() : ?string {
        return ( bpefw_fs()->is_activation_mode() ? bpefw_fs()->get_activation_url() : bpefw_fs()->get_upgrade_url() );
    }

}
if ( function_exists( 'bpefw_fs' ) ) {
    bpefw_fs()->set_basename( false, __FILE__ );
    return;
} else {
    bpe_initFreemius();
    call_user_func( function () {
        require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
        $plugin = new BulkPriceEditorPlugin(__FILE__);
        register_activation_hook( __FILE__, array($plugin, 'activate') );
        // No uninstall cleanup on purpose: options (templates, settings) are preserved
        // so users can switch between free and premium versions without losing data.
        if ( $plugin->checkRequirements() ) {
            $plugin->run();
        }
    } );
}
define( 'BULK_PRICE_EDITOR_PRODUCTION', true );