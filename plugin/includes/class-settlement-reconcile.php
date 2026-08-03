<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Complete unpaid orders when Ax402 already recorded an on-chain settlement
 * but the gateway never reached the shop fulfill upstream (common with tunnels).
 */
final class Ax402_WC_Settlement_Reconcile
{
    private const TRANSIENT_TTL = 15;

    /**
     * Match an Ax402 settlement to a prepared order endpoint.
     *
     * Endpoint id is authoritative: each order prep creates a unique Ax402
     * endpoint. Do not require META_AMOUNT_ATOMIC (primary/USDC) to equal the
     * settlement amount — buyers may pay any accept (ZCHF, XGAS, …), and the
     * gateway may record a divergent atomic for 18-decimal assets.
     *
     * @param list<array<string, mixed>> $settlements
     * @param list<string> $acceptable_amounts optional hint; preferred when several rows share an endpoint
     * @return array<string, mixed>|null
     */
    public static function find_matching_settlement(
        array $settlements,
        string $endpoint_id,
        string $amount_atomic = '',
        array $acceptable_amounts = []
    ): ?array {
        if ($endpoint_id === '') {
            return null;
        }

        $amounts = [];
        foreach ($acceptable_amounts as $a) {
            $a = (string) $a;
            if ($a !== '') {
                $amounts[$a] = true;
            }
        }
        if ($amount_atomic !== '') {
            $amounts[$amount_atomic] = true;
        }

        $fallback = null;
        foreach ($settlements as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['endpoint_id'] ?? '') !== $endpoint_id) {
                continue;
            }

            $settled = (string) ($row['amount'] ?? '');
            if ($amounts !== [] && isset($amounts[$settled])) {
                return $row;
            }

            $fallback ??= $row;
        }

        return $fallback;
    }

    /**
     * @param mixed $payload
     * @return list<array<string, mixed>>
     */
    public static function normalize_settlements_payload(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        $items = $payload['items'] ?? null;
        if (is_array($items)) {
            return array_values(array_filter($items, 'is_array'));
        }

        return [];
    }

    /**
     * Mark the order paid when a matching Ax402 settlement exists.
     *
     * @return bool True when the order is paid after this call.
     */
    public static function reconcile_order(
        WC_Order $order,
        ?Ax402_WC_Control_Plane_Client $client = null
    ): bool {
        if (!Ax402_WC_Settings::settlement_reconcile_enabled()) {
            return false;
        }

        if ($order->is_paid()) {
            return true;
        }

        if (!Ax402_WC_Fulfill_Auth::can_fulfill_status($order->get_status())) {
            return false;
        }

        if ($order->get_payment_method() !== Ax402_WC_Gateway_Ax402::GATEWAY_ID) {
            return false;
        }

        $endpoint_ids = Ax402_WC_Order_Payment::endpoint_ids_from_order($order);
        if ($endpoint_ids === []) {
            return false;
        }

        $client ??= Ax402_WC_Settings::client();
        if ($client === null) {
            return false;
        }

        $settings = Ax402_WC_Settings::all();
        $api_ids = Ax402_WC_Settings::configured_api_ids($settings);
        if ($api_ids === []) {
            return false;
        }

        try {
            // Always refresh during reconcile so pay-page status polls see new
            // settlements within ~1s instead of waiting out the transient TTL.
            $settlements = [];
            foreach ($api_ids as $api_id) {
                foreach (self::fetch_settlements($client, $api_id, true) as $row) {
                    $settlements[] = $row;
                }
            }
        } catch (Throwable $e) {
            $order->add_order_note('Ax402 settlement reconcile failed: ' . $e->getMessage());
            $order->save();
            return false;
        }

        $amount_atomic = (string) $order->get_meta(Ax402_WC_Order_Payment::META_AMOUNT_ATOMIC);
        $acceptable = [];
        foreach (Ax402_WC_Order_Payment::settlement_options_from_order($order) as $option) {
            if (!is_array($option)) {
                continue;
            }
            $atomic = (string) ($option['amountAtomic'] ?? $option['amount_atomic'] ?? '');
            if ($atomic !== '') {
                $acceptable[] = $atomic;
            }
        }

        $match = null;
        foreach ($endpoint_ids as $endpoint_id) {
            $match = self::find_matching_settlement(
                $settlements,
                $endpoint_id,
                $amount_atomic,
                $acceptable
            );
            if ($match !== null) {
                break;
            }
        }
        if ($match === null) {
            return false;
        }

        $tx = (string) ($match['tx'] ?? '');
        if ($tx !== '') {
            $order->set_transaction_id($tx);
        }

        $order->payment_complete($tx !== '' ? $tx : '');
        $order->add_order_note(
            sprintf(
                /* translators: 1: settlement id 2: transaction hash */
                __('Ax402 payment verified via settlement reconcile (settlement %1$s, tx %2$s). Gateway upstream fulfill was missing.', 'ax402-for-woocommerce'),
                (string) ($match['id'] ?? ''),
                $tx !== '' ? $tx : 'n/a'
            )
        );

        if (self::order_is_virtual_downloadable($order) && $order->has_status('processing')) {
            $order->update_status(
                'completed',
                __('Virtual/downloadable order auto-completed after Ax402 payment.', 'ax402-for-woocommerce')
            );
        }

        Ax402_WC_Order_Payment::delete_endpoint_for_order($order, $client);

        return $order->is_paid();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetch_settlements(
        Ax402_WC_Control_Plane_Client $client,
        string $api_id,
        bool $bypass_cache = false
    ): array {
        $cache_key = 'ax402_wc_settlements_' . md5($api_id);
        if (!$bypass_cache) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return self::normalize_settlements_payload($cached);
            }
        }

        $rows = $client->list_settlements($api_id);
        set_transient($cache_key, $rows, self::TRANSIENT_TTL);

        return $rows;
    }

    private static function order_is_virtual_downloadable(WC_Order $order): bool
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
