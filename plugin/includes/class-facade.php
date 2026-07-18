<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Optional WC-API facade that 302-redirects to the Ax402 gateway URL.
 */
final class Ax402_WC_Facade
{
    public function register(): void
    {
        add_action('woocommerce_api_ax402_pay', [$this, 'redirect']);
    }

    public function redirect(): void
    {
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '';
        if ($key === '') {
            status_header(400);
            echo 'Missing key';
            exit;
        }

        $order_id = wc_get_order_id_by_order_key($key);
        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order instanceof WC_Order) {
            status_header(404);
            echo 'Order not found';
            exit;
        }

        $gateway_url = (string) $order->get_meta(Ax402_WC_Order_Payment::META_GATEWAY_URL);
        if ($gateway_url === '') {
            status_header(409);
            echo 'Payment URL not ready';
            exit;
        }

        wp_redirect($gateway_url, 302);
        exit;
    }
}
