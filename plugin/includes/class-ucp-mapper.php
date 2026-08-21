<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * WC_Order → UCP checkout session JSON (no gateway fields).
 */
final class Ax402_WC_Ucp_Mapper
{
    public const META_UCP = '_ax402_ucp_session';
    public const META_PREPARED_TOTAL = '_ax402_ucp_prepared_total';
    public const META_AGENT = '_ax402_ucp_agent';

    /**
     * @param list<array<string, string>> $extra_messages
     * @return array<string, mixed>
     */
    public static function session(WC_Order $order, array $extra_messages = []): array
    {
        $computed = self::status_for_order($order);
        $status = $computed['status'];
        $messages = array_merge($computed['messages'], $extra_messages);

        $ucp = Ax402_WC_Ucp_Response::ucp(
            'dev.ucp.shopping.checkout',
            ['payment_handlers' => Ax402_WC_Ucp_Response::session_payment_handlers()]
        );

        $payload = [
            'ucp' => $ucp,
            'id' => $order->get_order_key(),
            'status' => $status,
            'currency' => Ax402_WC_Ucp_Money::CURRENCY,
            'line_items' => self::line_items($order),
            'totals' => self::totals($order),
            'links' => self::links($order, $status),
        ];

        $buyer = self::buyer($order);
        if ($buyer !== []) {
            $payload['buyer'] = $buyer;
        }

        $fulfillment = Ax402_WC_Ucp_Fulfillment::map_for_session($order);
        if ($fulfillment !== null) {
            $payload['fulfillment'] = $fulfillment;
        }

        if ($status === Ax402_WC_Ucp_Status::READY) {
            $messages[] = Ax402_WC_Ucp_Response::payment_required_message(
                $order->get_order_key(),
                'info'
            );
        }

        if ($messages !== []) {
            $payload['messages'] = $messages;
        }

        $created = $order->get_date_created();
        if ($created instanceof \DateTimeInterface) {
            $payload['expires_at'] = gmdate('c', $created->getTimestamp() + 6 * 3600);
        }

        if ($status === Ax402_WC_Ucp_Status::COMPLETED && $order->is_paid()) {
            $payload['order'] = [
                'id' => (string) $order->get_id(),
                'permalink_url' => $order->get_checkout_order_received_url(),
            ];
            $payload['payment'] = self::payment_after_settle($order);
        }

        $quote = self::quote($order);
        if ($quote !== null) {
            $payload['quote'] = $quote;
        }

        Ax402_WC_Ucp_Leak::assert_clean($payload, Ax402_WC_Settings::all());

        return $payload;
    }

    /**
     * @return array{status:string, messages:list<array<string,string>>}
     */
    public static function status_for_order(WC_Order $order): array
    {
        if ($order->is_paid()) {
            return Ax402_WC_Ucp_Status::compute(true, false, false, false, false, false, true, true);
        }
        if ($order->has_status(['cancelled', 'canceled', 'refunded', 'failed'])) {
            return Ax402_WC_Ucp_Status::compute(false, true, false, false, false, false, true, true);
        }

        $needs = Ax402_WC_Ucp_Fulfillment::order_needs_shipping($order);
        $has_dest = Ax402_WC_Ucp_Fulfillment::has_destination($order);
        $rates = $needs && $has_dest
            ? Ax402_WC_Ucp_Fulfillment::calculate_rates($order)
            : [];

        $has_items = false;
        $total = (float) $order->get_total();
        $in_stock = true;
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $has_items = true;
            $product = $item->get_product();
            if ($product instanceof WC_Product && !$product->is_in_stock()) {
                $in_stock = false;
                break;
            }
        }

        if (!$has_items) {
            return [
                'status' => Ax402_WC_Ucp_Status::INCOMPLETE,
                'messages' => [Ax402_WC_Ucp_Response::message(
                    'error',
                    'invalid',
                    'At least one line item is required.',
                    'recoverable',
                    '$.line_items'
                )],
            ];
        }

        return Ax402_WC_Ucp_Status::compute(
            false,
            false,
            $needs,
            $has_dest,
            Ax402_WC_Ucp_Fulfillment::has_shipping_selection($order),
            $rates !== [],
            $total > 0,
            $in_stock
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function line_items(WC_Order $order): array
    {
        $rows = [];
        foreach ($order->get_items() as $item_id => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $unit = Ax402_WC_Ucp_Money::usd_to_cents_exact((string) $item->get_subtotal())
                ?? Ax402_WC_Ucp_Money::usd_to_cents_ceil((string) $item->get_subtotal());
            $qty = max(1, $item->get_quantity());
            $unit_price = $unit !== null ? (int) round($unit / $qty) : 0;
            $line_total = $unit ?? 0;
            $product = $item->get_product();
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
                'quantity' => $qty,
                'totals' => [
                    ['type' => 'subtotal', 'amount' => $line_total],
                    ['type' => 'total', 'amount' => $line_total],
                ],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{type:string, amount:int}>
     */
    public static function totals(WC_Order $order): array
    {
        $subtotal = Ax402_WC_Ucp_Money::usd_to_cents_ceil((string) $order->get_subtotal()) ?? 0;
        $shipping = Ax402_WC_Ucp_Money::usd_to_cents_ceil((string) $order->get_shipping_total()) ?? 0;
        $tax = Ax402_WC_Ucp_Money::usd_to_cents_ceil((string) $order->get_total_tax()) ?? 0;
        $total = Ax402_WC_Ucp_Money::usd_to_cents_ceil((string) $order->get_total()) ?? 0;

        $rows = [
            ['type' => 'subtotal', 'amount' => $subtotal],
        ];
        if ($shipping > 0) {
            $rows[] = ['type' => 'fulfillment', 'amount' => $shipping];
        }
        if ($tax > 0) {
            $rows[] = ['type' => 'tax', 'amount' => $tax];
        }
        $rows[] = ['type' => 'total', 'amount' => $total];

        return $rows;
    }

    /**
     * @return list<array{type:string, url:string, title?:string}>
     */
    public static function links(?WC_Order $order = null, string $status = ''): array
    {
        $links = [];
        if (function_exists('wc_get_page_permalink')) {
            foreach ([
                'terms' => 'terms_of_service',
                'privacy' => 'privacy_policy',
            ] as $page => $type) {
                $url = wc_get_page_permalink($page);
                if (is_string($url) && $url !== '' && $url !== home_url('/')) {
                    $links[] = ['type' => $type, 'url' => $url];
                }
            }
        }
        if ($order instanceof WC_Order && $status === Ax402_WC_Ucp_Status::READY) {
            $links[] = Ax402_WC_Ucp_Response::payment_complete_link($order->get_order_key());
        }

        return $links;
    }

    /**
     * @return array<string, string>
     */
    public static function buyer(WC_Order $order): array
    {
        $buyer = [];
        $email = $order->get_billing_email();
        if ($email !== '') {
            $buyer['email'] = $email;
        }
        $first = $order->get_billing_first_name();
        if ($first !== '') {
            $buyer['first_name'] = $first;
        }
        $last = $order->get_billing_last_name();
        if ($last !== '') {
            $buyer['last_name'] = $last;
        }

        return $buyer;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function quote(WC_Order $order): ?array
    {
        $options = Ax402_WC_Order_Payment::settlement_options_from_order($order);
        $selected = (string) $order->get_meta(Ax402_WC_Order_Payment::META_SELECTED_TOKEN_ID);
        $candidate = null;
        foreach ($options as $option) {
            $source = (string) ($option['rateSource'] ?? '');
            $rate = (string) ($option['rate'] ?? '');
            if ($source === 'stablecoin-1to1' || $rate === '1') {
                continue;
            }
            if ($selected !== '' && (string) ($option['tokenId'] ?? '') === $selected) {
                $candidate = $option;
                break;
            }
            $candidate ??= $option;
        }
        if ($candidate === null) {
            return null;
        }

        $captured = (string) ($candidate['capturedAt'] ?? '');
        $rate = (string) ($candidate['rate'] ?? '');
        if ($rate === '' || $captured === '') {
            return null;
        }

        return [
            'rate' => $rate,
            'base' => 'USD',
            'target' => (string) ($candidate['symbol'] ?? ''),
            'captured_at' => $captured,
            'source' => (string) ($candidate['rateSource'] ?? 'ax402-control-plane'),
        ];
    }

    /**
     * @return array{instruments: list<array<string, mixed>>}
     */
    public static function payment_after_settle(WC_Order $order): array
    {
        $network = (string) $order->get_meta(Ax402_WC_Order_Payment::META_NETWORK);
        $options = Ax402_WC_Order_Payment::settlement_options_from_order($order);
        $selected = (string) $order->get_meta(Ax402_WC_Order_Payment::META_SELECTED_TOKEN_ID);
        $symbol = 'TOKEN';
        foreach ($options as $option) {
            if ($selected !== '' && (string) ($option['tokenId'] ?? '') === $selected) {
                $symbol = (string) ($option['symbol'] ?? $symbol);
                $network = (string) ($option['network'] ?? $network);
                break;
            }
        }

        $tx = (string) $order->get_transaction_id();
        $instrument = [
            'id' => 'instr_x402_1',
            'handler_id' => 'org.x402.payment',
            'type' => 'x402',
            'selected' => true,
            'display' => [
                'network' => $network,
                'asset' => $symbol,
            ],
        ];
        if ($tx !== '') {
            $instrument['display']['transaction'] = $tx;
        }

        return ['instruments' => [$instrument]];
    }
}
