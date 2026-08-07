<?php
/**
 * Plugin Name: Ax402 for WooCommerce
 * Plugin URI: https://ax402.io
 * Description: Accept x402 / Ax402 stablecoin payments in WooCommerce for humans and agents.
 * Version: 0.1.0
 * Author: AxLabs
 * Author URI: https://axlabs.com
 * Developer: AxLabs
 * Developer URI: https://axlabs.com
 * Text Domain: ax402-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 11.0
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('AX402_WC_VERSION', '0.1.0');
define('AX402_WC_PLUGIN_FILE', __FILE__);
define('AX402_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AX402_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AX402_WC_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once AX402_WC_PLUGIN_DIR . 'includes/class-autoloader.php';
Ax402_WC_Autoloader::register();

/**
 * Activation hook.
 */
function ax402_wc_activate(): void
{
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(AX402_WC_PLUGIN_BASENAME);
        wp_die(
            esc_html__('Ax402 for WooCommerce requires WooCommerce to be active.', 'ax402-for-woocommerce'),
            esc_html__('Plugin dependency check', 'ax402-for-woocommerce'),
            ['back_link' => true]
        );
    }

    if (!wp_next_scheduled('ax402_wc_prune_endpoints')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'ax402_wc_prune_endpoints');
    }

    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'ax402_wc_activate');

/**
 * Deactivation hook.
 */
function ax402_wc_deactivate(): void
{
    $timestamp = wp_next_scheduled('ax402_wc_prune_endpoints');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'ax402_wc_prune_endpoints');
    }
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'ax402_wc_deactivate');

/**
 * Boot the plugin after all plugins are loaded.
 */
function ax402_wc_init(): void
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Ax402 for WooCommerce requires WooCommerce.', 'ax402-for-woocommerce');
            echo '</p></div>';
        });
        return;
    }

    $GLOBALS['ax402_wc'] = Ax402_WC_Plugin::instance();
}
// Priority 11: after WooCommerce (typically 10) but early enough for Blocks hooks.
add_action('plugins_loaded', 'ax402_wc_init', 11);

/**
 * Declare WooCommerce feature compatibility.
 */
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            AX402_WC_PLUGIN_FILE,
            true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            AX402_WC_PLUGIN_FILE,
            true
        );
    }
});
