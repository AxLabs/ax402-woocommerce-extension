<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Upstream fulfill endpoint called by the Ax402 gateway after payment.
 */
final class Ax402_WC_Fulfill_Controller
{
    public function register(): void
    {
        register_rest_route(
            'ax402/v1',
            '/fulfill/(?P<order_key>[A-Za-z0-9_-]+)/(?P<fulfill_token>[A-Fa-f0-9]+)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'fulfill'],
                'permission_callback' => '__return_true',
                'args' => [
                    'order_key' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'fulfill_token' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                ],
            ]
        );
    }

    public function fulfill(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $order_key = (string) $request['order_key'];
        $token = (string) $request['fulfill_token'];

        $order_id = wc_get_order_id_by_order_key($order_key);
        if (!$order_id) {
            return new WP_Error('ax402_not_found', 'Order not found', ['status' => 404]);
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return new WP_Error('ax402_not_found', 'Order not found', ['status' => 404]);
        }

        $expected = (string) $order->get_meta(Ax402_WC_Order_Payment::META_FULFILL_TOKEN);
        if (!Ax402_WC_Fulfill_Auth::is_valid_token($expected, $token)) {
            return new WP_Error('ax402_forbidden', 'Invalid fulfill token', ['status' => 403]);
        }

        if ($order->is_paid()) {
            return new WP_REST_Response($this->receipt($order, true), 200);
        }

        if (!Ax402_WC_Fulfill_Auth::can_fulfill_status($order->get_status())) {
            return new WP_Error(
                'ax402_invalid_status',
                'Order cannot be fulfilled in status ' . $order->get_status(),
                ['status' => 409]
            );
        }

        $locked = (string) $order->get_meta(Ax402_WC_Order_Payment::META_AMOUNT_USDC);
        if ($locked !== '') {
            try {
                $current = Ax402_WC_Money::normalize_order_total($order->get_total());
                if ($current !== $locked) {
                    return new WP_Error(
                        'ax402_amount_mismatch',
                        'Order total changed after payment was prepared',
                        ['status' => 409]
                    );
                }
            } catch (Throwable $e) {
                return new WP_Error('ax402_amount_invalid', $e->getMessage(), ['status' => 409]);
            }
        }

        $order->payment_complete();
        $order->add_order_note(__('Ax402 payment verified via fulfill upstream.', 'ax402-woocommerce'));

        if ($this->order_is_virtual_downloadable($order) && $order->has_status('processing')) {
            $order->update_status('completed', __('Virtual/downloadable order auto-completed after Ax402 payment.', 'ax402-woocommerce'));
        }

        Ax402_WC_Order_Payment::delete_endpoint_for_order($order);

        return new WP_REST_Response($this->receipt($order, false), 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function receipt(WC_Order $order, bool $already_paid): array
    {
        $downloads = [];
        foreach ($order->get_downloadable_items() as $item) {
            $downloads[] = [
                'product_id' => $item['product_id'] ?? null,
                'download_url' => $item['download_url'] ?? null,
                'download_name' => $item['download_name'] ?? null,
            ];
        }

        return [
            'ok' => true,
            'already_paid' => $already_paid,
            'order_id' => $order->get_id(),
            'order_key' => $order->get_order_key(),
            'status' => $order->get_status(),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'amount_usdc' => (string) $order->get_meta(Ax402_WC_Order_Payment::META_AMOUNT_USDC),
            'network' => (string) $order->get_meta(Ax402_WC_Order_Payment::META_NETWORK),
            'thank_you_url' => $order->get_checkout_order_received_url(),
            'downloads' => $downloads,
        ];
    }

    private function order_is_virtual_downloadable(WC_Order $order): bool
    {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                return false;
            }
            if (!$product->is_virtual() && !$product->is_downloadable()) {
                return false;
            }
        }
        return $order->get_item_count() > 0;
    }
}
