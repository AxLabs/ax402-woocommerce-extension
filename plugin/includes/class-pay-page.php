<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Human pay page that mounts the Ax402 React paywall against the gateway URL.
 */
final class Ax402_WC_Pay_Page
{
    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybe_render'], 0);
    }

    /**
     * @return array{dependencies: array<int, string>, version: string}
     */
    private function asset_meta(): array
    {
        $asset_file = AX402_WC_PLUGIN_DIR . 'build/pay-page.asset.php';
        $asset = is_readable($asset_file)
            ? require $asset_file
            : ['dependencies' => [], 'version' => AX402_WC_VERSION];

        $dependencies = array_values(array_unique(array_merge(
            is_array($asset['dependencies'] ?? null) ? $asset['dependencies'] : [],
            ['wp-element', 'react', 'react-dom']
        )));

        return [
            'dependencies' => $dependencies,
            'version' => (string) ($asset['version'] ?? AX402_WC_VERSION),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function page_config(WC_Order $order, string $gateway_url): array
    {
        $settings = Ax402_WC_Settings::all();
        $amount_usd = (string) $order->get_meta(Ax402_WC_Order_Payment::META_AMOUNT_USD);
        if ($amount_usd === '') {
            $amount_usd = (string) $order->get_meta(Ax402_WC_Order_Payment::META_AMOUNT_USDC);
        }
        if ($amount_usd === '') {
            $amount_usd = Ax402_WC_Money::normalize_order_total($order->get_total());
        }

        $options = Ax402_WC_Order_Payment::settlement_options_from_order($order);
        if ($options === []) {
            try {
                $platform = Ax402_WC_Platform_Config_Store::platform_or_sync();
                $token_ids = Ax402_WC_Settings::enabled_token_ids($platform);
                $rebuilt = Ax402_WC_Platform_Tokens::build_settlement_options(
                    $platform,
                    $token_ids,
                    $amount_usd,
                    $settings['scheme']
                );
                foreach ($rebuilt as $row) {
                    $options[] = [
                        'tokenId' => (string) $row['token_id'],
                        'symbol' => (string) $row['symbol'],
                        'name' => (string) $row['name'],
                        'network' => (string) $row['network'],
                        'networkLabel' => (string) $row['network_label'],
                        'asset' => (string) $row['asset'],
                        'decimals' => (int) $row['decimals'],
                        'amount' => (string) $row['amount'],
                        'amountAtomic' => (string) $row['amount_atomic'],
                        'rate' => (string) $row['rate'],
                        'chainIdHex' => (string) $row['chain_id_hex'],
                        'rpcUrl' => (string) $row['rpc_url'],
                        'blockExplorerUrl' => (string) ($row['explorer_url'] ?? ''),
                        'isNative' => Ax402_WC_Platform_Tokens::is_native_asset((string) $row['asset']),
                    ];
                }
            } catch (Throwable $e) {
                $options = [];
            }
        }

        foreach ($options as &$option) {
            if (empty($option['blockExplorerUrl']) && !empty($option['network'])) {
                $option['blockExplorerUrl'] = Ax402_WC_Platform_Tokens::explorer_url_for_network(
                    (string) $option['network']
                );
            }
        }
        unset($option);

        $primary = $options[0] ?? [
            'network' => '',
            'asset' => '',
            'rpcUrl' => '',
        ];
        $order_key = $order->get_order_key();
        $rpc_by_network = Ax402_WC_Platform_Tokens::rpc_urls();

        // Ensure this store origin is allowed on the Ax402 gateway, then pay directly.
        $settings_full = Ax402_WC_Settings::all();
        if ($settings_full['api_id'] !== '') {
            Ax402_WC_Gateway_Cors::ensure_store_origins($settings_full['api_id']);
        }
        $cors = Ax402_WC_Gateway_Cors::status();

        return [
            // Browser talks to the Ax402 gateway directly (CORS managed via control plane).
            'gatewayUrl' => $gateway_url,
            'proxyGatewayUrl' => rest_url('ax402/v1/pay-proxy/' . $order_key),
            'directGatewayUrl' => $gateway_url,
            'corsOrigins' => $cors['origins'],
            'corsError' => $cors['error'],
            'orderId' => $order->get_id(),
            'orderKey' => $order_key,
            'amountUsd' => $amount_usd,
            'amountUsdc' => $amount_usd,
            'network' => (string) ($primary['network'] ?? ''),
            'allowedAssets' => (string) ($primary['asset'] ?? ''),
            'statusUrl' => rest_url('ax402/v1/orders/' . $order_key),
            'thankYouUrl' => $order->get_checkout_order_received_url(),
            'preferredNetworks' => (string) ($primary['network'] ?? ''),
            'rpcUrl' => (string) ($primary['rpcUrl'] ?? ''),
            'rpcByNetwork' => $rpc_by_network,
            'settlementOptions' => $options,
            'shopUrl' => wc_get_page_permalink('shop') ?: home_url('/'),
        ];
    }

    public function maybe_render(): void
    {
        if (!isset($_GET['ax402_pay']) || (string) $_GET['ax402_pay'] !== '1') {
            return;
        }

        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '';
        if ($key === '') {
            wp_die(esc_html__('Missing order key.', 'ax402-woocommerce'), 400);
        }

        $order_id = wc_get_order_id_by_order_key($key);
        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order instanceof WC_Order) {
            wp_die(esc_html__('Order not found.', 'ax402-woocommerce'), 404);
        }

        if ($order->is_paid()) {
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        $gateway_url = (string) $order->get_meta(Ax402_WC_Order_Payment::META_GATEWAY_URL);
        if ($gateway_url === '') {
            try {
                $prepared = Ax402_WC_Order_Payment::prepare($order);
                $gateway_url = $prepared['gateway_url'];
            } catch (Throwable $e) {
                wp_die(
                    esc_html(
                        sprintf(
                            /* translators: %s: error message */
                            __('Payment is not ready yet: %s', 'ax402-woocommerce'),
                            $e->getMessage()
                        )
                    ),
                    esc_html__('Ax402 payment', 'ax402-woocommerce'),
                    ['response' => 409]
                );
            }
        }

        $config = $this->page_config($order, $gateway_url);
        $asset = $this->asset_meta();
        $config_json = wp_json_encode($config);

        // Register + enqueue before any localize/inline data (template_redirect runs before wp_enqueue_scripts).
        wp_register_script(
            'ax402-wc-pay-page',
            AX402_WC_PLUGIN_URL . 'build/pay-page.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );
        wp_register_style(
            'ax402-wc-pay-page',
            AX402_WC_PLUGIN_URL . 'build/pay-page.css',
            [],
            $asset['version']
        );

        wp_enqueue_script('ax402-wc-pay-page');
        wp_enqueue_style('ax402-wc-pay-page');

        // Prefer inline bootstrap so React never mounts without config.
        wp_add_inline_script(
            'ax402-wc-pay-page',
            'window.ax402PayPage = ' . $config_json . ';',
            'before'
        );

        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo esc_html__('Pay with Ax402', 'ax402-woocommerce'); ?></title>
    <?php wp_head(); ?>
    <style>
        :root {
            --ax402-bg0: #070b14;
            --ax402-bg1: #101a2e;
            --ax402-text: #eef3ff;
            --ax402-muted: #9db0d0;
            --ax402-accent: #7cffb2;
            --ax402-card: rgba(255,255,255,0.04);
            --ax402-border: rgba(255,255,255,0.10);
            --ax402-warn: #ffd27a;
            --ax402-error: #ff8f8f;
        }
        body.ax402-pay-body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
            color: var(--ax402-text);
            background:
                radial-gradient(1000px 500px at 10% -10%, rgba(124,255,178,0.16), transparent 55%),
                radial-gradient(900px 480px at 90% 0%, rgba(90,140,255,0.18), transparent 50%),
                linear-gradient(180deg, var(--ax402-bg1), var(--ax402-bg0));
        }
        .ax402-pay-shell {
            max-width: 560px;
            margin: 0 auto;
            padding: 2.75rem 1.25rem 4rem;
        }
        .ax402-pay-brand {
            font-size: 0.85rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--ax402-accent);
            margin: 0 0 0.75rem;
            font-weight: 700;
        }
        .ax402-pay-shell h1 {
            font-size: clamp(1.6rem, 3vw, 2rem);
            line-height: 1.15;
            margin: 0 0 0.65rem;
        }
        .ax402-pay-lead {
            color: var(--ax402-muted);
            margin: 0 0 1.5rem;
            line-height: 1.5;
        }
        .ax402-pay-card {
            border: 1px solid var(--ax402-border);
            background: var(--ax402-card);
            border-radius: 18px;
            padding: 1.25rem;
            backdrop-filter: blur(8px);
        }
        .ax402-order-summary {
            display: grid;
            gap: 0.75rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--ax402-border);
        }
        .ax402-order-summary > div {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            font-size: 0.95rem;
        }
        .ax402-order-summary span { color: var(--ax402-muted); }
        .ax402-order-summary strong { color: var(--ax402-text); }
        .ax402-settle { margin: 0 0 1rem; }
        .ax402-settle-label {
            margin: 0 0 0.5rem;
            color: var(--ax402-muted);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .ax402-settle-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 0.5rem;
        }
        .ax402-settle-option {
            width: 100%;
            text-align: left;
            border: 1px solid var(--ax402-border);
            background: rgba(0,0,0,0.18);
            color: var(--ax402-text);
            border-radius: 12px;
            padding: 0.7rem 0.85rem;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }
        .ax402-settle-option.is-active {
            border-color: var(--ax402-accent);
            box-shadow: 0 0 0 1px rgba(124,255,178,0.35);
        }
        .ax402-settle-symbol { font-weight: 700; }
        .ax402-settle-meta { color: var(--ax402-muted); font-size: 0.88rem; }
        .ax402-settle-option:disabled { cursor: default; opacity: 1; }
        .ax402-settle-hint {
            margin: 0.55rem 0 0;
            color: var(--ax402-muted);
            font-size: 0.82rem;
            line-height: 1.4;
        }
        .ax402-banner {
            border-radius: 12px;
            padding: 0.85rem 0.95rem;
            margin: 0 0 0.85rem;
            border: 1px solid var(--ax402-border);
        }
        .ax402-banner p { margin: 0 0 0.65rem; line-height: 1.4; }
        .ax402-banner-warn { background: rgba(255,210,122,0.08); color: var(--ax402-warn); }
        .ax402-banner-error { background: rgba(255,143,143,0.08); color: var(--ax402-error); }
        .ax402-banner-btn {
            border: 0;
            border-radius: 10px;
            padding: 0.55rem 0.9rem;
            background: var(--ax402-accent);
            color: #061018;
            font-weight: 700;
            cursor: pointer;
        }
        .ax402-banner-btn:disabled { opacity: 0.6; cursor: wait; }
        .ax402-ready-error { color: var(--ax402-error); font-size: 0.9rem; }
        .ax402-pay-gate.is-blocked {
            opacity: 0.45;
            pointer-events: none;
            filter: grayscale(0.2);
        }
        #ax402-pay-root { min-height: 220px; }
        .ax402-pay-help {
            margin-top: 1.25rem;
            color: var(--ax402-muted);
            font-size: 0.9rem;
            line-height: 1.45;
        }
        .ax402-pay-help a { color: var(--ax402-accent); }
    </style>
</head>
<body class="ax402-pay-body">
    <main class="ax402-pay-shell">
        <p class="ax402-pay-brand">Ax402</p>
        <h1><?php echo esc_html__('Pay securely with your wallet', 'ax402-woocommerce'); ?></h1>
        <p class="ax402-pay-lead">
            <?php
            $option_count = count($config['settlementOptions'] ?? []);
            echo esc_html(
                $option_count > 1
                    ? __('Choose a settlement token, connect your wallet, and confirm the payment. We finalize the order automatically.', 'ax402-woocommerce')
                    : __('Connect your wallet and confirm the payment. We finalize the order automatically.', 'ax402-woocommerce')
            );
            ?>
        </p>
        <section class="ax402-pay-card">
            <div id="ax402-pay-root">
                <p class="ax402-pay-lead"><?php echo esc_html__('Loading payment…', 'ax402-woocommerce'); ?></p>
            </div>
        </section>
        <p class="ax402-pay-help">
            <?php echo esc_html__('Use the network shown for your selected token. Need to leave?', 'ax402-woocommerce'); ?>
            <a href="<?php echo esc_url((string) $config['shopUrl']); ?>">
                <?php echo esc_html__('Return to shop', 'ax402-woocommerce'); ?>
            </a>
        </p>
    </main>
    <script>
        window.ax402PayPage = <?php echo $config_json ? $config_json : '{}'; ?>;
    </script>
    <?php wp_footer(); ?>
</body>
</html>
        <?php
        exit;
    }
}
