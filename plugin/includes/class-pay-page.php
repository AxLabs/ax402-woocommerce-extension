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

        Ax402_WC_Settlement_Reconcile::reconcile_order($order, null, true);
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public pay URL; Woo order key is the capability.
        if (!isset($_GET['ax402_pay']) || (string) $_GET['ax402_pay'] !== '1') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public pay URL; Woo order key is the capability.
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '';
        if ($key === '') {
            wp_die(esc_html__('Missing order key.', 'ax402-for-woocommerce'), 400);
        }

        $order_id = wc_get_order_id_by_order_key($key);
        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order instanceof WC_Order) {
            wp_die(esc_html__('Order not found.', 'ax402-for-woocommerce'), 404);
        }

        Ax402_WC_Settlement_Reconcile::reconcile_order($order, null, true);
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
        wp_register_style(
            'ax402-wc-pay-page-shell',
            AX402_WC_PLUGIN_URL . 'assets/pay-page-shell.css',
            ['ax402-wc-pay-page'],
            AX402_WC_VERSION
        );
        wp_register_script(
            'ax402-wc-pay-page-shell',
            AX402_WC_PLUGIN_URL . 'assets/pay-page-shell.js',
            [],
            AX402_WC_VERSION,
            true
        );

        wp_enqueue_script('ax402-wc-pay-page');
        wp_enqueue_style('ax402-wc-pay-page');
        wp_enqueue_style('ax402-wc-pay-page-shell');
        wp_enqueue_script('ax402-wc-pay-page-shell');

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
    <?php wp_footer(); ?>
</body>
</html>
        <?php
        exit;
    }
}
