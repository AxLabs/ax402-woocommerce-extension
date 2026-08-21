<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * UCP checkout-session create / get / update / cancel.
 *
 * Ax402 prepare() runs only when the session is ready_for_complete so shipping
 * and tax are in the charged total.
 */
final class Ax402_WC_Ucp_Checkout
{
    /**
     * @param array<string, mixed> $body
     */
    public function create(array $body): WP_REST_Response|WP_Error
    {
        if (get_woocommerce_currency() !== 'USD') {
            return Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message(
                    'error',
                    'unsupported_currency',
                    'Store currency must be USD for Ax402 UCP checkout.',
                    'unrecoverable'
                )]
            );
        }

        $cart_id = trim((string) ($body['cart_id'] ?? ''));
        if ($cart_id !== '') {
            return $this->create_from_cart($cart_id);
        }

        $line_items = $body['line_items'] ?? null;
        if (!is_array($line_items) || $line_items === []) {
            return Ax402_WC_Ucp_Response::rest_error(
                400,
                [Ax402_WC_Ucp_Response::message('error', 'invalid', 'line_items is required', 'unrecoverable')]
            );
        }

        $order = wc_create_order();
        if (is_wp_error($order) || !$order instanceof WC_Order) {
            return new WP_Error('ax402_ucp_create', 'Could not create checkout session', ['status' => 500]);
        }

        try {
            Ax402_WC_Ucp_Lines::replace($order, $line_items);
            Ax402_WC_Ucp_Lines::apply_buyer($order, is_array($body['buyer'] ?? null) ? $body['buyer'] : []);
            if (isset($body['fulfillment']) && is_array($body['fulfillment'])) {
                Ax402_WC_Ucp_Fulfillment::apply_request($order, $body['fulfillment']);
            }

            $order->set_payment_method(Ax402_WC_Gateway_Ax402::GATEWAY_ID);
            $order->set_payment_method_title('Ax402');
            $order->update_meta_data(Ax402_WC_Ucp_Mapper::META_UCP, '1');
            Ax402_WC_Ucp_Kind::set($order, Ax402_WC_Ucp_Kind::CHECKOUT);
            Ax402_WC_Ucp_Fulfillment::sync_shipping($order);
            $order->calculate_totals();
            $order->update_status('pending', __('UCP checkout session awaiting Ax402 payment.', 'ax402-for-woocommerce'));

            $this->maybe_prepare($order);
            $order->save();
        } catch (InvalidArgumentException $e) {
            $order->delete(true);
            return Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message('error', 'invalid', $e->getMessage(), 'unrecoverable')]
            );
        } catch (Throwable $e) {
            $order->delete(true);
            self::log_failure('create', $e);
            return Ax402_WC_Ucp_Response::rest_error(
                500,
                [Ax402_WC_Ucp_Response::message(
                    'error',
                    'processing_error',
                    'Could not create the checkout session.',
                    'unrecoverable'
                )]
            );
        }

        return new WP_REST_Response(Ax402_WC_Ucp_Mapper::session($order), 201);
    }

    public function get(string $session_id): WP_REST_Response|WP_Error
    {
        $order = self::order_from_session($session_id);
        if (!$order instanceof WC_Order) {
            return Ax402_WC_Ucp_Response::rest_error(
                404,
                [Ax402_WC_Ucp_Response::message('error', 'not_found', 'Checkout session not found.', 'unrecoverable')]
            );
        }

        Ax402_WC_Settlement_Reconcile::reconcile_order($order);
        $fresh = wc_get_order($order->get_id());
        if ($fresh instanceof WC_Order) {
            $order = $fresh;
        }

        return new WP_REST_Response(Ax402_WC_Ucp_Mapper::session($order), 200);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function update(string $session_id, array $body): WP_REST_Response|WP_Error
    {
        $order = self::order_from_session($session_id);
        if (!$order instanceof WC_Order) {
            return Ax402_WC_Ucp_Response::rest_error(
                404,
                [Ax402_WC_Ucp_Response::message('error', 'not_found', 'Checkout session not found.', 'unrecoverable')]
            );
        }
        if ($order->is_paid()) {
            return new WP_REST_Response(Ax402_WC_Ucp_Mapper::session($order), 200);
        }
        if ($order->has_status(['cancelled', 'canceled'])) {
            return Ax402_WC_Ucp_Response::rest_error(
                409,
                [Ax402_WC_Ucp_Response::message('error', 'canceled', 'Checkout session is canceled.', 'unrecoverable')],
                'dev.ucp.shopping.checkout',
                ['id' => $session_id]
            );
        }

        try {
            if (isset($body['line_items']) && is_array($body['line_items']) && $body['line_items'] !== []) {
                Ax402_WC_Ucp_Lines::replace($order, $body['line_items']);
            }
            if (isset($body['buyer']) && is_array($body['buyer'])) {
                Ax402_WC_Ucp_Lines::apply_buyer($order, $body['buyer']);
            }
            if (isset($body['fulfillment']) && is_array($body['fulfillment'])) {
                Ax402_WC_Ucp_Fulfillment::apply_request($order, $body['fulfillment']);
            }
            Ax402_WC_Ucp_Fulfillment::sync_shipping($order);
            $order->calculate_totals();
            $this->maybe_prepare($order);
            $order->save();
        } catch (InvalidArgumentException $e) {
            return Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message('error', 'invalid', $e->getMessage(), 'recoverable')]
            );
        } catch (Throwable $e) {
            self::log_failure('update', $e);
            return Ax402_WC_Ucp_Response::rest_error(
                500,
                [Ax402_WC_Ucp_Response::message(
                    'error',
                    'processing_error',
                    'Could not update the checkout session.',
                    'unrecoverable'
                )]
            );
        }

        return new WP_REST_Response(Ax402_WC_Ucp_Mapper::session($order), 200);
    }

    public function cancel(string $session_id): WP_REST_Response|WP_Error
    {
        $order = self::order_from_session($session_id);
        if (!$order instanceof WC_Order) {
            return Ax402_WC_Ucp_Response::rest_error(
                404,
                [Ax402_WC_Ucp_Response::message('error', 'not_found', 'Checkout session not found.', 'unrecoverable')]
            );
        }
        if ($order->is_paid()) {
            return Ax402_WC_Ucp_Response::rest_error(
                409,
                [Ax402_WC_Ucp_Response::message('error', 'conflict', 'Paid sessions cannot be canceled.', 'unrecoverable')]
            );
        }
        if (!$order->has_status(['cancelled', 'canceled'])) {
            $order->update_status('cancelled', __('UCP checkout session canceled by agent.', 'ax402-for-woocommerce'));
        }

        $fresh = wc_get_order($order->get_id());
        if ($fresh instanceof WC_Order) {
            $order = $fresh;
        }

        return new WP_REST_Response(Ax402_WC_Ucp_Mapper::session($order), 200);
    }

    public static function order_from_key(string $session_id): ?WC_Order
    {
        $order_id = wc_get_order_id_by_order_key($session_id);
        if (!$order_id) {
            return null;
        }
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return null;
        }
        if ($order->get_payment_method() !== Ax402_WC_Gateway_Ax402::GATEWAY_ID) {
            return null;
        }

        return $order;
    }

    public static function order_from_session(string $session_id): ?WC_Order
    {
        $order = self::order_from_key($session_id);
        if (!$order instanceof WC_Order) {
            return null;
        }
        if (Ax402_WC_Ucp_Kind::is_cart($order)) {
            return null;
        }

        return $order;
    }

    /**
     * Convert (or resume) a cart. Overlapping checkout fields are ignored.
     */
    public function create_from_cart(string $cart_id): WP_REST_Response|WP_Error
    {
        $order = Ax402_WC_Ucp_Cart::from_id($cart_id);
        if (!$order instanceof WC_Order || $order->has_status(['cancelled', 'canceled', 'refunded', 'failed'])) {
            return Ax402_WC_Ucp_Response::rest_error(
                404,
                [Ax402_WC_Ucp_Response::message('error', 'not_found', 'Cart not found.', 'unrecoverable')]
            );
        }

        $was_cart = Ax402_WC_Ucp_Kind::is_cart($order);
        if ($was_cart && !$order->is_paid()) {
            Ax402_WC_Ucp_Kind::set($order, Ax402_WC_Ucp_Kind::CHECKOUT);
            $this->maybe_prepare($order);
            $order->save();
        }

        Ax402_WC_Settlement_Reconcile::reconcile_order($order);
        $fresh = wc_get_order($order->get_id());
        if ($fresh instanceof WC_Order) {
            $order = $fresh;
        }

        return new WP_REST_Response(Ax402_WC_Ucp_Mapper::session($order), $was_cart ? 201 : 200);
    }

    /**
     * Prepare Ax402 endpoints only for a final, ready total.
     */
    public function maybe_prepare(WC_Order $order): void
    {
        $computed = Ax402_WC_Ucp_Mapper::status_for_order($order);
        if ($computed['status'] !== Ax402_WC_Ucp_Status::READY) {
            return;
        }

        $current = Ax402_WC_Money::normalize_order_total($order->get_total());
        $prepared = (string) $order->get_meta(Ax402_WC_Ucp_Mapper::META_PREPARED_TOTAL);
        $options = Ax402_WC_Order_Payment::settlement_options_from_order($order);
        if ($prepared === $current && $options !== []) {
            return;
        }

        Ax402_WC_Order_Payment::prepare($order);
        $order->update_meta_data(Ax402_WC_Ucp_Mapper::META_PREPARED_TOTAL, $current);
        $order->save();
    }

    private static function log_failure(string $op, Throwable $e): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error(
                'UCP checkout ' . $op . ': ' . $e->getMessage(),
                ['source' => 'ax402-ucp']
            );
        }
    }
}
