<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * UCP cart: lightweight basket before checkout. Same Woo order as checkout
 * after cart_id conversion (idempotent).
 */
final class Ax402_WC_Ucp_Cart
{
    public const CAP = 'dev.ucp.shopping.cart';
    public const META_ORIGIN = '_ax402_ucp_origin_cart';

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
                    'Store currency must be USD for Ax402 UCP cart.',
                    'unrecoverable'
                )],
                self::CAP
            );
        }

        $line_items = $body['line_items'] ?? null;
        if (!is_array($line_items) || $line_items === []) {
            return Ax402_WC_Ucp_Response::rest_error(
                400,
                [Ax402_WC_Ucp_Response::message('error', 'invalid', 'line_items is required', 'unrecoverable')],
                self::CAP
            );
        }

        $order = wc_create_order();
        if (is_wp_error($order) || !$order instanceof WC_Order) {
            return new WP_Error('ax402_ucp_cart', 'Could not create cart', ['status' => 500]);
        }

        try {
            Ax402_WC_Ucp_Lines::replace($order, $line_items);
            Ax402_WC_Ucp_Lines::apply_buyer($order, is_array($body['buyer'] ?? null) ? $body['buyer'] : []);
            if (isset($body['context']) && is_array($body['context'])) {
                Ax402_WC_Ucp_Lines::apply_context($order, $body['context']);
            }
            $order->set_payment_method(Ax402_WC_Gateway_Ax402::GATEWAY_ID);
            $order->set_payment_method_title('Ax402');
            $order->update_meta_data(Ax402_WC_Ucp_Mapper::META_UCP, '1');
            $order->update_meta_data(self::META_ORIGIN, '1');
            Ax402_WC_Ucp_Kind::set($order, Ax402_WC_Ucp_Kind::CART);
            Ax402_WC_Ucp_Fulfillment::sync_shipping($order);
            $order->calculate_totals();
            $order->update_status('pending', __('UCP cart session.', 'ax402-for-woocommerce'));
            $order->save();
        } catch (InvalidArgumentException $e) {
            $order->delete(true);
            return Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message('error', 'invalid', $e->getMessage(), 'unrecoverable')],
                self::CAP
            );
        } catch (Throwable $e) {
            $order->delete(true);
            self::log_failure('create', $e);
            return Ax402_WC_Ucp_Response::rest_error(
                500,
                [Ax402_WC_Ucp_Response::message(
                    'error',
                    'processing_error',
                    'Could not create the cart.',
                    'unrecoverable'
                )],
                self::CAP
            );
        }

        return new WP_REST_Response(self::payload($order), 201);
    }

    public function get(string $cart_id): WP_REST_Response|WP_Error
    {
        $order = self::from_id($cart_id);
        if (!$order instanceof WC_Order || $order->has_status(['cancelled', 'canceled', 'refunded', 'failed'])) {
            return Ax402_WC_Ucp_Response::rest_error(
                404,
                [Ax402_WC_Ucp_Response::message('error', 'not_found', 'Cart not found.', 'unrecoverable')],
                self::CAP
            );
        }

        return new WP_REST_Response(self::payload($order), 200);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function update(string $cart_id, array $body): WP_REST_Response|WP_Error
    {
        $order = self::from_id($cart_id);
        if (!$order instanceof WC_Order || $order->has_status(['cancelled', 'canceled', 'refunded', 'failed'])) {
            return Ax402_WC_Ucp_Response::rest_error(
                404,
                [Ax402_WC_Ucp_Response::message('error', 'not_found', 'Cart not found.', 'unrecoverable')],
                self::CAP
            );
        }
        if ($order->is_paid()) {
            return Ax402_WC_Ucp_Response::rest_error(
                409,
                [Ax402_WC_Ucp_Response::message('error', 'conflict', 'Paid carts cannot be updated.', 'unrecoverable')],
                self::CAP,
                ['id' => $cart_id]
            );
        }

        $line_items = $body['line_items'] ?? null;
        if (!is_array($line_items) || $line_items === []) {
            return Ax402_WC_Ucp_Response::rest_error(
                400,
                [Ax402_WC_Ucp_Response::message('error', 'invalid', 'line_items is required', 'unrecoverable')],
                self::CAP
            );
        }

        try {
            Ax402_WC_Ucp_Lines::replace($order, $line_items);
            if (isset($body['buyer']) && is_array($body['buyer'])) {
                Ax402_WC_Ucp_Lines::apply_buyer($order, $body['buyer']);
            }
            if (isset($body['context']) && is_array($body['context'])) {
                Ax402_WC_Ucp_Lines::apply_context($order, $body['context']);
            }
            Ax402_WC_Ucp_Fulfillment::sync_shipping($order);
            $order->calculate_totals();
            if (Ax402_WC_Ucp_Kind::get($order) === Ax402_WC_Ucp_Kind::CHECKOUT) {
                (new Ax402_WC_Ucp_Checkout())->maybe_prepare($order);
            }
            $order->save();
        } catch (InvalidArgumentException $e) {
            return Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message('error', 'invalid', $e->getMessage(), 'recoverable')],
                self::CAP
            );
        } catch (Throwable $e) {
            self::log_failure('update', $e);
            return Ax402_WC_Ucp_Response::rest_error(
                500,
                [Ax402_WC_Ucp_Response::message(
                    'error',
                    'processing_error',
                    'Could not update the cart.',
                    'unrecoverable'
                )],
                self::CAP
            );
        }

        return new WP_REST_Response(self::payload($order), 200);
    }

    public function cancel(string $cart_id): WP_REST_Response|WP_Error
    {
        $order = self::from_id($cart_id);
        if (!$order instanceof WC_Order) {
            return Ax402_WC_Ucp_Response::rest_error(
                404,
                [Ax402_WC_Ucp_Response::message('error', 'not_found', 'Cart not found.', 'unrecoverable')],
                self::CAP
            );
        }
        if ($order->is_paid()) {
            return Ax402_WC_Ucp_Response::rest_error(
                409,
                [Ax402_WC_Ucp_Response::message('error', 'conflict', 'Paid carts cannot be canceled.', 'unrecoverable')],
                self::CAP
            );
        }

        $payload = self::payload($order);
        if (!$order->has_status(['cancelled', 'canceled'])) {
            $order->update_status('cancelled', __('UCP cart canceled by agent.', 'ax402-for-woocommerce'));
        }

        return new WP_REST_Response($payload, 200);
    }

    public static function from_id(string $cart_id): ?WC_Order
    {
        $order = Ax402_WC_Ucp_Checkout::order_from_key($cart_id);
        if (!$order instanceof WC_Order) {
            return null;
        }
        if ((string) $order->get_meta(self::META_ORIGIN) !== '1') {
            return null;
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(WC_Order $order): array
    {
        $payload = [
            'ucp' => Ax402_WC_Ucp_Response::ucp(self::CAP),
            'id' => $order->get_order_key(),
            'currency' => Ax402_WC_Ucp_Money::CURRENCY,
            'line_items' => Ax402_WC_Ucp_Mapper::line_items($order),
            'totals' => Ax402_WC_Ucp_Mapper::totals($order),
            'links' => Ax402_WC_Ucp_Mapper::links(),
            'continue_url' => self::continue_url(),
        ];

        $buyer = Ax402_WC_Ucp_Mapper::buyer($order);
        if ($buyer !== []) {
            $payload['buyer'] = $buyer;
        }

        $created = $order->get_date_created();
        if ($created instanceof \DateTimeInterface) {
            $payload['expires_at'] = gmdate('c', $created->getTimestamp() + 6 * 3600);
        }

        Ax402_WC_Ucp_Leak::assert_clean($payload, Ax402_WC_Settings::all());

        return $payload;
    }

    private static function log_failure(string $op, Throwable $e): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error(
                'UCP cart ' . $op . ': ' . $e->getMessage(),
                ['source' => 'ax402-ucp']
            );
        }
    }

    private static function continue_url(): string
    {
        if (function_exists('wc_get_page_permalink')) {
            $shop = wc_get_page_permalink('shop');
            if (is_string($shop) && $shop !== '') {
                return $shop;
            }
        }

        return home_url('/');
    }
}
