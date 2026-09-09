<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Ensures per-store Ax402 APIs exist (separate EVM and Hedera APIs — control plane
 * cannot mix eip155 and hedera payment tokens on one API).
 */
final class Ax402_WC_Store_Onboarding
{
    public const FAMILY_EVM = 'evm';
    public const FAMILY_HEDERA = 'hedera';

    /**
     * @return array{api_id:string,gateway_host:string,gateway_url:string,api:array<string,mixed>,family:string}
     */
    public static function ensure_api(?Ax402_WC_Control_Plane_Client $client = null): array
    {
        return self::ensure_api_for_family(self::FAMILY_EVM, $client);
    }

    /**
     * @return array{api_id:string,gateway_host:string,gateway_url:string,api:array<string,mixed>,family:string}
     */
    public static function ensure_hedera_api(?Ax402_WC_Control_Plane_Client $client = null): array
    {
        return self::ensure_api_for_family(self::FAMILY_HEDERA, $client);
    }

    /**
     * @return array{api_id:string,gateway_host:string,gateway_url:string,api:array<string,mixed>,family:string}
     */
    public static function ensure_api_for_network(
        string $network,
        ?Ax402_WC_Control_Plane_Client $client = null
    ): array {
        $family = Ax402_WC_Platform_Tokens::is_hedera_network($network)
            ? self::FAMILY_HEDERA
            : self::FAMILY_EVM;

        return self::ensure_api_for_family($family, $client);
    }

    /**
     * @return array{api_id:string,gateway_host:string,gateway_url:string,api:array<string,mixed>,family:string}
     */
    public static function ensure_api_for_family(
        string $family,
        ?Ax402_WC_Control_Plane_Client $client = null
    ): array {
        if ($family !== self::FAMILY_EVM && $family !== self::FAMILY_HEDERA) {
            throw new InvalidArgumentException(esc_html('Unknown API family: ' . $family));
        }

        $settings = Ax402_WC_Settings::all();
        $client ??= Ax402_WC_Settings::client();
        if ($client === null) {
            throw new RuntimeException('Ax402 API key is not configured');
        }

        $pay_to = $family === self::FAMILY_HEDERA
            ? trim($settings['pay_to_hedera_account_id'])
            : trim($settings['pay_to_address']);
        if ($pay_to === '') {
            throw new RuntimeException(
                $family === self::FAMILY_HEDERA
                    ? 'Hedera pay-to account id is required'
                    : 'EVM pay-to wallet address is required'
            );
        }

        $keys = self::settings_keys_for_family($family);
        $platform = $client->get_platform_config();
        Ax402_WC_Platform_Config_Store::store($platform);
        $accepted_token_ids = self::accepted_token_ids_for_family(
            $family,
            $platform,
            Ax402_WC_Settings::enabled_token_ids($platform)
        );
        if ($accepted_token_ids === []) {
            throw new RuntimeException(
                $family === self::FAMILY_HEDERA
                    ? 'No Hedera settlement tokens available to scope the Hedera API'
                    : 'No EVM settlement tokens available to scope the EVM API'
            );
        }

        $upstream = untrailingslashit(home_url());

        $api_id = (string) $settings[$keys['api_id']];
        $gateway_host = (string) $settings[$keys['gateway_host']];
        $api_slug = (string) $settings[$keys['api_slug']];

        if ($api_id !== '') {
            try {
                $api = $client->get_api($api_id);
                $api = self::sync_accepted_tokens($client, $api, $accepted_token_ids);
                $api = self::sync_upstream($client, $api, $upstream);
                $api = self::sync_pay_to($client, $api, $pay_to);
                $host = $gateway_host !== ''
                    ? $gateway_host
                    : self::primary_hostname($api, $api_slug, $platform, $settings['network_mode']);

                $result = [
                    'api_id' => (string) $api['id'],
                    'gateway_host' => $host,
                    'gateway_url' => Ax402_WC_Platform_Tokens::gateway_base_url($host, $platform),
                    'api' => $api,
                    'family' => $family,
                ];
                Ax402_WC_Gateway_Cors::ensure_store_origins($result['api_id'], $client);
                return $result;
            } catch (RuntimeException $e) {
                if (stripos($e->getMessage(), 'api not found') === false) {
                    throw $e;
                }
                Ax402_WC_Settings::update([
                    $keys['api_id'] => '',
                    $keys['gateway_host'] => '',
                ]);
                $settings = Ax402_WC_Settings::all();
                $api_slug = (string) $settings[$keys['api_slug']];
            }
        }

        $slug = $api_slug !== ''
            ? $api_slug
            : self::default_slug_for_family($family);

        try {
            $api = $client->create_api(
                self::api_display_name($family),
                $slug,
                $upstream,
                $pay_to,
                $accepted_token_ids,
                false
            );
        } catch (RuntimeException $e) {
            if (stripos($e->getMessage(), 'slug already exists') === false) {
                throw $e;
            }
            $api = self::find_api_by_slug($client, $slug);
            if ($api === null) {
                throw $e;
            }
            $api = self::sync_accepted_tokens($client, $api, $accepted_token_ids);
            $api = self::sync_upstream($client, $api, $upstream);
            $api = self::sync_pay_to($client, $api, $pay_to);
        }

        $host = self::primary_hostname($api, $slug, $platform, $settings['network_mode']);

        Ax402_WC_Settings::update([
            $keys['api_id'] => (string) $api['id'],
            $keys['api_slug'] => $slug,
            $keys['gateway_host'] => $host,
        ]);

        $result = [
            'api_id' => (string) $api['id'],
            'gateway_host' => $host,
            'gateway_url' => Ax402_WC_Platform_Tokens::gateway_base_url($host, $platform),
            'api' => $api,
            'family' => $family,
        ];
        Ax402_WC_Gateway_Cors::ensure_store_origins($result['api_id'], $client);
        return $result;
    }

    /**
     * Platform token ids belonging to one chain family (merchant selection, else all).
     *
     * @param array<string, mixed> $platform
     * @param list<string> $enabled_token_ids
     * @return list<string>
     */
    public static function accepted_token_ids_for_family(
        string $family,
        array $platform,
        array $enabled_token_ids
    ): array {
        $want_hedera = $family === self::FAMILY_HEDERA;
        $ids = [];

        foreach ($enabled_token_ids as $token_id) {
            $token = Ax402_WC_Platform_Tokens::find_token_by_id($platform, $token_id);
            if ($token === null) {
                continue;
            }
            $is_hedera = Ax402_WC_Platform_Tokens::is_hedera_network((string) ($token['network'] ?? ''));
            if ($is_hedera === $want_hedera) {
                $ids[] = $token_id;
            }
        }

        if ($ids !== []) {
            return array_values(array_unique($ids));
        }

        foreach (Ax402_WC_Platform_Tokens::enabled_tokens($platform) as $token) {
            $token_id = (string) ($token['id'] ?? '');
            if ($token_id === '') {
                continue;
            }
            $is_hedera = Ax402_WC_Platform_Tokens::is_hedera_network((string) ($token['network'] ?? ''));
            if ($is_hedera === $want_hedera) {
                $ids[] = $token_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array{api_id:string,gateway_host:string,api_slug:string}
     */
    public static function settings_keys_for_family(string $family): array
    {
        if ($family === self::FAMILY_HEDERA) {
            return [
                'api_id' => 'hedera_api_id',
                'gateway_host' => 'hedera_gateway_host',
                'api_slug' => 'hedera_api_slug',
            ];
        }

        return [
            'api_id' => 'api_id',
            'gateway_host' => 'gateway_host',
            'api_slug' => 'api_slug',
        ];
    }

    private static function default_slug_for_family(string $family): string
    {
        $base = 'wc-' . substr(hash('sha256', home_url()), 0, 10);
        return $family === self::FAMILY_HEDERA ? $base . '-hedera' : $base;
    }

    private static function api_display_name(string $family): string
    {
        $name = get_bloginfo('name') ?: 'WooCommerce Store';
        return $family === self::FAMILY_HEDERA
            ? $name . ' (Hedera)'
            : $name;
    }

    /**
     * @param array<string, mixed> $api
     * @param list<string> $token_ids
     * @return array<string, mixed>
     */
    private static function sync_accepted_tokens(
        Ax402_WC_Control_Plane_Client $client,
        array $api,
        array $token_ids
    ): array {
        $api_id = (string) ($api['id'] ?? '');
        if ($api_id === '' || $token_ids === []) {
            return $api;
        }

        $wanted = array_values(array_unique($token_ids));
        sort($wanted, SORT_STRING);

        $current = $api['accepted_token_ids'] ?? null;
        $current_ids = is_array($current)
            ? array_values(array_filter(array_map('strval', $current)))
            : [];
        sort($current_ids, SORT_STRING);

        $accept_all = !empty($api['accept_all_tokens']);
        if (!$accept_all && $current_ids === $wanted) {
            return $api;
        }

        return $client->update_api($api_id, [
            'accept_all_tokens' => false,
            'accepted_token_ids' => $wanted,
        ]);
    }

    /**
     * @param array<string, mixed> $api
     * @return array<string, mixed>
     */
    private static function sync_upstream(
        Ax402_WC_Control_Plane_Client $client,
        array $api,
        string $upstream
    ): array {
        $api_id = (string) ($api['id'] ?? '');
        if ($api_id === '') {
            return $api;
        }

        $current = rtrim((string) ($api['upstream_base_url'] ?? ''), '/');
        $wanted = rtrim($upstream, '/');
        if ($current === $wanted) {
            return $api;
        }

        return $client->update_api($api_id, [
            'upstream_base_url' => $wanted,
        ]);
    }

    /**
     * @param array<string, mixed> $api
     * @return array<string, mixed>
     */
    private static function sync_pay_to(
        Ax402_WC_Control_Plane_Client $client,
        array $api,
        string $pay_to
    ): array {
        $api_id = (string) ($api['id'] ?? '');
        if ($api_id === '' || $pay_to === '') {
            return $api;
        }

        $current = (string) ($api['pay_to_address'] ?? '');
        if (strcasecmp($current, $pay_to) === 0) {
            return $api;
        }

        return $client->update_api($api_id, [
            'pay_to_mode' => 'user_wallet',
            'pay_to_address' => $pay_to,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function find_api_by_slug(Ax402_WC_Control_Plane_Client $client, string $slug): ?array
    {
        foreach ($client->list_apis() as $api) {
            if (!is_array($api)) {
                continue;
            }
            if ((string) ($api['slug'] ?? '') === $slug) {
                return $api;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $api
     * @param array<string, mixed> $platform
     */
    public static function primary_hostname(
        array $api,
        string $slug,
        array $platform,
        string $network_mode,
    ): string {
        $domains = is_array($api['domains'] ?? null) ? $api['domains'] : [];
        foreach ($domains as $domain) {
            if (is_array($domain) && !empty($domain['is_primary']) && !empty($domain['hostname'])) {
                return (string) $domain['hostname'];
            }
        }
        foreach ($domains as $domain) {
            if (is_array($domain) && !empty($domain['hostname'])) {
                return (string) $domain['hostname'];
            }
        }

        $platform_domain = Ax402_WC_Platform_Tokens::preferred_platform_domain($platform, $network_mode);
        return $slug . '.' . $platform_domain;
    }
}
