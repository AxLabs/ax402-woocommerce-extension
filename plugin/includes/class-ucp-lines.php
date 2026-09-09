<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Shared line-item and buyer writes for UCP cart and checkout.
 */
final class Ax402_WC_Ucp_Lines
{
    /**
     * @param list<mixed> $line_items
     */
    public static function replace(WC_Order $order, array $line_items): void
    {
        $resolved = self::resolve($line_items);

        // WooCommerce 11 defers remove_order_items() until save(), then deletes
        // every persisted line_item row. add_product() already INSERTs, so a
        // later save() drops those rows and GET returns line_items: [].
        if ($order->get_id() > 0 && $order->get_items('line_item') !== []) {
            $order->remove_order_items('line_item');
            $order->save();
        }

        foreach ($resolved as $row) {
            $order->add_product($row['product'], $row['qty']);
        }
    }

    /**
     * @param list<mixed> $line_items
     * @return list<array{product: WC_Product, qty: int}>
     */
    public static function resolve(array $line_items): array
    {
        $resolved = [];
        foreach ($line_items as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Each line_item must be an object.');
            }
            $item = is_array($row['item'] ?? null) ? $row['item'] : [];
            $id = trim((string) ($item['id'] ?? ''));
            $qty = max(1, (int) ($row['quantity'] ?? 1));
            if ($id === '') {
                throw new InvalidArgumentException('line_items[].item.id is required.');
            }
            $product = Ax402_WC_Ucp_Catalog::resolve_purchasable($id);
            if (!$product instanceof WC_Product) {
                throw new InvalidArgumentException(esc_html('Unknown or unpurchasable item id: ' . $id));
            }
            $resolved[] = [
                'product' => $product,
                'qty' => $qty,
            ];
        }
        if ($resolved === []) {
            throw new InvalidArgumentException('At least one purchasable line item is required.');
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $buyer
     */
    public static function apply_buyer(WC_Order $order, array $buyer): void
    {
        if (isset($buyer['email'])) {
            $email = sanitize_email((string) $buyer['email']);
            if ($email !== '') {
                $order->set_billing_email($email);
            }
        }
        if (isset($buyer['first_name'])) {
            $order->set_billing_first_name(sanitize_text_field((string) $buyer['first_name']));
        }
        if (isset($buyer['last_name'])) {
            $order->set_billing_last_name(sanitize_text_field((string) $buyer['last_name']));
        }
        if (isset($buyer['phone_number'])) {
            $order->set_billing_phone(sanitize_text_field((string) $buyer['phone_number']));
        }
    }

    /**
     * Cart `context` hints for estimated shipping/tax.
     *
     * @param array<string, mixed> $context
     */
    public static function apply_context(WC_Order $order, array $context): void
    {
        $country = strtoupper(trim((string) ($context['address_country'] ?? $context['country'] ?? '')));
        $region = trim((string) ($context['address_region'] ?? $context['region'] ?? $context['state'] ?? ''));
        $postcode = trim((string) ($context['postal_code'] ?? $context['postcode'] ?? $context['zip'] ?? ''));
        if ($country === '' && $region === '' && $postcode === '') {
            return;
        }
        if ($country !== '') {
            $order->set_shipping_country($country);
            if ($order->get_billing_country() === '') {
                $order->set_billing_country($country);
            }
        }
        if ($region !== '') {
            $order->set_shipping_state($region);
            if ($order->get_billing_state() === '') {
                $order->set_billing_state($region);
            }
        }
        if ($postcode !== '') {
            $order->set_shipping_postcode($postcode);
            if ($order->get_billing_postcode() === '') {
                $order->set_billing_postcode($postcode);
            }
        }
    }
}
