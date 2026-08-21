<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Ax402_For_WooCommerce
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$option_keys = [
    'ax402_wc_settings',
    'ax402_wc_platform_cache',
    'ax402_wc_cors_origins',
    'ax402_wc_cors_synced_at',
    'ax402_wc_cors_error',
    'ax402_wc_ucp_rewrite',
    'woocommerce_ax402_settings',
];

foreach ($option_keys as $key) {
    delete_option($key);
}

delete_transient('ax402_wc_chainlist_v1');

global $wpdb;
if (isset($wpdb) && $wpdb instanceof wpdb) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_ax402_wc_') . '%',
            $wpdb->esc_like('_transient_timeout_ax402_wc_') . '%',
            $wpdb->esc_like('_site_transient_ax402_wc_') . '%',
            $wpdb->esc_like('_site_transient_timeout_ax402_wc_') . '%'
        )
    );
}

wp_clear_scheduled_hook('ax402_wc_prune_endpoints');
