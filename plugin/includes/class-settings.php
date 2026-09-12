<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Plugin settings stored in wp_options.
 */
final class Ax402_WC_Settings
{
    public const OPTION_KEY = 'ax402_wc_settings';

    /**
     * @return array{
     *   base_url:string,
     *   api_key:string,
     *   pay_to_address:string,
     *   pay_to_hedera_account_id:string,
     *   walletconnect_project_id:string,
     *   network_mode:string,
     *   scheme:string,
     *   api_id:string,
     *   gateway_host:string,
     *   api_slug:string,
     *   hedera_api_id:string,
     *   hedera_gateway_host:string,
     *   hedera_api_slug:string,
     *   enabled_token_ids:list<string>,
     *   settlement_reconcile:string,
     *   ucp_enabled:string,
     *   ucp_max_amount:string
     * }
     */
    public static function all(): array
    {
        $defaults = [
            'base_url' => 'https://api.ax402.io',
            'api_key' => '',
            'pay_to_address' => '',
            'pay_to_hedera_account_id' => '',
            'walletconnect_project_id' => '',
            'network_mode' => 'sepolia',
            'scheme' => 'exact',
            'api_id' => '',
            'gateway_host' => '',
            'api_slug' => '',
            'hedera_api_id' => '',
            'hedera_gateway_host' => '',
            'hedera_api_slug' => '',
            'enabled_token_ids' => [],
            'settlement_reconcile' => 'no',
            'ucp_enabled' => 'yes',
            'ucp_max_amount' => '',
        ];

        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $merged = array_merge($defaults, $stored);
        if (!empty($merged['api_key_enc']) && empty($merged['api_key'])) {
            try {
                $merged['api_key'] = Ax402_WC_Crypto::decrypt((string) $merged['api_key_enc']);
            } catch (Throwable $e) {
                $merged['api_key'] = '';
            }
        }

        unset($merged['api_key_enc']);

        $token_ids = $merged['enabled_token_ids'] ?? [];
        if (!is_array($token_ids)) {
            $token_ids = [];
        }
        $token_ids = array_values(array_filter(array_map(
            static fn ($id): string => sanitize_text_field((string) $id),
            $token_ids
        )));

        $reconcile = strtolower((string) ($merged['settlement_reconcile'] ?? 'no'));
        if (!in_array($reconcile, ['yes', 'no'], true)) {
            $reconcile = 'no';
        }

        $env_base = self::environment_base_url();
        $stored_base = trim((string) $merged['base_url']);

        return [
            'base_url' => $env_base !== ''
                ? $env_base
                : ($stored_base !== '' ? $stored_base : 'https://api.ax402.io'),
            'api_key' => (string) $merged['api_key'],
            'pay_to_address' => (string) $merged['pay_to_address'],
            'pay_to_hedera_account_id' => (string) $merged['pay_to_hedera_account_id'],
            'walletconnect_project_id' => (string) $merged['walletconnect_project_id'],
            'network_mode' => in_array($merged['network_mode'], ['sepolia', 'mainnet'], true)
                ? (string) $merged['network_mode']
                : 'sepolia',
            'scheme' => (string) ($merged['scheme'] ?: 'exact'),
            'api_id' => (string) $merged['api_id'],
            'gateway_host' => (string) $merged['gateway_host'],
            'api_slug' => (string) $merged['api_slug'],
            'hedera_api_id' => (string) $merged['hedera_api_id'],
            'hedera_gateway_host' => (string) $merged['hedera_gateway_host'],
            'hedera_api_slug' => (string) $merged['hedera_api_slug'],
            'enabled_token_ids' => $token_ids,
            'settlement_reconcile' => $reconcile,
            'ucp_enabled' => self::sanitize_yes_no($merged['ucp_enabled'] ?? 'yes'),
            'ucp_max_amount' => self::sanitize_max_amount($merged['ucp_max_amount'] ?? ''),
        ];
    }

    /**
     * AX402_BASE_URL when set in the PHP process. Empty means use the stored option.
     */
    public static function environment_base_url(): string
    {
        $raw = getenv('AX402_BASE_URL');
        if (!is_string($raw) || trim($raw) === '') {
            $from_env = $_ENV['AX402_BASE_URL'] ?? '';
            $raw = is_string($from_env) ? $from_env : '';
        }
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        return self::sanitize_base_url($raw);
    }

    /**
     * Stored API base URL without applying AX402_BASE_URL.
     */
    public static function persisted_base_url(): string
    {
        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $url = trim((string) ($stored['base_url'] ?? ''));
        if ($url === '') {
            return 'https://api.ax402.io';
        }

        return self::sanitize_base_url($url) ?: 'https://api.ax402.io';
    }

    public static function ucp_enabled(): bool
    {
        return self::all()['ucp_enabled'] === 'yes';
    }

    /**
     * Control-plane API ids for this store (EVM and/or Hedera).
     *
     * @return list<string>
     */
    public static function configured_api_ids(?array $settings = null): array
    {
        $settings ??= self::all();
        $ids = [];
        foreach (['api_id', 'hedera_api_id'] as $key) {
            $id = trim((string) ($settings[$key] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Pay-to value for a settlement network (EVM address or Hedera account id).
     */
    public static function pay_to_for_network(string $network): string
    {
        $settings = self::all();
        if (Ax402_WC_Platform_Tokens::is_hedera_network($network)) {
            return trim($settings['pay_to_hedera_account_id']);
        }

        return trim($settings['pay_to_address']);
    }

    public static function is_valid_hedera_account_id(string $account_id): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/', trim($account_id)) === 1;
    }

    /**
     * Resolved merchant token ids (configured selection or USDC default seed).
     *
     * @return list<string>
     */
    public static function enabled_token_ids(?array $platform = null): array
    {
        $settings = self::all();
        $ids = $settings['enabled_token_ids'];
        if ($ids !== []) {
            return $ids;
        }

        $platform ??= Ax402_WC_Platform_Config_Store::platform_or_sync();
        if ($platform === []) {
            return [];
        }

        return Ax402_WC_Platform_Tokens::default_enabled_token_ids(
            $platform,
            $settings['network_mode']
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function update(array $input): void
    {
        $current = self::all();
        $hedera = $current['pay_to_hedera_account_id'];
        if (array_key_exists('pay_to_hedera_account_id', $input)) {
            $hedera = self::sanitize_hedera_account_id((string) $input['pay_to_hedera_account_id']);
        }

        $next = [
            'base_url' => array_key_exists('base_url', $input)
                ? (self::sanitize_base_url((string) $input['base_url']) ?: self::persisted_base_url())
                : self::persisted_base_url(),
            'pay_to_address' => isset($input['pay_to_address'])
                ? sanitize_text_field((string) $input['pay_to_address'])
                : $current['pay_to_address'],
            'pay_to_hedera_account_id' => $hedera,
            'walletconnect_project_id' => isset($input['walletconnect_project_id'])
                ? sanitize_text_field((string) $input['walletconnect_project_id'])
                : $current['walletconnect_project_id'],
            'network_mode' => isset($input['network_mode'])
                && in_array($input['network_mode'], ['sepolia', 'mainnet'], true)
                ? (string) $input['network_mode']
                : $current['network_mode'],
            'scheme' => isset($input['scheme']) ? sanitize_text_field((string) $input['scheme']) : $current['scheme'],
            'api_id' => isset($input['api_id']) ? sanitize_text_field((string) $input['api_id']) : $current['api_id'],
            'gateway_host' => isset($input['gateway_host'])
                ? sanitize_text_field((string) $input['gateway_host'])
                : $current['gateway_host'],
            'api_slug' => isset($input['api_slug'])
                ? sanitize_title((string) $input['api_slug'])
                : $current['api_slug'],
            'hedera_api_id' => isset($input['hedera_api_id'])
                ? sanitize_text_field((string) $input['hedera_api_id'])
                : $current['hedera_api_id'],
            'hedera_gateway_host' => isset($input['hedera_gateway_host'])
                ? sanitize_text_field((string) $input['hedera_gateway_host'])
                : $current['hedera_gateway_host'],
            'hedera_api_slug' => isset($input['hedera_api_slug'])
                ? sanitize_title((string) $input['hedera_api_slug'])
                : $current['hedera_api_slug'],
            'enabled_token_ids' => array_key_exists('enabled_token_ids', $input)
                ? self::sanitize_token_ids($input['enabled_token_ids'])
                : $current['enabled_token_ids'],
            'settlement_reconcile' => self::sanitize_yes_no(
                $input['settlement_reconcile'] ?? $current['settlement_reconcile']
            ),
            'ucp_enabled' => self::sanitize_yes_no(
                $input['ucp_enabled'] ?? $current['ucp_enabled']
            ),
            'ucp_max_amount' => array_key_exists('ucp_max_amount', $input)
                ? self::sanitize_max_amount($input['ucp_max_amount'])
                : $current['ucp_max_amount'],
        ];

        $api_key = $current['api_key'];
        if (array_key_exists('api_key', $input)) {
            $submitted = trim((string) $input['api_key']);
            if ($submitted !== '') {
                $api_key = $submitted;
            }
        }

        $to_store = $next;
        $to_store['api_key_enc'] = $api_key !== '' ? Ax402_WC_Crypto::encrypt($api_key) : '';
        unset($to_store['api_key']);

        update_option(self::OPTION_KEY, $to_store, false);
        Ax402_WC_Ucp_Profile_Builder::bust();
    }

    public static function sanitize_hedera_account_id(string $value): string
    {
        $value = trim(sanitize_text_field($value));
        if ($value === '') {
            return '';
        }
        if (str_starts_with(strtolower($value), '0x')) {
            return '';
        }
        if (!self::is_valid_hedera_account_id($value)) {
            return '';
        }

        return $value;
    }

    /**
     * @param mixed $ids
     * @return list<string>
     */
    private static function sanitize_token_ids(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($id): string => sanitize_text_field((string) $id),
            $ids
        ))));
    }

    private static function sanitize_base_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (function_exists('esc_url_raw')) {
            return (string) esc_url_raw($url);
        }

        return $url;
    }

    private static function sanitize_yes_no(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));
        return $normalized === 'yes' ? 'yes' : 'no';
    }

    private static function sanitize_max_amount(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        return is_string($digits) ? $digits : '';
    }

    public static function settlement_reconcile_enabled(): bool
    {
        return self::all()['settlement_reconcile'] === 'yes';
    }

    public static function client(): ?Ax402_WC_Control_Plane_Client
    {
        $settings = self::all();
        if ($settings['api_key'] === '' || $settings['base_url'] === '') {
            return null;
        }

        return new Ax402_WC_Control_Plane_Client($settings['base_url'], $settings['api_key']);
    }
}
