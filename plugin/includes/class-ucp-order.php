<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * UCP order resource (post-purchase). `digital` belongs here as
 * fulfillment.expectations[].method_type, not on checkout methods.
 */
final class Ax402_WC_Ucp_Order
{
    public const CAP = 'dev.ucp.shopping.order';

    public function get(string $id): WP_REST_Response
    {
        $order = self::from_id($id);
        if (!$order instanceof WC_Order) {
            return Ax402_WC_Ucp_Response::rest_error(
                404,
                [Ax402_WC_Ucp_Response::message('error', 'not_found', 'Order not found.', 'unrecoverable')],
                self::CAP
            );
        }

        Ax402_WC_Settlement_Reconcile::reconcile_order($order);
        $fresh = wc_get_order($order->get_id());
        if ($fresh instanceof WC_Order) {
            $order = $fresh;
        }
        if (!$order->is_paid()) {
            return Ax402_WC_Ucp_Response::rest_error(
                404,
                [Ax402_WC_Ucp_Response::message('error', 'not_found', 'Order not found.', 'unrecoverable')],
                self::CAP
            );
        }

        return new WP_REST_Response(self::payload($order), 200);
    }

    public static function from_id(string $id): ?WC_Order
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        $order = null;
        if (ctype_digit($id)) {
            $found = wc_get_order((int) $id);
            if ($found instanceof WC_Order) {
                $order = $found;
            }
        }
        if (!$order instanceof WC_Order) {
            $order = Ax402_WC_Ucp_Checkout::order_from_key($id);
        }
        if (!$order instanceof WC_Order) {
            return null;
        }
        if ($order->get_payment_method() !== Ax402_WC_Gateway_Ax402::GATEWAY_ID) {
            return null;
        }
        if ((string) $order->get_meta(Ax402_WC_Ucp_Mapper::META_UCP) !== '1') {
            return null;
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(WC_Order $order): array
    {
        $line_items = self::line_items($order);
        $payload = [
            'ucp' => Ax402_WC_Ucp_Response::ucp(self::CAP),
            'id' => (string) $order->get_id(),
            'label' => $order->get_order_number(),
            'checkout_id' => $order->get_order_key(),
            'permalink_url' => $order->get_checkout_order_received_url(),
            'line_items' => $line_items,
            'fulfillment' => self::fulfillment($order, $line_items),
            'currency' => Ax402_WC_Ucp_Money::CURRENCY,
            'totals' => Ax402_WC_Ucp_Mapper::totals($order),
        ];

        $adjustments = self::adjustments($order);
        if ($adjustments !== []) {
            $payload['adjustments'] = $adjustments;
        }

        Ax402_WC_Ucp_Leak::assert_clean($payload, Ax402_WC_Settings::all());

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function line_items(WC_Order $order): array
    {
        $completed = $order->has_status(['completed']);
        $rows = [];
        foreach ($order->get_items() as $item_id => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $qty = max(0, $item->get_quantity());
            $refunded = $order->get_qty_refunded_for_item((int) $item_id);
            $total = max(0, $qty - abs($refunded));
            $product = $item->get_product();
            $digital = $product instanceof WC_Product && Ax402_WC_Ucp_Fulfillment::product_is_digital($product);
            $fulfilled = 0;
            if ($completed || ($digital && $order->is_paid())) {
                $fulfilled = $total;
            }
            $status = 'processing';
            if ($total === 0) {
                $status = 'removed';
            } elseif ($fulfilled === $total) {
                $status = 'fulfilled';
            } elseif ($fulfilled > 0) {
                $status = 'partial';
            }

            $unit = Ax402_WC_Ucp_Money::usd_to_cents_exact((string) $item->get_subtotal())
                ?? Ax402_WC_Ucp_Money::usd_to_cents_ceil((string) $item->get_subtotal());
            $line_total = $unit ?? 0;
            $unit_price = $qty > 0 ? (int) round($line_total / $qty) : 0;
            $catalog_id = $product instanceof WC_Product
                ? (string) $product->get_id()
                : (string) $item->get_product_id();

            $rows[] = [
                'id' => 'li_' . (string) $item_id,
                'item' => [
                    'id' => $catalog_id,
                    'title' => $item->get_name(),
                    'price' => $unit_price,
                ],
                'quantity' => [
                    'original' => $qty,
                    'total' => $total,
                    'fulfilled' => $fulfilled,
                ],
                'totals' => [
                    ['type' => 'subtotal', 'amount' => $line_total],
                    ['type' => 'total', 'amount' => $line_total],
                ],
                'status' => $status,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $line_items
     * @return array{expectations: list<array<string, mixed>>, events: list<array<string, mixed>>}
     */
    private static function fulfillment(WC_Order $order, array $line_items): array
    {
        $pickup = Ax402_WC_Ucp_Fulfillment::selected_method_type($order) === 'pickup';
        $by_type = ['digital' => [], 'shipping' => [], 'pickup' => []];
        foreach ($order->get_items() as $item_id => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $qty = max(1, $item->get_quantity());
            $product = $item->get_product();
            $needs = $product instanceof WC_Product && $product->needs_shipping();
            $type = Ax402_WC_Ucp_Fulfillment::expectation_method_type($needs, $pickup);
            $by_type[$type][] = [
                'id' => 'li_' . (string) $item_id,
                'quantity' => $qty,
            ];
        }

        $destination = Ax402_WC_Ucp_Fulfillment::postal_from_order($order);
        if ($pickup) {
            $loc_id = (string) $order->get_meta(Ax402_WC_Ucp_Fulfillment::META_PICKUP_LOCATION);
            foreach (Ax402_WC_Ucp_Fulfillment::pickup_locations() as $loc) {
                if ((string) ($loc['id'] ?? '') === $loc_id && is_array($loc['address'] ?? null)) {
                    $destination = $loc['address'];
                    break;
                }
            }
            if ($destination === []) {
                $destination = Ax402_WC_Ucp_Fulfillment::store_address();
            }
        }

        $expectations = [];
        foreach ($by_type as $type => $refs) {
            if ($refs === []) {
                continue;
            }
            $expectation = [
                'id' => 'exp_' . $type,
                'line_items' => $refs,
                'method_type' => $type,
                'destination' => $destination !== [] ? $destination : ['address_country' => 'US'],
                'fulfillable_on' => 'now',
            ];
            $expectation['description'] = match ($type) {
                'digital' => 'Digital delivery',
                'pickup' => 'Store pickup',
                default => 'Shipping',
            };
            $expectations[] = $expectation;
        }

        $occurred = $order->get_date_paid() ?? $order->get_date_created();
        $when = $occurred instanceof \DateTimeInterface
            ? $occurred->format('c')
            : gmdate('c');
        $event_type = $order->has_status(['completed']) ? 'delivered' : 'processing';
        $event_lines = [];
        foreach ($line_items as $row) {
            $qty = (int) ($row['quantity']['total'] ?? 0);
            if ($qty < 1) {
                continue;
            }
            $event_lines[] = [
                'id' => (string) $row['id'],
                'quantity' => $qty,
            ];
        }
        $events = [];
        if ($event_lines !== []) {
            $events[] = [
                'id' => 'evt_1',
                'occurred_at' => $when,
                'type' => $event_type,
                'line_items' => $event_lines,
            ];
        }

        return [
            'expectations' => $expectations,
            'events' => $events,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function adjustments(WC_Order $order): array
    {
        $refunded = (string) $order->get_total_refunded();
        $cents = Ax402_WC_Ucp_Money::usd_to_cents_ceil($refunded) ?? 0;
        if ($cents <= 0) {
            return [];
        }
        $when = $order->get_date_modified();
        $occurred = $when instanceof \DateTimeInterface ? $when->format('c') : gmdate('c');

        return [[
            'id' => 'adj_refund_1',
            'type' => 'refund',
            'occurred_at' => $occurred,
            'status' => 'completed',
            'totals' => [
                ['type' => 'total', 'amount' => -$cents],
            ],
            'description' => 'Refund',
        ]];
    }
}
