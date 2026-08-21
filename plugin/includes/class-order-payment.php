<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Creates / updates per-order Ax402 payment endpoints.
 *
 * Each settlement token gets its own temporary endpoint (single accept) so the
 * gateway never co-lists currencies on one path.
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

        $platform = $client->get_platform_config();
        $supported = null;
        try {
            $supported = $client->get_supported_networks();
        } catch (Throwable $e) {
            $supported = Ax402_WC_Platform_Config_Store::get()['supported_networks'];
        }
        Ax402_WC_Platform_Config_Store::store($platform, '', is_array($supported) ? $supported : null);

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

        self::delete_endpoint_ids_for_order($order, $client);

        $fulfill_token = (string) $order->get_meta(self::META_FULFILL_TOKEN);
        if ($fulfill_token === '') {
            $fulfill_token = bin2hex(random_bytes(16));
        }

        $order_key = $order->get_order_key();
        /** @var array<string, array{api_id:string,gateway_host:string,gateway_url:string,api:array<string,mixed>,family:string}> $api_by_family */
        $api_by_family = [];
        $upstream_auth = null;

        $display_options = [];
        foreach ($options as $option) {
            $token_id = (string) $option['token_id'];
            $network = (string) $option['network'];
            $symbol = (string) $option['symbol'];
            $slug = self::token_path_slug($token_id);
            $path = self::fulfill_path($order_key, $fulfill_token, $slug);

            $pay_to = Ax402_WC_Settings::pay_to_for_network($network);
            if ($pay_to === '') {
                throw new RuntimeException(
                    Ax402_WC_Platform_Tokens::is_hedera_network($network)
                        ? 'Hedera pay-to account id is required for Hedera settlement tokens.'
                        : 'EVM pay-to wallet address is required for EVM settlement tokens.'
                );
            }

            $family = Ax402_WC_Platform_Tokens::is_hedera_network($network)
                ? Ax402_WC_Store_Onboarding::FAMILY_HEDERA
                : Ax402_WC_Store_Onboarding::FAMILY_EVM;
            if (!isset($api_by_family[$family])) {
                $api_by_family[$family] = Ax402_WC_Store_Onboarding::ensure_api_for_family(
                    $family,
                    $client
                );
            }
            $onboarded = $api_by_family[$family];

            if ($upstream_auth === null) {
                $upstream_auth = Ax402_WC_Control_Plane_Client::upstream_auth_for_base_url(
                    (string) ($onboarded['api']['upstream_base_url'] ?? home_url())
                );
            }

            $endpoint = $client->upsert_endpoint(
                $onboarded['api_id'],
                'GET',
                $path,
                [$option['accept']],
                'WooCommerce order #' . $order->get_id() . ' (' . $symbol . ')',
                $upstream_auth
            );

            $gateway_url = Ax402_WC_Platform_Tokens::gateway_url(
                $onboarded['gateway_host'],
                $path,
                $platform
            );

            $display_options[] = self::option_for_meta($option, [
                'apiId' => $onboarded['api_id'],
                'endpointId' => (string) ($endpoint['id'] ?? ''),
                'gatewayUrl' => $gateway_url,
                'path' => $path,
                'tokenSlug' => $slug,
            ]);
        }

        $primary = $display_options[0];
        $order->update_meta_data(self::META_FULFILL_TOKEN, $fulfill_token);
        $order->update_meta_data(self::META_GATEWAY_URL, (string) $primary['gatewayUrl']);
        $order->update_meta_data(self::META_ENDPOINT_ID, (string) $primary['endpointId']);
        $order->update_meta_data(self::META_AMOUNT_USD, $amount_usd);
        $order->update_meta_data(self::META_AMOUNT_USDC, $amount_usd);
        $order->update_meta_data(self::META_AMOUNT_ATOMIC, (string) $primary['amountAtomic']);
        $order->update_meta_data(self::META_NETWORK, (string) $primary['network']);
        $order->update_meta_data(self::META_PATH, (string) $primary['path']);
        $order->update_meta_data(self::META_SETTLEMENT_OPTIONS, wp_json_encode($display_options));
        $order->delete_meta_data(self::META_SELECTED_TOKEN_ID);
        $order->save();

        $symbols = implode(', ', array_map(
            static fn (array $o): string => (string) $o['symbol'],
            $display_options
        ));

        $order->add_order_note(
            sprintf(
                /* translators: 1: USD amount 2: token symbols 3: endpoint count */
                __('Ax402 payment prepared for %1$s USD (%2$s) across %3$d temporary endpoint(s).', 'ax402-for-woocommerce'),
                $amount_usd,
                $symbols,
                count($display_options)
            )
        );

        return [
            'gateway_url' => (string) $primary['gatewayUrl'],
            'path' => (string) $primary['path'],
            'amount_usd' => $amount_usd,
            'amount_usdc' => $amount_usd,
            'network' => (string) $primary['network'],
            'endpoint_id' => (string) $primary['endpointId'],
            'fulfill_token' => $fulfill_token,
            'settlement_options' => $display_options,
        ];
    }

    /**
     * Re-prepare payment when admin-enabled tokens (with rates) differ from order meta.
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
            $supported = null;
            try {
                $supported = $client->get_supported_networks();
            } catch (Throwable $e) {
                $supported = Ax402_WC_Platform_Config_Store::get()['supported_networks'];
            }
            Ax402_WC_Platform_Config_Store::store($platform, '', is_array($supported) ? $supported : null);
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
        $stored = self::settlement_options_from_order($order);
        $stored_ids = self::sorted_token_ids_from_meta($stored);

        $has_endpoints = true;
        foreach ($stored as $option) {
            if ((string) ($option['endpointId'] ?? '') === '' || (string) ($option['gatewayUrl'] ?? '') === '') {
                $has_endpoints = false;
                break;
            }
        }

        if ($live_ids !== [] && $live_ids === $stored_ids && $has_endpoints && $stored !== []) {
            return;
        }

        self::prepare($order, $client);
    }

    /**
     * Select the pre-created per-token endpoint for the shopper’s settlement choice.
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
            self::prepare($order, $client);
            $options = self::settlement_options_from_order($order);
            foreach ($options as $option) {
                if ((string) ($option['tokenId'] ?? '') === $token_id) {
                    $selected = $option;
                    break;
                }
            }
        }
        if ($selected === null) {
            throw new InvalidArgumentException('Settlement token is not available for this order');
        }

        $atomic = (string) ($selected['amountAtomic'] ?? '');
        if ($atomic === '' || !preg_match('/^\d+$/', $atomic)) {
            throw new RuntimeException('Invalid settlement amount for token');
        }

        $endpoint_id = (string) ($selected['endpointId'] ?? '');
        $gateway_url = (string) ($selected['gatewayUrl'] ?? '');
        $path = (string) ($selected['path'] ?? '');
        if ($endpoint_id === '' || $gateway_url === '') {
            self::prepare($order, $client);
            $options = self::settlement_options_from_order($order);
            foreach ($options as $option) {
                if ((string) ($option['tokenId'] ?? '') === $token_id) {
                    $selected = $option;
                    $endpoint_id = (string) ($selected['endpointId'] ?? '');
                    $gateway_url = (string) ($selected['gatewayUrl'] ?? '');
                    $path = (string) ($selected['path'] ?? '');
                    $atomic = (string) ($selected['amountAtomic'] ?? $atomic);
                    break;
                }
            }
        }

        if ($endpoint_id === '' || $gateway_url === '') {
            throw new RuntimeException('Settlement endpoint is not ready for this token');
        }

        $symbol = (string) ($selected['symbol'] ?? 'TOKEN');

        $order->update_meta_data(self::META_SELECTED_TOKEN_ID, $token_id);
        $order->update_meta_data(self::META_ENDPOINT_ID, $endpoint_id);
        $order->update_meta_data(self::META_GATEWAY_URL, $gateway_url);
        if ($path !== '') {
            $order->update_meta_data(self::META_PATH, $path);
        }
        $order->update_meta_data(self::META_AMOUNT_ATOMIC, $atomic);
        $order->update_meta_data(self::META_NETWORK, (string) ($selected['network'] ?? ''));
        $order->save();

        return [
            'token_id' => $token_id,
            'symbol' => $symbol,
            'amount' => (string) ($selected['amount'] ?? ''),
            'amount_atomic' => $atomic,
            'endpoint_id' => $endpoint_id,
            'gateway_url' => $gateway_url,
        ];
    }

    /**
     * @return list<string>
     */
    public static function endpoint_ids_from_order(WC_Order $order): array
    {
        $ids = [];
        foreach (self::settlement_options_from_order($order) as $option) {
            $id = (string) ($option['endpointId'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        $primary = (string) $order->get_meta(self::META_ENDPOINT_ID);
        if ($primary !== '') {
            $ids[$primary] = true;
        }

        return array_keys($ids);
    }

    /**
     * Stable short slug for fulfill path uniqueness.
     */
    public static function token_path_slug(string $token_id): string
    {
        return substr(hash('sha256', $token_id), 0, 12);
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
     * @param array<string,mixed> $option built settlement option
     * @param array{apiId:string,endpointId:string,gatewayUrl:string,path:string,tokenSlug:string} $endpoint
     * @return array<string,mixed>
     */
    private static function option_for_meta(array $option, array $endpoint): array
    {
        return [
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
            'rateDate' => (string) ($option['rate_date'] ?? ''),
            'rateSource' => (string) ($option['rate_source'] ?? ''),
            'capturedAt' => (string) ($option['captured_at'] ?? ''),
            'chainIdHex' => (string) $option['chain_id_hex'],
            'rpcUrl' => (string) $option['rpc_url'],
            'blockExplorerUrl' => (string) ($option['explorer_url'] ?? ''),
            'isNative' => !empty($option['is_hedera'])
                ? Ax402_WC_Platform_Tokens::is_hedera_native_asset((string) $option['asset'])
                : Ax402_WC_Platform_Tokens::is_native_asset((string) $option['asset']),
            'isHedera' => !empty($option['is_hedera']),
            'apiId' => $endpoint['apiId'],
            'endpointId' => $endpoint['endpointId'],
            'gatewayUrl' => $endpoint['gatewayUrl'],
            'path' => $endpoint['path'],
            'tokenSlug' => $endpoint['tokenSlug'],
        ];
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

    public static function fulfill_path(
        string $order_key,
        string $fulfill_token,
        string $token_slug = ''
    ): string {
        $base = '/wp-json/ax402/v1/fulfill/'
            . rawurlencode($order_key)
            . '/'
            . rawurlencode($fulfill_token);
        if ($token_slug === '') {
            return $base;
        }

        return $base . '/' . rawurlencode($token_slug);
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

    public static function delete_endpoint_for_order(
        WC_Order $order,
        ?Ax402_WC_Control_Plane_Client $client = null
    ): void {
        self::delete_endpoint_ids_for_order($order, $client);
    }

    private static function delete_endpoint_ids_for_order(
        WC_Order $order,
        ?Ax402_WC_Control_Plane_Client $client = null
    ): void {
        $by_endpoint = [];
        foreach (self::settlement_options_from_order($order) as $option) {
            $endpoint_id = (string) ($option['endpointId'] ?? '');
            if ($endpoint_id === '') {
                continue;
            }
            $by_endpoint[$endpoint_id] = (string) ($option['apiId'] ?? '');
        }
        $primary = (string) $order->get_meta(self::META_ENDPOINT_ID);
        if ($primary !== '' && !isset($by_endpoint[$primary])) {
            $by_endpoint[$primary] = '';
        }
        if ($by_endpoint === []) {
            return;
        }

        $fallback_api_ids = Ax402_WC_Settings::configured_api_ids();
        if ($fallback_api_ids === []) {
            return;
        }

        $client ??= Ax402_WC_Settings::client();
        if ($client === null) {
            return;
        }

        foreach ($by_endpoint as $endpoint_id => $api_id) {
            $candidates = $api_id !== '' ? [$api_id] : $fallback_api_ids;
            $deleted = false;
            $last_error = '';
            foreach ($candidates as $candidate_api_id) {
                try {
                    $client->delete_endpoint($candidate_api_id, $endpoint_id);
                    $deleted = true;
                    break;
                } catch (Throwable $e) {
                    $last_error = $e->getMessage();
                }
            }
            if (!$deleted && $last_error !== '') {
                $order->add_order_note('Ax402 endpoint cleanup failed: ' . $last_error);
            }
        }

        $order->delete_meta_data(self::META_ENDPOINT_ID);
        $order->save();
    }
}
