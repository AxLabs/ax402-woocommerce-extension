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
            'Accept on-chain settlements via Ax402 / x402 for humans (wallet) and agents (buyer SDKs). Catalog currency stays USD.',
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
            __('Pay with a supported wallet token via Ax402 / x402.', 'ax402-woocommerce')
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
                'default' => __('Pay with a supported wallet token via Ax402 / x402.', 'ax402-woocommerce'),
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
                'description' => __('EVM address that receives settlements.', 'ax402-woocommerce'),
                'default' => $plugin['pay_to_address'],
            ],
            'network_mode' => [
                'title' => __('Default network seed', 'ax402-woocommerce'),
                'type' => 'select',
                'description' => __(
                    'Used for gateway hostname onboarding and the default USDC token when no settlement tokens are selected yet. Checkout accepts follow the token checklist below.',
                    'ax402-woocommerce'
                ),
                'options' => [
                    'sepolia' => __('Base Sepolia (dev)', 'ax402-woocommerce'),
                    'mainnet' => __('Base mainnet', 'ax402-woocommerce'),
                ],
                'default' => $plugin['network_mode'] ?: 'sepolia',
            ],
            'settlement_tokens' => [
                'title' => __('Settlement tokens', 'ax402-woocommerce'),
                'type' => 'ax402_tokens',
                'description' => __(
                    'Tokens from Ax402 platform config that this store accepts. Customers pick one on the pay page. Stablecoins settle 1:1 with the USD order total; other tokens need a resolvable exchange rate.',
                    'ax402-woocommerce'
                ),
            ],
            'refresh_platform_tokens' => [
                'title' => __('Refresh tokens', 'ax402-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Sync payment tokens from Ax402 on save', 'ax402-woocommerce'),
                'default' => 'no',
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

    /**
     * Custom settings field: checklist of platform payment tokens.
     *
     * @param string $key
     * @param array<string, mixed> $data
     */
    public function generate_ax402_tokens_html($key, $data): string
    {
        $field_key = $this->get_field_key($key);
        $data = wp_parse_args($data, [
            'title' => '',
            'description' => '',
        ]);

        $cache = Ax402_WC_Platform_Config_Store::get();
        if ($cache['platform'] === [] && Ax402_WC_Settings::client() !== null) {
            Ax402_WC_Platform_Config_Store::sync();
            $cache = Ax402_WC_Platform_Config_Store::get();
        }

        $platform = $cache['platform'];
        $tokens = Ax402_WC_Platform_Tokens::enabled_tokens($platform);
        $settings = Ax402_WC_Settings::all();
        $selected = $settings['enabled_token_ids'];
        if ($selected === [] && $tokens !== []) {
            $selected = Ax402_WC_Platform_Tokens::default_enabled_token_ids(
                $platform,
                $settings['network_mode']
            );
        }
        $rates = Ax402_WC_Composite_Exchange_Rates::default();

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html((string) $data['title']); ?></label>
            </th>
            <td class="forminp">
                <?php if ($cache['synced_at'] > 0) : ?>
                    <p class="description" style="margin-bottom:0.75em">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: 1: token count 2: localized datetime */
                                __('Last sync: %2$s · %1$d platform token(s).', 'ax402-woocommerce'),
                                $cache['token_count'],
                                wp_date(get_option('date_format') . ' ' . get_option('time_format'), $cache['synced_at'])
                            )
                        );
                        ?>
                    </p>
                <?php endif; ?>
                <?php if ($cache['error'] !== '') : ?>
                    <p class="description" style="color:#b32d2e;margin-bottom:0.75em">
                        <?php echo esc_html($cache['error']); ?>
                    </p>
                <?php endif; ?>
                <?php if ($tokens === []) : ?>
                    <p class="description">
                        <?php echo esc_html__(
                            'No platform tokens cached yet. Save an API key and check “Sync payment tokens from Ax402 on save”.',
                            'ax402-woocommerce'
                        ); ?>
                    </p>
                <?php else : ?>
                    <fieldset>
                        <ul style="margin:0;padding:0;list-style:none;max-width:42rem">
                            <?php foreach ($tokens as $token) :
                                $id = (string) ($token['id'] ?? '');
                                $symbol = (string) ($token['symbol'] ?? '');
                                $network = (string) ($token['network'] ?? '');
                                $decimals = (int) ($token['decimals'] ?? 0);
                                $rate = $rates->rate_usd_to_token($symbol, $network);
                                $rate_ok = $rate !== null;
                                $checked = in_array($id, $selected, true);
                                ?>
                                <li style="margin:0 0 0.55em;padding:0.55em 0.7em;border:1px solid #c3c4c7;border-radius:4px;background:#fff">
                                    <label style="display:flex;gap:0.65em;align-items:flex-start">
                                        <input
                                            type="checkbox"
                                            name="<?php echo esc_attr($field_key); ?>[]"
                                            value="<?php echo esc_attr($id); ?>"
                                            <?php checked($checked); ?>
                                            <?php disabled(!$rate_ok); ?>
                                        />
                                        <span>
                                            <strong><?php echo esc_html($symbol); ?></strong>
                                            · <?php echo esc_html(Ax402_WC_Platform_Tokens::network_label($network)); ?>
                                            <br />
                                            <span class="description">
                                                <?php
                                                echo esc_html(
                                                    sprintf(
                                                        /* translators: 1: CAIP-2 network 2: decimals */
                                                        __('%1$s · %2$d decimals', 'ax402-woocommerce'),
                                                        $network,
                                                        $decimals
                                                    )
                                                );
                                                ?>
                                                <?php if ($rate_ok) : ?>
                                                    · <?php echo esc_html__('rate OK', 'ax402-woocommerce'); ?>
                                                <?php else : ?>
                                                    · <span style="color:#b32d2e"><?php echo esc_html__('rate unavailable', 'ax402-woocommerce'); ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </fieldset>
                <?php endif; ?>
                <?php if (!empty($data['description'])) : ?>
                    <p class="description"><?php echo esc_html((string) $data['description']); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Persist checklist values (WC does not know this custom field type).
     *
     * @param string $key
     * @param mixed $value
     * @return list<string>
     */
    public function validate_ax402_tokens_field($key, $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($id): string => sanitize_text_field((string) $id),
            $value
        ))));
    }

    public function sync_plugin_settings(): void
    {
        $payload = [
            'base_url' => (string) $this->get_option('base_url', 'https://api.ax402.io'),
            'pay_to_address' => (string) $this->get_option('pay_to_address', ''),
            'network_mode' => (string) $this->get_option('network_mode', 'sepolia'),
            'api_slug' => (string) $this->get_option('api_slug', ''),
            'enabled_token_ids' => $this->get_option('settlement_tokens', []),
        ];

        if (!is_array($payload['enabled_token_ids'])) {
            $payload['enabled_token_ids'] = [];
        }

        $api_key = (string) $this->get_option('api_key', '');
        if ($api_key !== '') {
            $payload['api_key'] = $api_key;
            // Do not persist plaintext API key in gateway settings.
            $this->update_option('api_key', '');
        }

        Ax402_WC_Settings::update($payload);

        $refresh = $this->get_option('refresh_platform_tokens', 'no') === 'yes';
        if ($refresh || Ax402_WC_Platform_Config_Store::platform() === []) {
            $sync = Ax402_WC_Platform_Config_Store::sync();
            if (!$sync['ok'] && $sync['error'] !== '') {
                WC_Admin_Settings::add_error(
                    sprintf(
                        /* translators: %s: error message */
                        __('Ax402 token sync: %s', 'ax402-woocommerce'),
                        $sync['error']
                    )
                );
            }
            $this->update_option('refresh_platform_tokens', 'no');
        }

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
        if ($settings['api_key'] === '' || $settings['pay_to_address'] === '') {
            return false;
        }

        return Ax402_WC_Settings::enabled_token_ids() !== [];
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
