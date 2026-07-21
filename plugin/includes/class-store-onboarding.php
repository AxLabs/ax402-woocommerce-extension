<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Ensures a per-store Ax402 API exists and settings are populated.
 */
final class Ax402_WC_Store_Onboarding
{
    /**
     * @return array{api_id:string,gateway_host:string,gateway_url:string,api:array<string,mixed>}
     */
    public static function ensure_api(?Ax402_WC_Control_Plane_Client $client = null): array
    {
        $settings = Ax402_WC_Settings::all();
        $client ??= Ax402_WC_Settings::client();
        if ($client === null) {
            throw new RuntimeException('Ax402 API key is not configured');
        }
        if ($settings['pay_to_address'] === '') {
            throw new RuntimeException('Pay-to wallet address is required');
        }

        $platform = $client->get_platform_config();

        if ($settings['api_id'] !== '') {
            $api = $client->get_api($settings['api_id']);
            $host = $settings['gateway_host'] !== ''
                ? $settings['gateway_host']
                : self::primary_hostname($api, $settings['api_slug'], $platform, $settings['network_mode']);

            $result = [
                'api_id' => (string) $api['id'],
                'gateway_host' => $host,
                'gateway_url' => Ax402_WC_Platform_Tokens::gateway_base_url($host, $platform),
                'api' => $api,
            ];
            Ax402_WC_Gateway_Cors::ensure_store_origins($result['api_id'], $client);
            return $result;
        }

        $slug = $settings['api_slug'] !== ''
            ? $settings['api_slug']
            : 'wc-' . substr(hash('sha256', home_url()), 0, 10);

        $upstream = untrailingslashit(home_url());
        $api = $client->create_api(
            get_bloginfo('name') ?: 'WooCommerce Store',
            $slug,
            $upstream,
            $settings['pay_to_address']
        );

        $host = self::primary_hostname($api, $slug, $platform, $settings['network_mode']);

        Ax402_WC_Settings::update([
            'api_id' => (string) $api['id'],
            'api_slug' => $slug,
            'gateway_host' => $host,
        ]);

        $result = [
            'api_id' => (string) $api['id'],
            'gateway_host' => $host,
            'gateway_url' => Ax402_WC_Platform_Tokens::gateway_base_url($host, $platform),
            'api' => $api,
        ];
        Ax402_WC_Gateway_Cors::ensure_store_origins($result['api_id'], $client);
        return $result;
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
