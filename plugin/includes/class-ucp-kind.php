<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Distinguishes a UCP cart resource from a checkout session on the same Woo order.
 */
final class Ax402_WC_Ucp_Kind
{
    public const META = '_ax402_ucp_kind';
    public const CART = 'cart';
    public const CHECKOUT = 'checkout';

    public static function get(WC_Order $order): string
    {
        $kind = (string) $order->get_meta(self::META);
        if ($kind === self::CART || $kind === self::CHECKOUT) {
            return $kind;
        }

        return self::CHECKOUT;
    }

    public static function set(WC_Order $order, string $kind): void
    {
        $order->update_meta_data(self::META, $kind);
    }

    public static function is_cart(WC_Order $order): bool
    {
        return self::get($order) === self::CART;
    }
}
