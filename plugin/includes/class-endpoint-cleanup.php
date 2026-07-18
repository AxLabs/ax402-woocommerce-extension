<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Cleanup Ax402 endpoints when orders are cancelled/failed or via cron.
 */
final class Ax402_WC_Endpoint_Cleanup
{
    public function register(): void
    {
        add_action('woocommerce_order_status_cancelled', [$this, 'on_terminal_status'], 10, 1);
        add_action('woocommerce_order_status_failed', [$this, 'on_terminal_status'], 10, 1);
        add_action('woocommerce_order_status_refunded', [$this, 'on_terminal_status'], 10, 1);
        add_action('ax402_wc_prune_endpoints', [$this, 'prune_stale']);
    }

    public function on_terminal_status(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order) {
            Ax402_WC_Order_Payment::delete_endpoint_for_order($order);
        }
    }

    /**
     * Delete endpoints for unpaid Ax402 orders older than 48 hours.
     */
    public function prune_stale(): void
    {
        $orders = wc_get_orders([
            'limit' => 50,
            'status' => ['pending', 'on-hold'],
            'payment_method' => Ax402_WC_Gateway_Ax402::GATEWAY_ID,
            'date_created' => '<' . (time() - 2 * DAY_IN_SECONDS),
            'meta_key' => Ax402_WC_Order_Payment::META_ENDPOINT_ID,
            'meta_compare' => 'EXISTS',
        ]);

        foreach ($orders as $order) {
            if ($order instanceof WC_Order) {
                Ax402_WC_Order_Payment::delete_endpoint_for_order($order);
            }
        }
    }
}
