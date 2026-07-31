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
     *   network_mode:string,
     *   scheme:string,
     *   api_id:string,
     *   gateway_host:string,
     *   api_slug:string,
     *   enabled_token_ids:list<string>,
     *   settlement_reconcile:string
     * }
     */
    public static function all(): array
    {
        $defaults = [
            'base_url' => 'https://api.ax402.io',
            'api_key' => '',
            'pay_to_address' => '',
            'network_mode' => 'sepolia',
            'scheme' => 'exact',
            'api_id' => '',
            'gateway_host' => '',
            'api_slug' => '',
            'enabled_token_ids' => [],
            'settlement_reconcile' => 'yes',
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

        $reconcile = strtolower((string) ($merged['settlement_reconcile'] ?? 'yes'));
        if (!in_array($reconcile, ['yes', 'no'], true)) {
            $reconcile = 'yes';
        }

        return [
            'base_url' => (string) $merged['base_url'],
            'api_key' => (string) $merged['api_key'],
            'pay_to_address' => (string) $merged['pay_to_address'],
            'network_mode' => in_array($merged['network_mode'], ['sepolia', 'mainnet'], true)
                ? (string) $merged['network_mode']
                : 'sepolia',
            'scheme' => (string) ($merged['scheme'] ?: 'exact'),
            'api_id' => (string) $merged['api_id'],
            'gateway_host' => (string) $merged['gateway_host'],
            'api_slug' => (string) $merged['api_slug'],
            'enabled_token_ids' => $token_ids,
            'settlement_reconcile' => $reconcile,
        ];
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
        $next = [
            'base_url' => isset($input['base_url'])
                ? esc_url_raw((string) $input['base_url'])
                : $current['base_url'],
            'pay_to_address' => isset($input['pay_to_address'])
                ? sanitize_text_field((string) $input['pay_to_address'])
                : $current['pay_to_address'],
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
            'enabled_token_ids' => array_key_exists('enabled_token_ids', $input)
                ? self::sanitize_token_ids($input['enabled_token_ids'])
                : $current['enabled_token_ids'],
            'settlement_reconcile' => self::sanitize_yes_no(
                $input['settlement_reconcile'] ?? $current['settlement_reconcile']
            ),
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

    private static function sanitize_yes_no(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));
        return $normalized === 'yes' ? 'yes' : 'no';
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
