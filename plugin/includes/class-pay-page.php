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
        add_action('woocommerce_thankyou_ax402', [$this, 'render_order_received_cta'], 5);
    }

    /**
     * Pending Ax402 orders on the thank-you page need a path back to the wallet pay UI.
     */
    public function render_order_received_cta(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }
        if ($order->get_payment_method() !== Ax402_WC_Gateway_Ax402::GATEWAY_ID) {
            return;
        }

        Ax402_WC_Settlement_Reconcile::reconcile_order($order);
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        if ($order->is_paid() || !$order->has_status(['pending', 'on-hold', 'failed'])) {
            return;
        }

        $pay_url = Ax402_WC_Order_Payment::pay_page_url($order);
        $message = sprintf(
            '%1$s <a href="%2$s" class="button">%3$s</a>',
            esc_html__(
                'This order is still awaiting Ax402 payment. Continue to the wallet pay page to finish checkout.',
                'ax402-for-woocommerce'
            ),
            esc_url($pay_url),
            esc_html(
                sprintf(
                    /* translators: %d: order number */
                    __('Continue payment for order #%d', 'ax402-for-woocommerce'),
                    $order->get_id()
                )
            )
        );

        // Use WooCommerce's native error notice (typically light red) so themes
        // style it instead of hardcoding a plugin background color.
        wc_print_notice($message, 'error');
    }

    /**
     * Return a live gateway payment URL, re-preparing the endpoint when missing/stale.
     *
     * @throws Throwable
     */
    private function resolve_gateway_url(WC_Order $order): string
    {
        $gateway_url = (string) $order->get_meta(Ax402_WC_Order_Payment::META_GATEWAY_URL);
        if ($gateway_url !== '' && $this->gateway_endpoint_is_live($gateway_url)) {
            return $gateway_url;
        }

        $prepared = Ax402_WC_Order_Payment::prepare($order);
        return (string) $prepared['gateway_url'];
    }

    /**
     * Unpaid endpoints should answer 402 Payment Required; 200 means already fulfilled upstream.
     */
    private function gateway_endpoint_is_live(string $gateway_url): bool
    {
        if (!wp_http_validate_url($gateway_url)) {
            return false;
        }

        $response = wp_remote_get(
            $gateway_url,
            [
                'timeout' => 15,
                'redirection' => 0,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]
        );
        if (is_wp_error($response)) {
            return false;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        return in_array($status, [200, 402], true);
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
                    $is_hedera = !empty($row['is_hedera']);
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
                        'isNative' => $is_hedera
                            ? Ax402_WC_Platform_Tokens::is_hedera_native_asset((string) $row['asset'])
                            : Ax402_WC_Platform_Tokens::is_native_asset((string) $row['asset']),
                        'isHedera' => $is_hedera,
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
        foreach (Ax402_WC_Settings::configured_api_ids($settings_full) as $cors_api_id) {
            Ax402_WC_Gateway_Cors::ensure_store_origins($cors_api_id);
        }
        $cors = Ax402_WC_Gateway_Cors::status();
        $wc_project = trim((string) ($settings_full['walletconnect_project_id'] ?? ''));
        $hedera_wallet_connect = null;
        if ($wc_project !== '') {
            $hedera_network = 'hedera:mainnet';
            foreach ($options as $opt) {
                if (!empty($opt['isHedera']) || Ax402_WC_Platform_Tokens::is_hedera_network((string) ($opt['network'] ?? ''))) {
                    $hedera_network = (string) $opt['network'];
                    break;
                }
            }
            $hedera_wallet_connect = [
                'projectId' => $wc_project,
                'network' => $hedera_network,
                'metadata' => [
                    'name' => get_bloginfo('name') ?: 'WooCommerce',
                    'description' => 'Ax402 checkout',
                    'url' => home_url('/'),
                    'icons' => [],
                ],
            ];
        }

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
            'selectSettlementUrl' => rest_url('ax402/v1/orders/' . $order_key . '/settlement'),
            'thankYouUrl' => $order->get_checkout_order_received_url(),
            'orderUrl' => $order->get_checkout_order_received_url(),
            'payPageUrl' => Ax402_WC_Order_Payment::pay_page_url($order),
            'preferredNetworks' => (string) ($primary['network'] ?? ''),
            'rpcUrl' => (string) ($primary['rpcUrl'] ?? ''),
            'rpcByNetwork' => $rpc_by_network,
            'settlementOptions' => $options,
            'shopUrl' => wc_get_page_permalink('shop') ?: home_url('/'),
            'hederaWalletConnect' => $hedera_wallet_connect,
        ];
    }

    public function maybe_render(): void
    {
        if (!isset($_GET['ax402_pay']) || (string) $_GET['ax402_pay'] !== '1') {
            return;
        }

        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '';
        if ($key === '') {
            wp_die(esc_html__('Missing order key.', 'ax402-for-woocommerce'), 400);
        }

        $order_id = wc_get_order_id_by_order_key($key);
        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order instanceof WC_Order) {
            wp_die(esc_html__('Order not found.', 'ax402-for-woocommerce'), 404);
        }

        Ax402_WC_Settlement_Reconcile::reconcile_order($order);
        $order = wc_get_order($order->get_id());
        if (!$order instanceof WC_Order) {
            wp_die(esc_html__('Order not found.', 'ax402-for-woocommerce'), 404);
        }

        if ($order->is_paid()) {
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        try {
            Ax402_WC_Order_Payment::ensure_current_settlement_options($order);
            $order = wc_get_order($order->get_id());
            if (!$order instanceof WC_Order) {
                wp_die(esc_html__('Order not found.', 'ax402-for-woocommerce'), 404);
            }
            $gateway_url = $this->resolve_gateway_url($order);
        } catch (Throwable $e) {
            wp_die(
                esc_html(
                    sprintf(
                        /* translators: %s: error message */
                        __('Payment is not ready yet: %s', 'ax402-for-woocommerce'),
                        $e->getMessage()
                    )
                ),
                esc_html__('Ax402 payment', 'ax402-for-woocommerce'),
                ['response' => 409]
            );
        }

        $config = $this->page_config($order, $gateway_url);
        $asset = $this->asset_meta();

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
        $boot = 'window.ax402PayPage = '
            . (wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}')
            . ';';
        wp_add_inline_script('ax402-wc-pay-page', $boot, 'before');

        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo esc_html__('Pay with Ax402', 'ax402-for-woocommerce'); ?></title>
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
        .ax402-info {
            position: relative;
            display: inline;
            white-space: nowrap;
        }
        .ax402-info-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.05em;
            height: 1.05em;
            margin: 0 0 0 0.2em;
            padding: 0;
            vertical-align: -0.12em;
            border: 0;
            background: transparent;
            color: var(--ax402-accent);
            cursor: help;
            opacity: 0.9;
        }
        .ax402-info-btn svg {
            width: 1.05em;
            height: 1.05em;
            display: block;
        }
        .ax402-info-btn:hover,
        .ax402-info-btn:focus-visible,
        .ax402-info.is-open .ax402-info-btn {
            opacity: 1;
            outline: none;
            filter: drop-shadow(0 0 4px rgba(124,255,178,0.45));
        }
        .ax402-info-tip {
            position: absolute;
            left: 50%;
            bottom: calc(100% + 0.5rem);
            transform: translateX(-50%);
            width: max-content;
            max-width: min(16.5rem, 70vw);
            padding: 0.55rem 0.7rem;
            border-radius: 10px;
            border: 1px solid var(--ax402-border);
            background: #121a24;
            color: var(--ax402-text);
            font-size: 0.8rem;
            line-height: 1.35;
            white-space: normal;
            box-shadow: 0 10px 28px rgba(0,0,0,0.35);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.12s ease;
            z-index: 5;
        }
        .ax402-info-tip::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: #121a24;
        }
        .ax402-info:hover .ax402-info-tip,
        .ax402-info:focus-within .ax402-info-tip,
        .ax402-info.is-open .ax402-info-tip {
            opacity: 1;
            visibility: visible;
        }
        .ax402-pay-card {
            border: 1px solid var(--ax402-border);
            background: var(--ax402-card);
            border-radius: 18px;
            padding: 1.25rem;
            backdrop-filter: blur(8px);
            overflow: hidden;
            box-sizing: border-box;
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
            gap: 0.55rem;
        }
        .ax402-settle-option {
            width: 100%;
            text-align: left;
            border: 1px solid var(--ax402-border);
            background: rgba(0,0,0,0.18);
            color: var(--ax402-text);
            border-radius: 12px;
            padding: 0.75rem 0.9rem;
            cursor: pointer;
            display: grid;
            gap: 0.28rem;
        }
        .ax402-settle-option.is-active {
            border-color: var(--ax402-accent);
            box-shadow: 0 0 0 1px rgba(124,255,178,0.35);
        }
        .ax402-settle-top {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.75rem;
        }
        .ax402-settle-symbol {
            font-weight: 700;
            font-size: 1.05rem;
            letter-spacing: 0.01em;
        }
        .ax402-settle-network {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            color: var(--ax402-muted);
            font-size: 0.78rem;
            white-space: nowrap;
        }
        .ax402-settle-amount {
            font-size: 0.98rem;
            font-variant-numeric: tabular-nums;
            color: var(--ax402-text);
        }
        .ax402-settle-rate {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            color: var(--ax402-muted);
            font-size: 0.8rem;
            font-variant-numeric: tabular-nums;
        }
        .ax402-settle-glyph {
            flex: 0 0 auto;
            opacity: 0.85;
        }
        .ax402-settle-option:disabled { cursor: default; opacity: 1; }
        .ax402-steps { margin-top: 0.25rem; }
        .ax402-step {
            border: 1px solid var(--ax402-border);
            background: rgba(0,0,0,0.22);
            border-radius: 14px;
            padding: 1rem 1.05rem 1.1rem;
        }
        .ax402-step-title {
            margin: 0 0 0.4rem;
            font-size: 1.15rem;
            line-height: 1.25;
        }
        .ax402-step-desc {
            margin: 0 0 1rem;
            color: var(--ax402-muted);
            line-height: 1.45;
            font-size: 0.95rem;
        }
        .ax402-step-hint {
            margin: 0;
            color: var(--ax402-muted);
            line-height: 1.45;
            font-size: 0.9rem;
        }
        .ax402-step-error {
            margin: 0.75rem 0 0;
            color: var(--ax402-error);
            font-size: 0.9rem;
            line-height: 1.4;
        }
        .ax402-step-meta,
        .ax402-step-footer {
            margin-top: 0.85rem;
            color: var(--ax402-muted);
            font-size: 0.82rem;
        }
        .ax402-primary-btn {
            border: 0;
            border-radius: 10px;
            padding: 0.7rem 1rem;
            background: var(--ax402-accent);
            color: #061018;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.95rem;
        }
        .ax402-primary-btn:disabled { opacity: 0.6; cursor: wait; }
        .ax402-pay-step { margin-top: 0.15rem; max-width: 100%; }
        /* Dedicated pay page: keep paywall card in normal flow (not a stacked overlay). */
        .ax402-inline-gate.x402-paywall-gate,
        .ax402-pay-card .x402-paywall-gate {
            position: static;
            max-width: 100%;
        }
        .ax402-pay-card .x402-paywall-teaser {
            display: none;
        }
        .ax402-pay-card .x402-paywall-overlay {
            position: static !important;
            inset: auto !important;
            display: block;
            background: transparent !important;
            padding: 0 !important;
            max-width: 100%;
            box-sizing: border-box;
        }
        .ax402-pay-card .x402-paywall-card {
            box-sizing: border-box;
            max-width: 100% !important;
            width: 100% !important;
            margin: 0;
            padding: 1rem 0 0;
            border: 0;
            border-top: 1px solid var(--ax402-border);
            border-radius: 0;
            background: transparent;
            box-shadow: none;
            color: var(--ax402-text);
            text-align: left;
        }
        .ax402-pay-card .x402-paywall-title {
            color: var(--ax402-text);
            font-size: 1.15rem;
        }
        .ax402-pay-card .x402-paywall-desc,
        .ax402-pay-card .x402-paywall-wallet {
            color: var(--ax402-muted);
        }
        .ax402-pay-card .x402-paywall-price {
            color: var(--ax402-accent);
            font-weight: 700;
        }
        .ax402-pay-card .x402-paywall-actions {
            max-width: 100%;
        }
        .ax402-pay-card .x402-paywall-btn {
            box-sizing: border-box;
            max-width: 100%;
        }
        .ax402-pay-card .x402-paywall-btn-primary {
            background: var(--ax402-accent);
            color: #061018;
            border: 0;
            font-weight: 700;
        }
        #ax402-pay-root { min-height: 180px; }
        .ax402-pay-loading {
            color: var(--ax402-muted);
            margin: 0;
            line-height: 1.5;
        }
        .ax402-spinner {
            width: 2rem;
            height: 2rem;
            margin: 0.35rem 0 0.75rem;
            border: 2px solid rgba(125, 211, 252, 0.25);
            border-top-color: var(--ax402-accent);
            border-radius: 50%;
            animation: ax402-spin 0.75s linear infinite;
        }
        @keyframes ax402-spin {
            to { transform: rotate(360deg); }
        }
        .ax402-pay-help {
            margin-top: 1.25rem;
            color: var(--ax402-muted);
            font-size: 0.9rem;
            line-height: 1.45;
        }
        .ax402-pay-help a { color: var(--ax402-accent); }
        .ax402-pay-help-sep {
            margin: 0 0.35rem;
            color: var(--ax402-muted);
            opacity: 0.7;
        }
        .ax402-pay-powered {
            margin: 0.55rem 0 0;
            color: var(--ax402-muted);
            font-size: 0.84rem;
            line-height: 1.4;
        }
        .ax402-pay-powered a {
            color: var(--ax402-accent);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.28rem;
        }
        .ax402-pay-powered a:hover,
        .ax402-pay-powered a:focus-visible {
            text-decoration: underline;
            outline: none;
        }
        .ax402-ext-icon {
            width: 0.85em;
            height: 0.85em;
            flex: 0 0 auto;
            opacity: 0.9;
        }
        .screen-reader-text {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
    </style>
</head>
<body class="ax402-pay-body">
    <main class="ax402-pay-shell">
        <p class="ax402-pay-brand">Ax402</p>
        <h1><?php echo esc_html__('Pay securely with your wallet', 'ax402-for-woocommerce'); ?></h1>
        <p class="ax402-pay-lead">
            <?php
            $option_count = count($config['settlementOptions'] ?? []);
            echo esc_html(
                $option_count > 1
                    ? __('Choose a settlement token, then connect and just sign the payment. We finalize the order automatically.', 'ax402-for-woocommerce')
                    : __('Connect and just sign the payment. We finalize the order automatically.', 'ax402-for-woocommerce')
            );
            ?>
            <span class="ax402-info" data-ax402-info>
                <button
                    type="button"
                    class="ax402-info-btn"
                    aria-label="<?php echo esc_attr__('No network fees required. We cover them for you.', 'ax402-for-woocommerce'); ?>"
                    aria-describedby="ax402-fee-tip"
                    aria-expanded="false"
                >
                    <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                        <circle cx="8" cy="8" r="7" fill="none" stroke="currentColor" stroke-width="1.5" />
                        <circle cx="8" cy="4.6" r="1" fill="currentColor" />
                        <path d="M8 7.1v4.4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                    </svg>
                </button>
                <span class="ax402-info-tip" id="ax402-fee-tip" role="tooltip">
                    <?php echo esc_html__('No network fees required. We cover them for you.', 'ax402-for-woocommerce'); ?>
                </span>
            </span>
        </p>
        <section class="ax402-pay-card">
            <div id="ax402-pay-root">
                <p class="ax402-pay-loading"><?php echo esc_html__('Loading payment…', 'ax402-for-woocommerce'); ?></p>
            </div>
        </section>
        <p class="ax402-pay-help">
            <?php echo esc_html__('Use the network shown for your selected token. Need to leave?', 'ax402-for-woocommerce'); ?>
            <a href="<?php echo esc_url((string) $config['shopUrl']); ?>">
                <?php echo esc_html__('Return to shop', 'ax402-for-woocommerce'); ?>
            </a>
            <span class="ax402-pay-help-sep" aria-hidden="true">·</span>
            <a href="<?php echo esc_url((string) ($config['orderUrl'] ?? $config['thankYouUrl'])); ?>">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d: order number */
                        __('Order details #%d', 'ax402-for-woocommerce'),
                        (int) ($config['orderId'] ?? 0)
                    )
                );
                ?>
            </a>
        </p>
        <?php
        $gateway = new Ax402_WC_Gateway_Ax402();
        if ($gateway->get_option('show_powered_by', 'no') === 'yes') :
            ?>
        <p class="ax402-pay-powered">
            <?php echo esc_html__('Powered by', 'ax402-for-woocommerce'); ?>
            <a href="https://ax402.io" target="_blank" rel="noopener noreferrer">
                Ax402
                <svg class="ax402-ext-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                    <path
                        fill="currentColor"
                        d="M6.5 2.5a.75.75 0 0 0 0 1.5h4.19L3.22 11.47a.75.75 0 1 0 1.06 1.06L11.75 5.06v4.19a.75.75 0 0 0 1.5 0v-6a.75.75 0 0 0-.75-.75h-6z"
                    />
                </svg>
                <span class="screen-reader-text"><?php echo esc_html__('(opens in a new tab)', 'ax402-for-woocommerce'); ?></span>
            </a>
        </p>
        <?php endif; ?>
    </main>
    <script>
        window.ax402PayPage = <?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}'; ?>;
        (function () {
            var root = document.querySelector('[data-ax402-info]');
            if (!root) return;
            var btn = root.querySelector('.ax402-info-btn');
            if (!btn) return;
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                var open = root.classList.toggle('is-open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function (event) {
                if (!root.contains(event.target)) {
                    root.classList.remove('is-open');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    root.classList.remove('is-open');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        })();
    </script>
    <?php wp_footer(); ?>
</body>
</html>
        <?php
        exit;
    }
}
