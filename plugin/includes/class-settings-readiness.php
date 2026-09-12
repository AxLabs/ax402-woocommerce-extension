<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Whether this store can take Ax402 payments (independent of Enable/Disable).
 */
final class Ax402_WC_Settings_Readiness
{
    /**
     * @param array{
     *   currency?:string,
     *   api_key?:string,
     *   pay_to_address?:string,
     *   pay_to_hedera_account_id?:string,
     *   walletconnect_project_id?:string,
     *   token_ids?:list<string>,
     *   platform?:array<string,mixed>
     * }|null $context
     */
    public static function is_ready(?array $context = null): bool
    {
        foreach (self::items($context) as $item) {
            if (!$item['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{
     *   currency?:string,
     *   api_key?:string,
     *   pay_to_address?:string,
     *   pay_to_hedera_account_id?:string,
     *   walletconnect_project_id?:string,
     *   token_ids?:list<string>,
     *   platform?:array<string,mixed>
     * }|null $context
     * @return list<array{id:string,ok:bool,label:string,fix:string}>
     */
    public static function items(?array $context = null): array
    {
        $ctx = self::resolve_context($context);
        $needs_evm = self::needs_evm($ctx['platform'], $ctx['token_ids']);
        $needs_hedera = Ax402_WC_Platform_Tokens::has_hedera_token_enabled(
            $ctx['platform'],
            $ctx['token_ids']
        );

        $items = [
            [
                'id' => 'currency',
                'ok' => strtoupper($ctx['currency']) === 'USD',
                'label' => __('Store currency is USD', 'ax402-for-woocommerce'),
                'fix' => __('Set the store currency to USD in WooCommerce → Settings → General.', 'ax402-for-woocommerce'),
            ],
            [
                'id' => 'api_key',
                'ok' => $ctx['api_key'] !== '',
                'label' => __('Ax402 API Key is set', 'ax402-for-woocommerce'),
                'fix' => __('Paste an Ax402 API Key in the Account section below.', 'ax402-for-woocommerce'),
            ],
            [
                'id' => 'tokens',
                'ok' => $ctx['token_ids'] !== [],
                'label' => __('At least one settlement token is enabled', 'ax402-for-woocommerce'),
                'fix' => __('Save settings with a valid API key, then enable a settlement token.', 'ax402-for-woocommerce'),
            ],
        ];

        if ($needs_evm) {
            $items[] = [
                'id' => 'evm_wallet',
                'ok' => Ax402_WC_Evm_Address::is_valid($ctx['pay_to_address']),
                'label' => __('EVM pay-to wallet is set', 'ax402-for-woocommerce'),
                'fix' => __('Enter a valid EVM pay-to address in Payout wallets.', 'ax402-for-woocommerce'),
            ];
        }

        if ($needs_hedera) {
            $parsed = Ax402_WC_Settings::parse_hedera_account_id($ctx['pay_to_hedera_account_id']);
            $items[] = [
                'id' => 'hedera_wallet',
                'ok' => $parsed['ok'] && $parsed['value'] !== '',
                'label' => __('Hedera pay-to account is set', 'ax402-for-woocommerce'),
                'fix' => __('Enter a Hedera account id (0.0.x) in Payout wallets.', 'ax402-for-woocommerce'),
            ];
            $items[] = [
                'id' => 'walletconnect',
                'ok' => $ctx['walletconnect_project_id'] !== '',
                'label' => __('WalletConnect project ID is set', 'ax402-for-woocommerce'),
                'fix' => __('Add a WalletConnect project ID from Reown (required for Hedera shopper wallets).', 'ax402-for-woocommerce'),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $platform
     * @param list<string> $token_ids
     */
    public static function needs_evm(array $platform, array $token_ids): bool
    {
        if ($platform === []) {
            return true;
        }

        return Ax402_WC_Platform_Tokens::has_evm_token_enabled($platform, $token_ids);
    }

    /**
     * @param array{
     *   currency?:string,
     *   api_key?:string,
     *   pay_to_address?:string,
     *   pay_to_hedera_account_id?:string,
     *   walletconnect_project_id?:string,
     *   token_ids?:list<string>,
     *   platform?:array<string,mixed>
     * }|null $context
     * @return array{
     *   currency:string,
     *   api_key:string,
     *   pay_to_address:string,
     *   pay_to_hedera_account_id:string,
     *   walletconnect_project_id:string,
     *   token_ids:list<string>,
     *   platform:array<string,mixed>
     * }
     */
    private static function resolve_context(?array $context): array
    {
        $settings = ($context === null) ? Ax402_WC_Settings::all() : null;
        $platform = is_array($context['platform'] ?? null)
            ? $context['platform']
            : (($settings !== null && class_exists(Ax402_WC_Platform_Config_Store::class))
                ? Ax402_WC_Platform_Config_Store::platform()
                : []);

        $token_ids = $context['token_ids'] ?? null;
        if (!is_array($token_ids)) {
            $token_ids = $settings !== null ? Ax402_WC_Settings::enabled_token_ids($platform) : [];
        }

        $currency = $context['currency'] ?? (function_exists('get_woocommerce_currency')
            ? (string) get_woocommerce_currency()
            : '');

        return [
            'currency' => (string) $currency,
            'api_key' => (string) ($context['api_key'] ?? ($settings['api_key'] ?? '')),
            'pay_to_address' => (string) ($context['pay_to_address'] ?? ($settings['pay_to_address'] ?? '')),
            'pay_to_hedera_account_id' => (string) (
                $context['pay_to_hedera_account_id'] ?? ($settings['pay_to_hedera_account_id'] ?? '')
            ),
            'walletconnect_project_id' => trim((string) (
                $context['walletconnect_project_id'] ?? ($settings['walletconnect_project_id'] ?? '')
            )),
            'token_ids' => array_values(array_map('strval', $token_ids)),
            'platform' => $platform,
        ];
    }
}
