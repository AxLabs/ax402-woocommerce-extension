<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * WooCommerce payment gateway for Ax402 / x402.
 */
final class Ax402_WC_Gateway_Ax402 extends WC_Payment_Gateway
{
    public const GATEWAY_ID = 'ax402';

    public function __construct()
    {
        $this->id = self::GATEWAY_ID;
        $this->method_title = __('Ax402 (x402)', 'ax402-woocommerce');
        $this->method_description = __(
            'Accept USDC payments via Ax402 / x402 for humans (wallet) and agents (buyer SDKs).',
            'ax402-woocommerce'
        );
        $this->has_fields = false;
        $this->supports = ['products'];
        $this->icon = AX402_WC_PLUGIN_URL . 'assets/ax402-icon.svg';

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', __('Pay with Ax402', 'ax402-woocommerce'));
        $this->description = $this->get_option(
            'description',
            __('Pay with USDC using a wallet or an x402-capable agent.', 'ax402-woocommerce')
        );
        $this->enabled = $this->get_option('enabled', 'no');

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'sync_plugin_settings']);
    }

    public function init_form_fields(): void
    {
        $plugin = Ax402_WC_Settings::all();

        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'ax402-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Ax402 payments', 'ax402-woocommerce'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'ax402-woocommerce'),
                'type' => 'text',
                'description' => __('Payment method title at checkout.', 'ax402-woocommerce'),
                'default' => __('Pay with Ax402', 'ax402-woocommerce'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'ax402-woocommerce'),
                'type' => 'textarea',
                'default' => __('Pay with USDC using a wallet or an x402-capable agent.', 'ax402-woocommerce'),
            ],
            'base_url' => [
                'title' => __('Ax402 API base URL', 'ax402-woocommerce'),
                'type' => 'text',
                'default' => $plugin['base_url'] ?: 'https://api.ax402.io',
            ],
            'api_key' => [
                'title' => __('API key', 'ax402-woocommerce'),
                'type' => 'password',
                'description' => __(
                    'Scoped ax402_live_… key with apiManager scopes. Leave blank to keep the current key.',
                    'ax402-woocommerce'
                ),
                'default' => '',
            ],
            'pay_to_address' => [
                'title' => __('Pay-to wallet', 'ax402-woocommerce'),
                'type' => 'text',
                'description' => __('EVM address that receives USDC settlements.', 'ax402-woocommerce'),
                'default' => $plugin['pay_to_address'],
            ],
            'network_mode' => [
                'title' => __('Network', 'ax402-woocommerce'),
                'type' => 'select',
                'options' => [
                    'sepolia' => __('Base Sepolia (dev)', 'ax402-woocommerce'),
                    'mainnet' => __('Base mainnet', 'ax402-woocommerce'),
                ],
                'default' => $plugin['network_mode'] ?: 'sepolia',
            ],
            'api_slug' => [
                'title' => __('Gateway slug', 'ax402-woocommerce'),
                'type' => 'text',
                'description' => __(
                    'Optional. Used when creating the store API on Ax402. Leave blank to auto-generate.',
                    'ax402-woocommerce'
                ),
                'default' => $plugin['api_slug'],
            ],
        ];
    }

    public function sync_plugin_settings(): void
    {
        $payload = [
            'base_url' => (string) $this->get_option('base_url', 'https://api.ax402.io'),
            'pay_to_address' => (string) $this->get_option('pay_to_address', ''),
            'network_mode' => (string) $this->get_option('network_mode', 'sepolia'),
            'api_slug' => (string) $this->get_option('api_slug', ''),
        ];

        $api_key = (string) $this->get_option('api_key', '');
        if ($api_key !== '') {
            $payload['api_key'] = $api_key;
            // Do not persist plaintext API key in gateway settings.
            $this->update_option('api_key', '');
        }

        Ax402_WC_Settings::update($payload);

        try {
            if (Ax402_WC_Settings::client() !== null && $payload['pay_to_address'] !== '') {
                Ax402_WC_Store_Onboarding::ensure_api();
            }
        } catch (Throwable $e) {
            WC_Admin_Settings::add_error(
                sprintf(
                    /* translators: %s: error message */
                    __('Ax402 onboarding warning: %s', 'ax402-woocommerce'),
                    $e->getMessage()
                )
            );
        }
    }

    public function is_available(): bool
    {
        if (!parent::is_available()) {
            return false;
        }
        if (get_woocommerce_currency() !== 'USD') {
            return false;
        }

        $settings = Ax402_WC_Settings::all();
        return $settings['api_key'] !== '' && $settings['pay_to_address'] !== '';
    }

    /**
     * @return array{result:string,redirect?:string}
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wc_add_notice(__('Order not found.', 'ax402-woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        try {
            Ax402_WC_Order_Payment::prepare($order);
        } catch (Throwable $e) {
            wc_add_notice(
                sprintf(
                    /* translators: %s: error message */
                    __('Ax402 payment setup failed: %s', 'ax402-woocommerce'),
                    $e->getMessage()
                ),
                'error'
            );
            return ['result' => 'failure'];
        }

        $order->update_status('pending', __('Awaiting Ax402 / x402 payment.', 'ax402-woocommerce'));

        WC()->cart?->empty_cart();

        return [
            'result' => 'success',
            'redirect' => Ax402_WC_Order_Payment::pay_page_url($order),
        ];
    }
}
