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
        $network = (string) $order->get_meta(Ax402_WC_Order_Payment::META_NETWORK);
        if ($network === '') {
            $network = Ax402_WC_Platform_Tokens::network_for_mode($settings['network_mode']);
        }

        $amount = (string) $order->get_meta(Ax402_WC_Order_Payment::META_AMOUNT_USDC);
        if ($amount === '') {
            $amount = Ax402_WC_Money::normalize_order_total($order->get_total());
        }

        $usdc_asset = $network === Ax402_WC_Platform_Tokens::NETWORK_BASE_MAINNET
            ? '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913'
            : '0x036CbD53842c5426634e7929541eC2318f3dCF7e';

        $order_key = $order->get_order_key();

        return [
            // Browser paywall must use same-origin proxy to avoid gateway CORS.
            'gatewayUrl' => rest_url('ax402/v1/pay-proxy/' . $order_key),
            'directGatewayUrl' => $gateway_url,
            'orderId' => $order->get_id(),
            'orderKey' => $order_key,
            'amountUsdc' => $amount,
            'network' => $network,
            'allowedAssets' => $usdc_asset,
            'statusUrl' => rest_url('ax402/v1/orders/' . $order_key),
            'thankYouUrl' => $order->get_checkout_order_received_url(),
            'preferredNetworks' => $network,
            'rpcUrl' => $settings['network_mode'] === 'mainnet'
                ? 'https://mainnet.base.org'
                : 'https://sepolia.base.org',
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
        .ax402-pay-meta {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--ax402-border);
            font-size: 0.95rem;
        }
        .ax402-pay-meta strong { color: var(--ax402-text); }
        .ax402-pay-meta span { color: var(--ax402-muted); }
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
            <?php echo esc_html__('Connect MetaMask (or another wallet), confirm the USDC payment, and we will finalize your order automatically.', 'ax402-woocommerce'); ?>
        </p>
        <section class="ax402-pay-card">
            <div class="ax402-pay-meta">
                <div>
                    <span><?php echo esc_html__('Order', 'ax402-woocommerce'); ?></span><br />
                    <strong>#<?php echo esc_html((string) $order->get_id()); ?></strong>
                </div>
                <div style="text-align:right">
                    <span><?php echo esc_html__('Amount', 'ax402-woocommerce'); ?></span><br />
                    <strong><?php echo esc_html($config['amountUsdc']); ?> USDC</strong>
                </div>
            </div>
            <div id="ax402-pay-root">
                <p class="ax402-pay-lead"><?php echo esc_html__('Loading payment…', 'ax402-woocommerce'); ?></p>
            </div>
        </section>
        <p class="ax402-pay-help">
            <?php echo esc_html__('Use the Base network in your wallet. Need to leave?', 'ax402-woocommerce'); ?>
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
