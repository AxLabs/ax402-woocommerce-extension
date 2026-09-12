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
        $this->method_title = __('Ax402 (x402)', 'ax402-for-woocommerce');
        $this->method_description = __(
            'Ax402 is an x402 payment provider for shoppers and agents, with Universal Commerce Protocol (UCP) support. Catalog currency stays USD.',
            'ax402-for-woocommerce'
        );
        $this->has_fields = false;
        $this->supports = ['products'];
        $icon_path = AX402_WC_PLUGIN_DIR . 'assets/ax402-icon.svg';
        $icon_ver = is_readable($icon_path) ? (string) filemtime($icon_path) : AX402_WC_VERSION;
        $this->icon = add_query_arg('v', $icon_ver, AX402_WC_PLUGIN_URL . 'assets/ax402-icon.svg');

        $this->init_form_fields();
        $this->init_settings();

        $this->title = __('Pay with Ax402', 'ax402-for-woocommerce');
        $this->description =
            esc_html__(
                'Pay with a wallet using stablecoins or supported tokens via Ax402 using the x402 standard. For people and AI agents.',
                'ax402-for-woocommerce'
            )
            . ' <strong>'
            . esc_html__('No network fees for shoppers.', 'ax402-for-woocommerce')
            . '</strong>';
        $this->enabled = $this->get_option('enabled', 'no');

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'sync_plugin_settings']);
    }

    /**
     * Checkout payment-method icon (classic + Blocks via settings).
     */
    public function get_icon(): string
    {
        if ($this->icon === '') {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.
            return apply_filters('woocommerce_gateway_icon', '', $this->id);
        }

        $icon = sprintf(
            '<img src="%1$s" alt="%2$s" width="32" height="32" style="max-height:32px;width:auto;" />',
            esc_url(WC_HTTPS::force_https_url($this->icon)),
            esc_attr($this->get_title())
        );

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.
        return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
    }

    public function init_form_fields(): void
    {
        $plugin = Ax402_WC_Settings::all();

        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'ax402-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Ax402 payments', 'ax402-for-woocommerce'),
                'default' => 'no',
            ],
            'base_url' => [
                'title' => __('Ax402 API base URL', 'ax402-for-woocommerce'),
                'type' => 'ax402_base_url',
                'description' => sprintf(
                    /* translators: %s: environment variable name */
                    __('Development and testing only. This value cannot be edited here. Set %s to change it.', 'ax402-for-woocommerce'),
                    '<code>AX402_BASE_URL</code>'
                ),
                'default' => $plugin['base_url'] ?: 'https://api.ax402.io',
            ],
            'api_key' => [
                'title' => __('Ax402 API Key', 'ax402-for-woocommerce'),
                'type' => 'ax402_api_key',
                'description' => sprintf(
                    /* translators: 1: opening anchor tag, 2: closing anchor tag */
                    __('Create a key at %1$sAx402%2$s: create an account, open API keys, name it something like “Ax402 for WooCommerce”, select All scopes, then click Create API Key. Enter a new key only when you want to replace the current one.', 'ax402-for-woocommerce'),
                    '<a href="https://ax402.io" target="_blank" rel="noopener noreferrer">',
                    '</a>'
                ),
                'default' => '',
            ],
            'pay_to_address' => [
                'title' => __('EVM pay-to wallet', 'ax402-for-woocommerce'),
                'type' => 'text',
                'description' => __(
                    'Single EVM address that receives settlements on all EVM networks (Base, Neo X, …).',
                    'ax402-for-woocommerce'
                ),
                'default' => $plugin['pay_to_address'],
                'custom_attributes' => [
                    'id' => 'woocommerce_ax402_pay_to_address',
                ],
            ],
            'pay_to_hedera_account_id' => [
                'title' => __('Hedera pay-to account', 'ax402-for-woocommerce'),
                'type' => 'text',
                'description' => __(
                    'Hedera account id (0.0.x) that receives Hedera settlements. Enable a Hedera settlement token below to unlock this field.',
                    'ax402-for-woocommerce'
                ),
                'default' => $plugin['pay_to_hedera_account_id'] ?? '',
                'custom_attributes' => [
                    'id' => 'woocommerce_ax402_pay_to_hedera_account_id',
                    'autocomplete' => 'off',
                    'data-ax402-hedera-payto' => '1',
                ],
            ],
            'walletconnect_project_id' => [
                'title' => __('WalletConnect project ID', 'ax402-for-woocommerce'),
                'type' => 'text',
                'description' => sprintf(
                    /* translators: 1: opening anchor tag, 2: closing anchor tag */
                    __('Required for Hedera shopper wallets (HashPack, etc.). Get a project ID from %1$sReown%2$s: create an account, then create a project. Unlock this field by enabling a Hedera settlement token.', 'ax402-for-woocommerce'),
                    '<a href="https://reown.com" target="_blank" rel="noopener noreferrer">',
                    '</a>'
                ),
                'default' => $plugin['walletconnect_project_id'] ?? '',
                'custom_attributes' => [
                    'id' => 'woocommerce_ax402_walletconnect_project_id',
                    'data-ax402-hedera-payto' => '1',
                ],
            ],
            'network_mode' => [
                'title' => __('Environment seed', 'ax402-for-woocommerce'),
                'type' => 'select',
                'description' => __(
                    'Only used for Ax402 gateway hostname onboarding (dev vs production platform domain). Settlement networks and tokens always come from the live platform sync below.',
                    'ax402-for-woocommerce'
                ),
                'options' => [
                    'sepolia' => __('Development / test domains', 'ax402-for-woocommerce'),
                    'mainnet' => __('Production domain', 'ax402-for-woocommerce'),
                ],
                'default' => $plugin['network_mode'] ?: 'sepolia',
            ],
            'settlement_tokens' => [
                'title' => __('Settlement tokens', 'ax402-for-woocommerce'),
                'type' => 'ax402_tokens',
                'description' => __(
                    'Live list from Ax402 /config/platform. Customers pick one on the pay page. Stablecoins settle 1:1 with the USD order total; other tokens need a resolvable exchange rate.',
                    'ax402-for-woocommerce'
                ),
            ],
            'gateway_cors' => [
                'title' => __('Gateway CORS', 'ax402-for-woocommerce'),
                'type' => 'ax402_cors_status',
                'description' => __(
                    'Store origins are pushed to Ax402 so the pay page can call the gateway directly from the browser.',
                    'ax402-for-woocommerce'
                ),
            ],
            'api_slug' => [
                'title' => __('Gateway slug', 'ax402-for-woocommerce'),
                'type' => 'text',
                'description' => __(
                    'Optional. Used when creating the store API on Ax402. Leave blank to auto-generate.',
                    'ax402-for-woocommerce'
                ),
                'default' => $plugin['api_slug'],
            ],
            'settlement_reconcile' => [
                'title' => __('Settlement reconcile', 'ax402-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __(
                    'Complete unpaid orders from Ax402 settlements when gateway fulfill is missing',
                    'ax402-for-woocommerce'
                ),
                'description' => __(
                    'Off by default. When enabled, Ax402 settlements can complete unpaid orders if gateway fulfill is missing. Pay-page and agent status polls still confirm payment when this is off.',
                    'ax402-for-woocommerce'
                ),
                'default' => ($plugin['settlement_reconcile'] ?? 'no') === 'yes' ? 'yes' : 'no',
            ],
            'ucp_enabled' => [
                'title' => __('UCP for agents', 'ax402-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __(
                    'Enable Universal Commerce Protocol (/.well-known/ucp and /wp-json/ucp/v1)',
                    'ax402-for-woocommerce'
                ),
                'description' => __(
                    'On by default. Independent of the human checkout method. Buying agents discover the store, browse the catalog, and pay via x402 at checkout complete. Does not change the pay page or the legacy /wp-json/ax402/v1 agent API.',
                    'ax402-for-woocommerce'
                ),
                'default' => ($plugin['ucp_enabled'] ?? 'yes') === 'yes' ? 'yes' : 'no',
            ],
            'ucp_max_amount' => [
                'title' => __('UCP max payment (base units)', 'ax402-for-woocommerce'),
                'type' => 'text',
                'description' => __(
                    'Optional ceiling advertised to agents as x402.max_amount (integer token base units, digits only). Leave blank for no advertised ceiling.',
                    'ax402-for-woocommerce'
                ),
                'default' => (string) ($plugin['ucp_max_amount'] ?? ''),
            ],
            'show_powered_by' => [
                'title' => __('Pay page credit', 'ax402-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Show Ax402 credit on the pay page', 'ax402-for-woocommerce'),
                'description' => __(
                    'Optional front-facing “Powered by Ax402” link. Off by default.',
                    'ax402-for-woocommerce'
                ),
                'default' => 'no',
            ],
        ];
    }

    /**
     * Read-only API base URL. Change via AX402_BASE_URL, not this screen.
     *
     * @param string $key
     * @param array<string, mixed> $data
     */
    public function generate_ax402_base_url_html($key, $data): string
    {
        $field_key = $this->get_field_key($key);
        $data = wp_parse_args($data, [
            'title' => '',
            'description' => '',
            'css' => '',
            'class' => '',
        ]);
        $value = Ax402_WC_Settings::all()['base_url'] ?: 'https://api.ax402.io';

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html((string) $data['title']); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo esc_html((string) $data['title']); ?></span></legend>
                    <input
                        class="input-text regular-input <?php echo esc_attr((string) $data['class']); ?>"
                        type="url"
                        name="<?php echo esc_attr($field_key); ?>"
                        id="<?php echo esc_attr($field_key); ?>"
                        style="<?php echo esc_attr((string) $data['css']); ?>"
                        value="<?php echo esc_attr($value); ?>"
                        readonly="readonly"
                        disabled="disabled"
                        aria-readonly="true"
                    />
                    <?php if ((string) $data['description'] !== '') : ?>
                        <p class="description"><?php echo $this->admin_field_description_html((string) $data['description']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses in helper. ?></p>
                    <?php endif; ?>
                </fieldset>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Masked API key. Submitted asterisks matching the stored length keep the current key.
     *
     * @param string $key
     * @param array<string, mixed> $data
     */
    public function generate_ax402_api_key_html($key, $data): string
    {
        $field_key = $this->get_field_key($key);
        $data = wp_parse_args($data, [
            'title' => '',
            'description' => '',
            'css' => '',
            'class' => '',
        ]);
        $current = Ax402_WC_Settings::all()['api_key'];
        $mask = $current !== '' ? str_repeat('*', strlen($current)) : '';

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html((string) $data['title']); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo esc_html((string) $data['title']); ?></span></legend>
                    <input
                        class="input-text regular-input <?php echo esc_attr((string) $data['class']); ?>"
                        type="password"
                        name="<?php echo esc_attr($field_key); ?>"
                        id="<?php echo esc_attr($field_key); ?>"
                        style="<?php echo esc_attr((string) $data['css']); ?>"
                        value="<?php echo esc_attr($mask); ?>"
                        autocomplete="new-password"
                        spellcheck="false"
                    />
                    <?php if ((string) $data['description'] !== '') : ?>
                        <p class="description"><?php echo $this->admin_field_description_html((string) $data['description']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses in helper. ?></p>
                    <?php endif; ?>
                </fieldset>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Ignore posted base URL (the input is disabled; this setting is env-only).
     *
     * @param string $key
     * @param mixed $value
     */
    public function validate_ax402_base_url_field($key, $value): string
    {
        unset($key, $value);
        return Ax402_WC_Settings::persisted_base_url();
    }

    /**
     * Keep the stored key when the field is empty or still the display mask.
     *
     * @param string $key
     * @param mixed $value
     */
    public function validate_ax402_api_key_field($key, $value): string
    {
        unset($key);
        $submitted = trim((string) $value);
        if ($this->submitted_api_key_unchanged($submitted)) {
            return '';
        }

        return sanitize_text_field($submitted);
    }

    /**
     * @param string $key
     * @param array<string, mixed> $data
     */
    public function generate_ax402_cors_status_html($key, $data): string
    {
        unset($key);
        $data = wp_parse_args($data, [
            'title' => '',
            'description' => '',
        ]);
        $cors = Ax402_WC_Gateway_Cors::status();
        $store = $cors['store_origins'];

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html((string) $data['title']); ?></label>
            </th>
            <td class="forminp">
                <p class="description" style="margin-top:0">
                    <?php echo esc_html__('This store origin(s):', 'ax402-for-woocommerce'); ?>
                    <code><?php echo esc_html($store !== [] ? implode(', ', $store) : '—'); ?></code>
                </p>
                <?php if ($cors['synced_at'] > 0) : ?>
                    <p class="description">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: 1: datetime 2: origins */
                                __('Last CORS sync: %1$s · gateway allows: %2$s', 'ax402-for-woocommerce'),
                                wp_date(
                                    get_option('date_format') . ' ' . get_option('time_format'),
                                    $cors['synced_at']
                                ),
                                $cors['origins'] !== [] ? implode(', ', $cors['origins']) : '—'
                            )
                        );
                        ?>
                    </p>
                <?php else : ?>
                    <p class="description">
                        <?php echo esc_html__(
                            'CORS has not been synced yet. Save settings (with API key + pay-to) to push origins.',
                            'ax402-for-woocommerce'
                        ); ?>
                    </p>
                <?php endif; ?>
                <?php if ($cors['error'] !== '') : ?>
                    <p class="description" style="color:#b32d2e">
                        <?php echo esc_html($cors['error']); ?>
                    </p>
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
     * Custom settings field: checklist of platform payment tokens.
     *
     * @param string $key
     * @param array<string, mixed> $data
     */
    public function generate_ax402_tokens_html($key, $data): string
    {
        $field_key = $this->get_field_key($key);
        $refresh_key = $this->get_field_key('refresh_platform_tokens');
        $data = wp_parse_args($data, [
            'title' => '',
            'description' => '',
        ]);

        $settings = Ax402_WC_Settings::all();
        $current_base = rtrim($settings['base_url'], '/');

        // Always fetch live platform tokens when credentials exist so the admin
        // list tracks /config/platform (not a stale cache from another base URL).
        $sync_error = '';
        if (Ax402_WC_Settings::client() !== null) {
            $sync = Ax402_WC_Platform_Config_Store::sync();
            if (!$sync['ok'] && $sync['error'] !== '') {
                $sync_error = $sync['error'];
            }
            // Bust FX cache on every settings render so ZCHF/XGAS pick up new
            // /exchange-rates data (previously an empty rates:[] could stick for 5m).
            Ax402_WC_Control_Plane_Exchange_Rates::clear_cache_for_base_url($current_base);
        }

        $cache = Ax402_WC_Platform_Config_Store::get();
        $cache_base = rtrim((string) ($cache['source_base_url'] ?? ''), '/');
        $cache_stale = $cache['platform'] !== []
            && $current_base !== ''
            && (
                $cache_base === ''
                || strcasecmp($cache_base, $current_base) !== 0
            );

        $platform = $cache['platform'];
        $tokens = Ax402_WC_Platform_Tokens::enabled_tokens($platform);
        $selected = $settings['enabled_token_ids'];
        if ($selected === [] && $tokens !== []) {
            $selected = Ax402_WC_Platform_Tokens::default_enabled_token_ids(
                $platform,
                $settings['network_mode']
            );
        }
        // Drop selections that no longer exist on the live platform list.
        if ($tokens !== [] && $selected !== []) {
            $valid_ids = array_map(
                static fn (array $token): string => (string) ($token['id'] ?? ''),
                $tokens
            );
            $selected = array_values(array_intersect($selected, $valid_ids));
        }
        $rates = Ax402_WC_Composite_Exchange_Rates::default(null, true);
        $display_error = $sync_error !== '' ? $sync_error : (string) ($cache['error'] ?? '');

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
                                __('Last sync: %2$s · %1$d platform token(s).', 'ax402-for-woocommerce'),
                                $cache['token_count'],
                                wp_date(get_option('date_format') . ' ' . get_option('time_format'), $cache['synced_at'])
                            )
                        );
                        if ($cache_base !== '') {
                            echo ' ';
                            echo esc_html(
                                sprintf(
                                    /* translators: %s: API base URL */
                                    __('Source: %s', 'ax402-for-woocommerce'),
                                    $cache_base
                                )
                            );
                        }
                        ?>
                    </p>
                <?php endif; ?>
                <?php if ($cache_stale) : ?>
                    <p class="description" style="color:#b32d2e;margin-bottom:0.75em">
                        <?php echo esc_html__(
                            'Cached tokens are from a different API base URL. Save settings or click “Refresh tokens from Ax402”.',
                            'ax402-for-woocommerce'
                        ); ?>
                    </p>
                <?php endif; ?>
                <?php if ($display_error !== '') : ?>
                    <p class="description" style="color:#b32d2e;margin-bottom:0.75em">
                        <?php echo esc_html($display_error); ?>
                    </p>
                <?php endif; ?>
                <?php if ($tokens === []) : ?>
                    <p class="description">
                        <?php echo esc_html__(
                            'No platform tokens available. Enter a valid API key for the base URL above, then save or refresh.',
                            'ax402-for-woocommerce'
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
                                            class="ax402-settlement-token"
                                            name="<?php echo esc_attr($field_key); ?>[]"
                                            value="<?php echo esc_attr($id); ?>"
                                            data-network="<?php echo esc_attr($network); ?>"
                                            data-hedera="<?php echo Ax402_WC_Platform_Tokens::is_hedera_network($network) ? '1' : '0'; ?>"
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
                                                        __('%1$s · %2$d decimals', 'ax402-for-woocommerce'),
                                                        $network,
                                                        $decimals
                                                    )
                                                );
                                                ?>
                                                <?php if ($rate_ok) : ?>
                                                    · <?php
                                                    if (Ax402_WC_Stablecoin_One_To_One_Rates::is_stablecoin($symbol)) {
                                                        echo esc_html__('rate OK (1:1 USD)', 'ax402-for-woocommerce');
                                                    } else {
                                                        echo esc_html(
                                                            sprintf(
                                                                /* translators: %s: tokens per 1 USD */
                                                                __('rate OK (%s / USD)', 'ax402-for-woocommerce'),
                                                                $rate
                                                            )
                                                        );
                                                    }
                                                    ?>
                                                <?php else : ?>
                                                    · <span style="color:#b32d2e"><?php echo esc_html__('rate unavailable', 'ax402-for-woocommerce'); ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </fieldset>
                <?php endif; ?>
                <p style="margin:0.9em 0 0.35em">
                    <input type="hidden" name="<?php echo esc_attr($refresh_key); ?>" id="<?php echo esc_attr($refresh_key); ?>" value="0" />
                    <button
                        type="submit"
                        class="button button-secondary"
                        name="save"
                        value="<?php echo esc_attr__('Refresh tokens from Ax402', 'ax402-for-woocommerce'); ?>"
                        onclick="var el=document.getElementById('<?php echo esc_js($refresh_key); ?>'); if (el) { el.value='1'; }"
                    >
                        <?php echo esc_html__('Refresh tokens from Ax402', 'ax402-for-woocommerce'); ?>
                    </button>
                </p>
                <p class="description" style="margin-top:0">
                    <?php echo esc_html__(
                        'Tokens load from Ax402 /config/platform; USD rates load from /exchange-rates. Both refresh when you open this page (and when you change API base URL / API key and save). Stablecoins use 1:1; ZCHF, XGAS, and other market tokens need a live FX row.',
                        'ax402-for-woocommerce'
                    ); ?>
                </p>
                <script>
                (function () {
                    function syncHederaFields() {
                        var hederaOn = false;
                        document.querySelectorAll('input.ax402-settlement-token[data-hedera="1"]:checked:not(:disabled)').forEach(function () {
                            hederaOn = true;
                        });
                        document.querySelectorAll('[data-ax402-hedera-payto="1"]').forEach(function (el) {
                            el.readOnly = !hederaOn;
                            if (!hederaOn) {
                                el.setAttribute('aria-disabled', 'true');
                            } else {
                                el.removeAttribute('aria-disabled');
                            }
                            if (el.closest('tr')) {
                                el.closest('tr').style.opacity = hederaOn ? '' : '0.55';
                            }
                        });
                    }
                    document.addEventListener('change', function (e) {
                        if (e.target && e.target.classList && e.target.classList.contains('ax402-settlement-token')) {
                            syncHederaFields();
                        }
                    });
                    syncHederaFields();
                })();
                </script>
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
            'pay_to_address' => (string) $this->get_option('pay_to_address', ''),
            'pay_to_hedera_account_id' => (string) $this->get_option('pay_to_hedera_account_id', ''),
            'walletconnect_project_id' => (string) $this->get_option('walletconnect_project_id', ''),
            'network_mode' => (string) $this->get_option('network_mode', 'sepolia'),
            'api_slug' => (string) $this->get_option('api_slug', ''),
            'enabled_token_ids' => $this->get_option('settlement_tokens', []),
            'settlement_reconcile' => $this->get_option('settlement_reconcile', 'no') === 'yes' ? 'yes' : 'no',
            'ucp_enabled' => $this->get_option('ucp_enabled', 'yes') === 'yes' ? 'yes' : 'no',
            'ucp_max_amount' => (string) $this->get_option('ucp_max_amount', ''),
        ];

        if (!is_array($payload['enabled_token_ids'])) {
            $payload['enabled_token_ids'] = [];
        }

        $platform_preview = Ax402_WC_Platform_Config_Store::platform();
        $needs_hedera = Ax402_WC_Platform_Tokens::has_hedera_token_enabled(
            $platform_preview,
            $payload['enabled_token_ids']
        );
        if ($needs_hedera) {
            $hedera = Ax402_WC_Settings::sanitize_hedera_account_id($payload['pay_to_hedera_account_id']);
            $payload['pay_to_hedera_account_id'] = $hedera;
            $this->update_option('pay_to_hedera_account_id', $hedera);
            if ($hedera === '') {
                WC_Admin_Settings::add_error(
                    __('Enable Hedera settlements requires a valid Hedera pay-to account id (0.0.x).', 'ax402-for-woocommerce')
                );
            }
            if (trim($payload['walletconnect_project_id']) === '') {
                WC_Admin_Settings::add_error(
                    __('Enable Hedera settlements requires a WalletConnect project ID for shopper wallets.', 'ax402-for-woocommerce')
                );
            }
        } else {
            // Keep stored value but fields stay greyed in UI when no Hedera token.
            $payload['pay_to_hedera_account_id'] = Ax402_WC_Settings::sanitize_hedera_account_id(
                $payload['pay_to_hedera_account_id']
            );
        }

        $api_key = (string) $this->get_option('api_key', '');
        $api_key_changed = $api_key !== '';
        if ($api_key_changed) {
            $payload['api_key'] = $api_key;
            // Do not persist plaintext API key in gateway settings.
            $this->update_option('api_key', '');
        }

        Ax402_WC_Settings::update($payload);

        $refresh_key = $this->get_field_key('refresh_platform_tokens');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC settings form already verified.
        $refresh_posted = isset($_POST[$refresh_key]) ? sanitize_text_field(wp_unslash($_POST[$refresh_key])) : '0';
        $refresh_requested = $refresh_posted !== '' && $refresh_posted !== '0';

        $should_refresh = $refresh_requested
            || $api_key_changed
            || Ax402_WC_Platform_Config_Store::platform() === [];

        if ($should_refresh) {
            $sync = Ax402_WC_Platform_Config_Store::sync();
            if (!$sync['ok'] && $sync['error'] !== '') {
                WC_Admin_Settings::add_error(
                    sprintf(
                        /* translators: %s: error message */
                        __('Ax402 token sync: %s', 'ax402-for-woocommerce'),
                        $sync['error']
                    )
                );
            } elseif ($sync['ok']) {
                $platform = Ax402_WC_Platform_Config_Store::platform();
                $valid_ids = [];
                foreach (Ax402_WC_Platform_Tokens::enabled_tokens($platform) as $token) {
                    $id = (string) ($token['id'] ?? '');
                    if ($id !== '') {
                        $valid_ids[] = $id;
                    }
                }

                $selected = array_values(array_intersect($payload['enabled_token_ids'], $valid_ids));
                if ($selected === [] && ($base_url_changed || $api_key_changed || $refresh_requested)) {
                    $selected = Ax402_WC_Platform_Tokens::default_enabled_token_ids(
                        $platform,
                        $payload['network_mode']
                    );
                    $selected = array_values(array_intersect($selected, $valid_ids));
                }

                if ($selected !== $payload['enabled_token_ids']) {
                    Ax402_WC_Settings::update(['enabled_token_ids' => $selected]);
                    $this->update_option('settlement_tokens', $selected);
                }

                if ($base_url_changed || $api_key_changed || $refresh_requested) {
                    WC_Admin_Settings::add_message(
                        sprintf(
                            /* translators: %d: token count */
                            __('Ax402 settlement tokens refreshed (%d available).', 'ax402-for-woocommerce'),
                            $sync['token_count']
                        )
                    );
                }
            }
        }

        try {
            $settings_now = Ax402_WC_Settings::all();
            $platform_now = Ax402_WC_Platform_Config_Store::platform();
            $token_ids_now = Ax402_WC_Settings::enabled_token_ids($platform_now);
            $needs_hedera = Ax402_WC_Platform_Tokens::has_hedera_token_enabled(
                $platform_now,
                $token_ids_now
            );
            $needs_evm = false;
            foreach ($token_ids_now as $token_id) {
                $token = Ax402_WC_Platform_Tokens::find_token_by_id($platform_now, $token_id);
                if ($token === null) {
                    continue;
                }
                if (!Ax402_WC_Platform_Tokens::is_hedera_network((string) ($token['network'] ?? ''))) {
                    $needs_evm = true;
                    break;
                }
            }
            if ($platform_now === [] && $settings_now['pay_to_address'] !== '') {
                $needs_evm = true;
            }

            if (Ax402_WC_Settings::client() !== null) {
                $can_onboard = (!$needs_evm || $settings_now['pay_to_address'] !== '')
                    && (!$needs_hedera || $settings_now['pay_to_hedera_account_id'] !== '')
                    && ($needs_evm || $needs_hedera);
                if ($can_onboard) {
                    Ax402_WC_Store_Onboarding::ensure_api();
                }
                $cors = Ax402_WC_Gateway_Cors::status();
                if ($cors['error'] !== '') {
                    WC_Admin_Settings::add_error(
                        sprintf(
                            /* translators: %s: error message */
                            __('Ax402 CORS sync: %s', 'ax402-for-woocommerce'),
                            $cors['error']
                        )
                    );
                }
            }
        } catch (Throwable $e) {
            WC_Admin_Settings::add_error(
                sprintf(
                    /* translators: %s: error message */
                    __('Ax402 onboarding warning: %s', 'ax402-for-woocommerce'),
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
        if ($settings['api_key'] === '') {
            return false;
        }

        $token_ids = Ax402_WC_Settings::enabled_token_ids();
        if ($token_ids === []) {
            return false;
        }

        $platform = Ax402_WC_Platform_Config_Store::platform();
        $needs_hedera = Ax402_WC_Platform_Tokens::has_hedera_token_enabled($platform, $token_ids);
        $needs_evm = false;
        foreach ($token_ids as $token_id) {
            $token = Ax402_WC_Platform_Tokens::find_token_by_id($platform, $token_id);
            if ($token === null) {
                continue;
            }
            if (!Ax402_WC_Platform_Tokens::is_hedera_network((string) ($token['network'] ?? ''))) {
                $needs_evm = true;
                break;
            }
        }
        // Default seed tokens are EVM when platform cache is empty.
        if ($platform === []) {
            $needs_evm = true;
        }

        if ($needs_evm && $settings['pay_to_address'] === '') {
            return false;
        }
        if ($needs_hedera) {
            if (!Ax402_WC_Settings::is_valid_hedera_account_id($settings['pay_to_hedera_account_id'])) {
                return false;
            }
            if (trim($settings['walletconnect_project_id']) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{result:string,redirect?:string}
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wc_add_notice(__('Order not found.', 'ax402-for-woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        try {
            Ax402_WC_Order_Payment::prepare($order);
        } catch (Throwable $e) {
            wc_add_notice(
                sprintf(
                    /* translators: %s: error message */
                    __('Ax402 payment setup failed: %s', 'ax402-for-woocommerce'),
                    $e->getMessage()
                ),
                'error'
            );
            return ['result' => 'failure'];
        }

        $order->update_status('pending', __('Awaiting Ax402 / x402 payment.', 'ax402-for-woocommerce'));

        WC()->cart?->empty_cart();

        return [
            'result' => 'success',
            'redirect' => Ax402_WC_Order_Payment::pay_page_url($order),
        ];
    }

    private function submitted_api_key_unchanged(string $submitted): bool
    {
        if ($submitted === '') {
            return true;
        }

        $current = Ax402_WC_Settings::all()['api_key'];
        return $current !== '' && $submitted === str_repeat('*', strlen($current));
    }

    private function admin_field_description_html(string $html): string
    {
        return wp_kses($html, [
            'a' => [
                'href' => true,
                'target' => true,
                'rel' => true,
            ],
            'code' => [],
            'strong' => [],
            'em' => [],
        ]);
    }
}
