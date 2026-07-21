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

        $endpoint = $client->upsert_endpoint(
            $onboarded['api_id'],
            'GET',
            $path,
            $accepts,
            'WooCommerce order #' . $order->get_id()
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
