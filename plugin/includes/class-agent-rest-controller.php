<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Agent-facing browse + buy REST API.
 */
final class Ax402_WC_Agent_Rest_Controller
{
    public function register(): void
    {
        register_rest_route('ax402/v1', '/products', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'list_products'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('ax402/v1', '/orders', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_order'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('ax402/v1', '/orders/(?P<order_key>[A-Za-z0-9_-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_order'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function list_products(WP_REST_Request $request): WP_REST_Response
    {
        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(50, max(1, (int) ($request->get_param('per_page') ?: 20)));

        $q = new WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'fields' => 'ids',
        ]);

        $items = [];
        foreach ($q->posts as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $items[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'slug' => $product->get_slug(),
                'type' => $product->get_type(),
                'price' => $product->get_price(),
                'currency' => get_woocommerce_currency(),
                'virtual' => $product->is_virtual(),
                'downloadable' => $product->is_downloadable(),
                'in_stock' => $product->is_in_stock(),
            ];
        }

        return new WP_REST_Response([
            'products' => $items,
            'page' => $page,
            'per_page' => $per_page,
            'total' => (int) $q->found_posts,
        ], 200);
    }

    public function create_order(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (get_woocommerce_currency() !== 'USD') {
            return new WP_Error('ax402_currency', 'Store currency must be USD', ['status' => 400]);
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }

        $line_items = $params['line_items'] ?? null;
        if (!is_array($line_items) || $line_items === []) {
            return new WP_Error('ax402_invalid', 'line_items is required', ['status' => 400]);
        }

        $order = wc_create_order();
        if (is_wp_error($order)) {
            return $order;
        }

        foreach ($line_items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $product_id = (int) ($row['product_id'] ?? 0);
            $qty = max(1, (int) ($row['quantity'] ?? 1));
            $product = wc_get_product($product_id);
            if (!$product) {
                $order->delete(true);
                return new WP_Error('ax402_invalid', 'Unknown product_id ' . $product_id, ['status' => 400]);
            }
            $order->add_product($product, $qty);
        }

        $email = isset($params['billing_email']) ? sanitize_email((string) $params['billing_email']) : '';
        if ($email !== '') {
            $order->set_billing_email($email);
        }

        $order->set_payment_method(Ax402_WC_Gateway_Ax402::GATEWAY_ID);
        $order->set_payment_method_title('Ax402');
        $order->calculate_totals();
        $order->update_status('pending', __('Agent order awaiting Ax402 payment.', 'ax402-woocommerce'));

        try {
            $payment = Ax402_WC_Order_Payment::prepare($order);
        } catch (Throwable $e) {
            $order->delete(true);
            return new WP_Error('ax402_prepare_failed', $e->getMessage(), ['status' => 500]);
        }

        return new WP_REST_Response([
            'order_id' => $order->get_id(),
            'order_key' => $order->get_order_key(),
            'status' => $order->get_status(),
            'amount_usdc' => $payment['amount_usdc'],
            'network' => $payment['network'],
            'payment_url' => $payment['gateway_url'],
            'pay_page_url' => Ax402_WC_Order_Payment::pay_page_url($order),
            'status_url' => rest_url('ax402/v1/orders/' . $order->get_order_key()),
        ], 201);
    }

    public function get_order(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $order_key = (string) $request['order_key'];
        $order_id = wc_get_order_id_by_order_key($order_key);
        if (!$order_id) {
            return new WP_Error('ax402_not_found', 'Order not found', ['status' => 404]);
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return new WP_Error('ax402_not_found', 'Order not found', ['status' => 404]);
        }

        // Gateway may settle on-chain without reaching shop fulfill (e.g. tunnel blocks).
        Ax402_WC_Settlement_Reconcile::reconcile_order($order);
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return new WP_Error('ax402_not_found', 'Order not found', ['status' => 404]);
        }

        $downloads = [];
        if ($order->is_paid()) {
            foreach ($order->get_downloadable_items() as $item) {
                $downloads[] = [
                    'product_id' => $item['product_id'] ?? null,
                    'download_url' => $item['download_url'] ?? null,
                    'download_name' => $item['download_name'] ?? null,
                ];
            }
        }

        return new WP_REST_Response([
            'order_id' => $order->get_id(),
            'order_key' => $order->get_order_key(),
            'status' => $order->get_status(),
            'paid' => $order->is_paid(),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'amount_usdc' => (string) $order->get_meta(Ax402_WC_Order_Payment::META_AMOUNT_USDC),
            'network' => (string) $order->get_meta(Ax402_WC_Order_Payment::META_NETWORK),
            'payment_url' => (string) $order->get_meta(Ax402_WC_Order_Payment::META_GATEWAY_URL),
            'thank_you_url' => $order->is_paid() ? $order->get_checkout_order_received_url() : null,
            'downloads' => $downloads,
        ], 200);
    }
}
