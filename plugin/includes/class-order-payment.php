<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Creates / updates per-order Ax402 payment endpoints.
 */
final class Ax402_WC_Order_Payment
{
    public const META_GATEWAY_URL = '_ax402_gateway_url';
    public const META_ENDPOINT_ID = '_ax402_endpoint_id';
    public const META_FULFILL_TOKEN = '_ax402_fulfill_token';
    public const META_AMOUNT_USDC = '_ax402_amount_usdc';
    public const META_AMOUNT_USD = '_ax402_amount_usd';
    public const META_AMOUNT_ATOMIC = '_ax402_amount_atomic';
    public const META_NETWORK = '_ax402_network';
    public const META_PATH = '_ax402_path_pattern';
    public const META_SETTLEMENT_OPTIONS = '_ax402_settlement_options';
    public const META_SELECTED_TOKEN_ID = '_ax402_selected_token_id';

    /**
     * Prepare Ax402 payment for a WooCommerce order.
     *
     * @return array{
     *   gateway_url:string,
     *   path:string,
     *   amount_usd:string,
     *   amount_usdc:string,
     *   network:string,
     *   endpoint_id:string,
     *   fulfill_token:string,
     *   settlement_options:list<array<string,mixed>>
     * }
     */
    public static function prepare(WC_Order $order, ?Ax402_WC_Control_Plane_Client $client = null): array
    {
        if (get_woocommerce_currency() !== 'USD') {
            throw new RuntimeException('Ax402 v0 requires store currency USD');
        }

        $settings = Ax402_WC_Settings::all();
        $client ??= Ax402_WC_Settings::client();
        if ($client === null) {
            throw new RuntimeException('Ax402 is not configured');
        }

        $onboarded = Ax402_WC_Store_Onboarding::ensure_api($client);
        $platform = $client->get_platform_config();
        Ax402_WC_Platform_Config_Store::store($platform);

        $amount_usd = Ax402_WC_Money::normalize_order_total($order->get_total());
        $token_ids = Ax402_WC_Settings::enabled_token_ids($platform);
        Ax402_WC_Control_Plane_Exchange_Rates::clear_cache_for_base_url($settings['base_url']);
        $options = Ax402_WC_Platform_Tokens::build_settlement_options(
            $platform,
            $token_ids,
            $amount_usd,
            $settings['scheme']
        );

        if ($options === []) {
            throw new RuntimeException(
                'No settlement tokens available with a resolvable USD rate. Enable tokens in Ax402 settings.'
            );
        }

        $accepts = array_map(
            static fn (array $option): array => $option['accept'],
            $options
        );

        $fulfill_token = (string) $order->get_meta(self::META_FULFILL_TOKEN);
        if ($fulfill_token === '') {
            $fulfill_token = bin2hex(random_bytes(16));
        }

        $order_key = $order->get_order_key();
        $path = self::fulfill_path($order_key, $fulfill_token);

        $upstream_auth = Ax402_WC_Control_Plane_Client::upstream_auth_for_base_url(
            (string) ($onboarded['api']['upstream_base_url'] ?? home_url())
        );

        $endpoint = $client->upsert_endpoint(
            $onboarded['api_id'],
            'GET',
            $path,
            $accepts,
            'WooCommerce order #' . $order->get_id(),
            $upstream_auth
        );

        $gateway_url = Ax402_WC_Platform_Tokens::gateway_url($onboarded['gateway_host'], $path, $platform);
        $primary = $options[0];
        $display_options = self::options_for_meta($options);

        $order->update_meta_data(self::META_FULFILL_TOKEN, $fulfill_token);
        $order->update_meta_data(self::META_GATEWAY_URL, $gateway_url);
        $order->update_meta_data(self::META_ENDPOINT_ID, (string) ($endpoint['id'] ?? ''));
        $order->update_meta_data(self::META_AMOUNT_USD, $amount_usd);
        // Backward-compatible meta name: USD catalog total (historically USDC 1:1).
        $order->update_meta_data(self::META_AMOUNT_USDC, $amount_usd);
        $order->update_meta_data(self::META_AMOUNT_ATOMIC, (string) $primary['amount_atomic']);
        $order->update_meta_data(self::META_NETWORK, (string) $primary['network']);
        $order->update_meta_data(self::META_PATH, $path);
        $order->update_meta_data(self::META_SETTLEMENT_OPTIONS, wp_json_encode($display_options));
        $order->save();

        $symbols = implode(', ', array_map(
            static fn (array $o): string => (string) $o['symbol'],
            $options
        ));

        $order->add_order_note(
            sprintf(
                /* translators: 1: USD amount 2: token symbols 3: gateway URL */
                __('Ax402 payment prepared for %1$s USD (%2$s). Gateway: %3$s', 'ax402-woocommerce'),
                $amount_usd,
                $symbols,
                $gateway_url
            )
        );

        return [
            'gateway_url' => $gateway_url,
            'path' => $path,
            'amount_usd' => $amount_usd,
            'amount_usdc' => $amount_usd,
            'network' => (string) $primary['network'],
            'endpoint_id' => (string) ($endpoint['id'] ?? ''),
            'fulfill_token' => $fulfill_token,
            'settlement_options' => $display_options,
        ];
    }

    /**
     * Re-prepare payment when admin-enabled tokens (with rates) differ from order meta.
     *
     * Checkout may have frozen a single-token snapshot before the merchant enabled
     * ZCHF/XGAS; the pay page must show the current selectable set and update the
     * Ax402 endpoint accepts[] accordingly.
     */
    public static function ensure_current_settlement_options(
        WC_Order $order,
        ?Ax402_WC_Control_Plane_Client $client = null
    ): void {
        if ($order->is_paid()) {
            return;
        }

        $settings = Ax402_WC_Settings::all();
        $client ??= Ax402_WC_Settings::client();
        if ($client === null) {
            return;
        }

        Ax402_WC_Control_Plane_Exchange_Rates::clear_cache_for_base_url($settings['base_url']);

        try {
            $platform = $client->get_platform_config();
            Ax402_WC_Platform_Config_Store::store($platform);
        } catch (Throwable $e) {
            $platform = Ax402_WC_Platform_Config_Store::platform_or_sync();
        }

        if ($platform === []) {
            return;
        }

        $token_ids = Ax402_WC_Settings::enabled_token_ids($platform);
        $amount_usd = Ax402_WC_Money::normalize_order_total($order->get_total());
        $live = Ax402_WC_Platform_Tokens::build_settlement_options(
            $platform,
            $token_ids,
            $amount_usd,
            $settings['scheme']
        );
        $live_ids = self::sorted_token_ids_from_built($live);
        $stored_ids = self::sorted_token_ids_from_meta(
            self::settlement_options_from_order($order)
        );

        $gateway_url = (string) $order->get_meta(self::META_GATEWAY_URL);
        if ($live_ids !== [] && $live_ids === $stored_ids && $gateway_url !== '') {
            return;
        }

        self::prepare($order, $client);
    }

    /**
     * Narrow the Ax402 endpoint to a single settlement token at its FX amount.
     *
     * The Ax402 gateway rewrites Base non-USDC accepts to USD×10^decimals when
     * multiple accepts share the Base network (ZCHF becomes "USD as ZCHF").
     * Human checkout therefore locks one accept — the converted token amount —
     * before the wallet paywall inspects the gateway URL.
     *
     * @return array{
     *   token_id:string,
     *   symbol:string,
     *   amount:string,
     *   amount_atomic:string,
     *   endpoint_id:string,
     *   gateway_url:string
     * }
     */
    public static function lock_settlement_token(
        WC_Order $order,
        string $token_id,
        ?Ax402_WC_Control_Plane_Client $client = null
    ): array {
        if ($order->is_paid()) {
            throw new RuntimeException('Order is already paid');
        }

        if (!Ax402_WC_Fulfill_Auth::can_fulfill_status($order->get_status())) {
            throw new RuntimeException('Order cannot accept payment in status ' . $order->get_status());
        }

        $token_id = trim($token_id);
        if ($token_id === '') {
            throw new InvalidArgumentException('tokenId is required');
        }

        $settings = Ax402_WC_Settings::all();
        $client ??= Ax402_WC_Settings::client();
        if ($client === null) {
            throw new RuntimeException('Ax402 is not configured');
        }

        $options = self::settlement_options_from_order($order);
        if ($options === []) {
            self::ensure_current_settlement_options($order, $client);
            $options = self::settlement_options_from_order($order);
        }

        $selected = null;
        foreach ($options as $option) {
            if ((string) ($option['tokenId'] ?? '') === $token_id) {
                $selected = $option;
                break;
            }
        }
        if ($selected === null) {
            throw new InvalidArgumentException('Settlement token is not available for this order');
        }

        $atomic = (string) ($selected['amountAtomic'] ?? '');
        if ($atomic === '' || !preg_match('/^\d+$/', $atomic)) {
            throw new RuntimeException('Invalid settlement amount for token');
        }

        $path = (string) $order->get_meta(self::META_PATH);
        if ($path === '' || (string) $order->get_meta(self::META_GATEWAY_URL) === '') {
            self::prepare($order, $client);
            $path = (string) $order->get_meta(self::META_PATH);
            $options = self::settlement_options_from_order($order);
            foreach ($options as $option) {
                if ((string) ($option['tokenId'] ?? '') === $token_id) {
                    $selected = $option;
                    $atomic = (string) ($selected['amountAtomic'] ?? $atomic);
                    break;
                }
            }
        }

        $onboarded = Ax402_WC_Store_Onboarding::ensure_api($client);
        $platform = $client->get_platform_config();
        Ax402_WC_Platform_Config_Store::store($platform);

        $token = Ax402_WC_Platform_Tokens::find_token_by_id($platform, $token_id);
        if ($token === null) {
            throw new RuntimeException('Settlement token was not found on the Ax402 platform');
        }

        $accept = Ax402_WC_Platform_Tokens::build_accept($token, $atomic, $settings['scheme']);
        $symbol = (string) ($selected['symbol'] ?? $token['symbol'] ?? 'TOKEN');

        $upstream_auth = Ax402_WC_Control_Plane_Client::upstream_auth_for_base_url(
            (string) ($onboarded['api']['upstream_base_url'] ?? home_url())
        );

        $endpoint = $client->upsert_endpoint(
            $onboarded['api_id'],
            'GET',
            $path,
            [$accept],
            'WooCommerce order #' . $order->get_id() . ' (' . $symbol . ')',
            $upstream_auth
        );

        $gateway_url = Ax402_WC_Platform_Tokens::gateway_url(
            $onboarded['gateway_host'],
            $path,
            $platform
        );

        $order->update_meta_data(self::META_SELECTED_TOKEN_ID, $token_id);
        $order->update_meta_data(self::META_ENDPOINT_ID, (string) ($endpoint['id'] ?? ''));
        $order->update_meta_data(self::META_GATEWAY_URL, $gateway_url);
        $order->update_meta_data(self::META_AMOUNT_ATOMIC, $atomic);
        $order->update_meta_data(self::META_NETWORK, (string) ($selected['network'] ?? $token['network'] ?? ''));
        $order->save();

        return [
            'token_id' => $token_id,
            'symbol' => $symbol,
            'amount' => (string) ($selected['amount'] ?? ''),
            'amount_atomic' => $atomic,
            'endpoint_id' => (string) ($endpoint['id'] ?? ''),
            'gateway_url' => $gateway_url,
        ];
    }

    /**
     * @param list<array<string,mixed>> $options
     * @return list<string>
     */
    private static function sorted_token_ids_from_built(array $options): array
    {
        $ids = [];
        foreach ($options as $option) {
            $id = (string) ($option['token_id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * @param list<array<string,mixed>> $options
     * @return list<string>
     */
    private static function sorted_token_ids_from_meta(array $options): array
    {
        $ids = [];
        foreach ($options as $option) {
            $id = (string) ($option['tokenId'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * @param list<array<string,mixed>> $options
     * @return list<array<string,mixed>>
     */
    private static function options_for_meta(array $options): array
    {
        $out = [];
        foreach ($options as $option) {
            $out[] = [
                'tokenId' => (string) $option['token_id'],
                'symbol' => (string) $option['symbol'],
                'name' => (string) $option['name'],
                'network' => (string) $option['network'],
                'networkLabel' => (string) $option['network_label'],
                'asset' => (string) $option['asset'],
                'decimals' => (int) $option['decimals'],
                'amount' => (string) $option['amount'],
                'amountAtomic' => (string) $option['amount_atomic'],
                'rate' => (string) $option['rate'],
                'chainIdHex' => (string) $option['chain_id_hex'],
                'rpcUrl' => (string) $option['rpc_url'],
                'blockExplorerUrl' => (string) ($option['explorer_url'] ?? ''),
                'isNative' => Ax402_WC_Platform_Tokens::is_native_asset((string) $option['asset']),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function settlement_options_from_order(WC_Order $order): array
    {
        $raw = (string) $order->get_meta(self::META_SETTLEMENT_OPTIONS);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    public static function fulfill_path(string $order_key, string $fulfill_token): string
    {
        return '/wp-json/ax402/v1/fulfill/' . rawurlencode($order_key) . '/' . rawurlencode($fulfill_token);
    }

    public static function pay_page_url(WC_Order $order): string
    {
        return add_query_arg(
            [
                'ax402_pay' => '1',
                'key' => $order->get_order_key(),
            ],
            wc_get_checkout_url()
        );
    }

    public static function delete_endpoint_for_order(WC_Order $order, ?Ax402_WC_Control_Plane_Client $client = null): void
    {
        $endpoint_id = (string) $order->get_meta(self::META_ENDPOINT_ID);
        if ($endpoint_id === '') {
            return;
        }

        $settings = Ax402_WC_Settings::all();
        if ($settings['api_id'] === '') {
            return;
        }

        $client ??= Ax402_WC_Settings::client();
        if ($client === null) {
            return;
        }

        try {
            $client->delete_endpoint($settings['api_id'], $endpoint_id);
            $order->delete_meta_data(self::META_ENDPOINT_ID);
            $order->save();
        } catch (Throwable $e) {
            $order->add_order_note('Ax402 endpoint cleanup failed: ' . $e->getMessage());
            $order->save();
        }
    }
}
