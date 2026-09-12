<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Ensures one store Ax402 API exists. EVM and Hedera tokens share that API
 * when pay_to_addresses maps each Hedera CAIP-2 network to a 0.0.x account.
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
        $settings = Ax402_WC_Settings::all();
        $client ??= Ax402_WC_Settings::client();
        if ($client === null) {
            throw new RuntimeException('Ax402 API key is not configured');
        }

        $platform = $client->get_platform_config();
        Ax402_WC_Platform_Config_Store::store($platform);
        $recipients = self::recipient_config(
            $platform,
            Ax402_WC_Settings::enabled_token_ids($platform),
            trim($settings['pay_to_address']),
            trim($settings['pay_to_hedera_account_id'])
        );

        if ($recipients['accepted_token_ids'] === []) {
            throw new RuntimeException('No settlement tokens available to scope the Ax402 API');
        }
        if ($recipients['has_evm'] && $recipients['evm_pay_to'] === '') {
            throw new RuntimeException('EVM pay-to wallet address is required');
        }
        if ($recipients['has_hedera'] && $recipients['hedera_pay_to'] === '') {
            throw new RuntimeException('Hedera pay-to account id is required');
        }
        if ($recipients['pay_to_address'] === '') {
            throw new RuntimeException('A pay-to address is required');
        }

        $upstream = untrailingslashit(home_url());
        $api_id = (string) $settings['api_id'];
        $gateway_host = (string) $settings['gateway_host'];
        $api_slug = (string) $settings['api_slug'];

        if ($api_id !== '') {
            try {
                $api = $client->get_api($api_id);
                $api = self::sync_api($client, $api, $recipients, $upstream);
                $host = $gateway_host !== ''
                    ? $gateway_host
                    : self::primary_hostname($api, $api_slug, $platform, $settings['network_mode']);

                return self::onboarded_result($api, $host, $platform, $client);
            } catch (RuntimeException $e) {
                if (stripos($e->getMessage(), 'api not found') === false) {
                    throw $e;
                }
                Ax402_WC_Settings::update([
                    'api_id' => '',
                    'gateway_host' => '',
                ]);
                $settings = Ax402_WC_Settings::all();
                $api_slug = (string) $settings['api_slug'];
            }
        }

        $slug = $api_slug !== '' ? $api_slug : self::default_slug();

        try {
            $api = $client->create_api(
                self::api_display_name(),
                $slug,
                $upstream,
                $recipients['pay_to_address'],
                $recipients['accepted_token_ids'],
                false,
                $recipients['pay_to_addresses'] !== [] ? $recipients['pay_to_addresses'] : null
            );
        } catch (RuntimeException $e) {
            if (stripos($e->getMessage(), 'slug already exists') === false) {
                throw $e;
            }
            $api = self::find_api_by_slug($client, $slug);
            if ($api === null) {
                throw $e;
            }
            $api = self::sync_api($client, $api, $recipients, $upstream);
        }

        $host = self::primary_hostname($api, $slug, $platform, $settings['network_mode']);

        Ax402_WC_Settings::update([
            'api_id' => (string) $api['id'],
            'api_slug' => $slug,
            'gateway_host' => $host,
        ]);

        return self::onboarded_result($api, $host, $platform, $client);
    }

    /**
     * @deprecated Dual APIs are no longer required. Delegates to {@see ensure_api()}.
     * @return array{api_id:string,gateway_host:string,gateway_url:string,api:array<string,mixed>,family:string}
     */
    public static function ensure_hedera_api(?Ax402_WC_Control_Plane_Client $client = null): array
    {
        return self::ensure_api($client);
    }

    /**
     * @deprecated Dual APIs are no longer required. Delegates to {@see ensure_api()}.
     * @return array{api_id:string,gateway_host:string,gateway_url:string,api:array<string,mixed>,family:string}
     */
    public static function ensure_api_for_network(
        string $network,
        ?Ax402_WC_Control_Plane_Client $client = null
    ): array {
        unset($network);

        return self::ensure_api($client);
    }

    /**
     * @deprecated Dual APIs are no longer required. Delegates to {@see ensure_api()}.
     * @return array{api_id:string,gateway_host:string,gateway_url:string,api:array<string,mixed>,family:string}
     */
    public static function ensure_api_for_family(
        string $family,
        ?Ax402_WC_Control_Plane_Client $client = null
    ): array {
        unset($family);

        return self::ensure_api($client);
    }

    /**
     * Recipients + token scope for one store API.
     *
     * @param array<string, mixed> $platform
     * @param list<string> $enabled_token_ids
     * @return array{
     *   accepted_token_ids:list<string>,
     *   pay_to_address:string,
     *   pay_to_addresses:array<string,string>,
     *   has_evm:bool,
     *   has_hedera:bool,
     *   evm_pay_to:string,
     *   hedera_pay_to:string
     * }
     */
    public static function recipient_config(
        array $platform,
        array $enabled_token_ids,
        string $evm_pay_to,
        string $hedera_pay_to
    ): array {
        $ids = self::accepted_token_ids($platform, $enabled_token_ids);
        $has_evm = false;
        $has_hedera = false;
        $hedera_networks = [];

        foreach ($ids as $token_id) {
            $token = Ax402_WC_Platform_Tokens::find_token_by_id($platform, $token_id);
            if ($token === null) {
                continue;
            }
            $network = (string) ($token['network'] ?? '');
            if (Ax402_WC_Platform_Tokens::is_hedera_network($network)) {
                $has_hedera = true;
                if ($network !== '') {
                    $hedera_networks[$network] = true;
                }
            } else {
                $has_evm = true;
            }
        }

        $pay_to_addresses = [];
        if ($has_hedera && $hedera_pay_to !== '') {
            foreach (array_keys($hedera_networks) as $network) {
                $pay_to_addresses[$network] = $hedera_pay_to;
            }
        }

        $pay_to_address = $has_evm ? $evm_pay_to : $hedera_pay_to;

        return [
            'accepted_token_ids' => $ids,
            'pay_to_address' => $pay_to_address,
            'pay_to_addresses' => $pay_to_addresses,
            'has_evm' => $has_evm,
            'has_hedera' => $has_hedera,
            'evm_pay_to' => $evm_pay_to,
            'hedera_pay_to' => $hedera_pay_to,
        ];
    }

    /**
     * @param array<string, mixed> $platform
     * @param list<string> $enabled_token_ids
     * @return list<string>
     */
    public static function accepted_token_ids(array $platform, array $enabled_token_ids): array
    {
        $ids = [];
        foreach ($enabled_token_ids as $token_id) {
            $token = Ax402_WC_Platform_Tokens::find_token_by_id($platform, $token_id);
            if ($token !== null && (string) ($token['id'] ?? '') !== '') {
                $ids[] = (string) $token['id'];
            }
        }
        if ($ids !== []) {
            return array_values(array_unique($ids));
        }

        foreach (Ax402_WC_Platform_Tokens::enabled_tokens($platform) as $token) {
            $token_id = (string) ($token['id'] ?? '');
            if ($token_id !== '') {
                $ids[] = $token_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @deprecated Use {@see accepted_token_ids()} — APIs are no longer family-scoped.
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
        foreach (self::accepted_token_ids($platform, $enabled_token_ids) as $token_id) {
            $token = Ax402_WC_Platform_Tokens::find_token_by_id($platform, $token_id);
            if ($token === null) {
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

    /**
     * @param array<string, mixed> $api
     * @param array{
     *   accepted_token_ids:list<string>,
     *   pay_to_address:string,
     *   pay_to_addresses:array<string,string>
     * } $recipients
     * @return array<string, mixed>
     */
    private static function sync_api(
        Ax402_WC_Control_Plane_Client $client,
        array $api,
        array $recipients,
        string $upstream
    ): array {
        // Recipients first: adding Hedera token ids without pay_to_addresses
        // fails with "hedera:…: payTo: invalid Hedera account id".
        $api = self::sync_pay_to(
            $client,
            $api,
            $recipients['pay_to_address'],
            $recipients['pay_to_addresses']
        );
        $api = self::sync_accepted_tokens($client, $api, $recipients['accepted_token_ids']);

        return self::sync_upstream($client, $api, $upstream);
    }

    /**
     * @param array<string, mixed> $api
     * @return array{api_id:string,gateway_host:string,gateway_url:string,api:array<string,mixed>,family:string}
     */
    private static function onboarded_result(
        array $api,
        string $host,
        array $platform,
        Ax402_WC_Control_Plane_Client $client
    ): array {
        $result = [
            'api_id' => (string) $api['id'],
            'gateway_host' => $host,
            'gateway_url' => Ax402_WC_Platform_Tokens::gateway_base_url($host, $platform),
            'api' => $api,
            'family' => self::FAMILY_EVM,
        ];
        Ax402_WC_Gateway_Cors::ensure_store_origins($result['api_id'], $client);

        return $result;
    }

    private static function default_slug(): string
    {
        return 'wc-' . substr(hash('sha256', home_url()), 0, 10);
    }

    private static function api_display_name(): string
    {
        return get_bloginfo('name') ?: 'WooCommerce Store';
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
     * @param array<string, string> $pay_to_addresses
     * @return array<string, mixed>
     */
    private static function sync_pay_to(
        Ax402_WC_Control_Plane_Client $client,
        array $api,
        string $pay_to,
        array $pay_to_addresses
    ): array {
        $api_id = (string) ($api['id'] ?? '');
        if ($api_id === '' || $pay_to === '') {
            return $api;
        }

        $wanted_map = [];
        foreach ($pay_to_addresses as $network => $address) {
            $network = trim((string) $network);
            $address = trim((string) $address);
            if ($network !== '' && $address !== '') {
                $wanted_map[$network] = $address;
            }
        }
        ksort($wanted_map, SORT_STRING);

        $current_map = [];
        $raw_map = $api['pay_to_addresses'] ?? null;
        if (is_array($raw_map)) {
            foreach ($raw_map as $network => $address) {
                $network = trim((string) $network);
                $address = trim((string) $address);
                if ($network !== '' && $address !== '') {
                    $current_map[$network] = $address;
                }
            }
        }
        ksort($current_map, SORT_STRING);

        $current_address = (string) ($api['pay_to_address'] ?? '');
        $address_same = strcasecmp($current_address, $pay_to) === 0;
        $map_same = $current_map === $wanted_map;
        if ($address_same && $map_same) {
            return $api;
        }

        $body = [
            'pay_to_mode' => 'user_wallet',
            'pay_to_address' => $pay_to,
        ];
        if ($wanted_map !== [] || $current_map !== []) {
            // Empty object clears a previous per-network map (not a merge).
            $body['pay_to_addresses'] = $wanted_map;
        }

        return $client->update_api($api_id, $body);
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
