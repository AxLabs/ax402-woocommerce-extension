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
    public const META_AMOUNT_ATOMIC = '_ax402_amount_atomic';
    public const META_NETWORK = '_ax402_network';
    public const META_PATH = '_ax402_path_pattern';

    /**
     * Prepare Ax402 payment for a WooCommerce order.
     *
     * @return array{gateway_url:string,path:string,amount_usdc:string,network:string,endpoint_id:string,fulfill_token:string}
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
        $network = Ax402_WC_Platform_Tokens::network_for_mode($settings['network_mode']);
        $token = Ax402_WC_Platform_Tokens::find_usdc_token($platform, $network);

        $amount_usdc = Ax402_WC_Money::normalize_order_total($order->get_total());
        $amount_atomic = Ax402_WC_Money::usdc_to_atomic($amount_usdc);

        $fulfill_token = (string) $order->get_meta(self::META_FULFILL_TOKEN);
        if ($fulfill_token === '') {
            $fulfill_token = bin2hex(random_bytes(16));
        }

        $order_key = $order->get_order_key();
        $path = self::fulfill_path($order_key, $fulfill_token);
        $accept = Ax402_WC_Platform_Tokens::build_accept($token, $amount_atomic, $settings['scheme']);

        $endpoint = $client->upsert_endpoint(
            $onboarded['api_id'],
            'GET',
            $path,
            [$accept],
            'WooCommerce order #' . $order->get_id()
        );

        $gateway_url = Ax402_WC_Platform_Tokens::gateway_url($onboarded['gateway_host'], $path, $platform);

        $order->update_meta_data(self::META_FULFILL_TOKEN, $fulfill_token);
        $order->update_meta_data(self::META_GATEWAY_URL, $gateway_url);
        $order->update_meta_data(self::META_ENDPOINT_ID, (string) ($endpoint['id'] ?? ''));
        $order->update_meta_data(self::META_AMOUNT_USDC, $amount_usdc);
        $order->update_meta_data(self::META_AMOUNT_ATOMIC, $amount_atomic);
        $order->update_meta_data(self::META_NETWORK, $network);
        $order->update_meta_data(self::META_PATH, $path);
        $order->save();

        $order->add_order_note(
            sprintf(
                /* translators: 1: USDC amount 2: gateway URL */
                __('Ax402 payment prepared for %1$s USDC. Gateway: %2$s', 'ax402-woocommerce'),
                $amount_usdc,
                $gateway_url
            )
        );

        return [
            'gateway_url' => $gateway_url,
            'path' => $path,
            'amount_usdc' => $amount_usdc,
            'network' => $network,
            'endpoint_id' => (string) ($endpoint['id'] ?? ''),
            'fulfill_token' => $fulfill_token,
        ];
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
