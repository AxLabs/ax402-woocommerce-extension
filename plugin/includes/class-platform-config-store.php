<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Cached Ax402 platform config (payment tokens + supported networks) for admin + checkout.
 */
final class Ax402_WC_Platform_Config_Store
{
    public const OPTION_KEY = 'ax402_wc_platform_cache';

    /**
     * @return array{
     *   platform:array<string,mixed>,
     *   supported_networks:array<string,mixed>|null,
     *   synced_at:int,
     *   error:string,
     *   token_count:int
     * }
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $platform = is_array($stored['platform'] ?? null) ? $stored['platform'] : [];
        $supported = $stored['supported_networks'] ?? null;
        if ($supported !== null && !is_array($supported)) {
            $supported = null;
        }

        return [
            'platform' => $platform,
            'supported_networks' => $supported,
            'synced_at' => (int) ($stored['synced_at'] ?? 0),
            'error' => (string) ($stored['error'] ?? ''),
            'token_count' => count(Ax402_WC_Platform_Tokens::enabled_tokens($platform)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function platform(): array
    {
        return self::get()['platform'];
    }

    /**
     * Persist a platform config payload (e.g. after a successful live fetch).
     *
     * @param array<string, mixed> $platform
     * @param array<string, mixed>|null $supported_networks
     */
    public static function store(
        array $platform,
        string $error = '',
        ?array $supported_networks = null
    ): void {
        $current = self::get();
        update_option(
            self::OPTION_KEY,
            [
                'platform' => $platform,
                'supported_networks' => $supported_networks ?? $current['supported_networks'],
                'synced_at' => time(),
                'error' => $error,
            ],
            false
        );
    }

    /**
     * Fetch live platform config (+ supported networks) and persist cache.
     *
     * @return array{ok:bool,error:string,token_count:int,synced_at:int}
     */
    public static function sync(?Ax402_WC_Control_Plane_Client $client = null): array
    {
        $client ??= Ax402_WC_Settings::client();
        if ($client === null) {
            $payload = [
                'platform' => self::platform(),
                'supported_networks' => self::get()['supported_networks'],
                'synced_at' => (int) (self::get()['synced_at'] ?? 0),
                'error' => 'API key is required to sync platform tokens.',
            ];
            update_option(self::OPTION_KEY, $payload, false);

            return [
                'ok' => false,
                'error' => $payload['error'],
                'token_count' => 0,
                'synced_at' => $payload['synced_at'],
            ];
        }

        try {
            $platform = $client->get_platform_config();
            $supported = null;
            try {
                $supported = $client->get_supported_networks();
            } catch (Throwable $e) {
                // Tokens still usable without facilitator network list.
                $supported = self::get()['supported_networks'];
            }
            self::store($platform, '', $supported);

            return [
                'ok' => true,
                'error' => '',
                'token_count' => count(Ax402_WC_Platform_Tokens::enabled_tokens($platform)),
                'synced_at' => time(),
            ];
        } catch (Throwable $e) {
            $current = self::get();
            $payload = [
                'platform' => $current['platform'],
                'supported_networks' => $current['supported_networks'],
                'synced_at' => $current['synced_at'],
                'error' => $e->getMessage(),
            ];
            update_option(self::OPTION_KEY, $payload, false);

            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'token_count' => $current['token_count'],
                'synced_at' => $current['synced_at'],
            ];
        }
    }

    /**
     * Return cached platform, syncing once if empty and credentials exist.
     *
     * @return array<string, mixed>
     */
    public static function platform_or_sync(): array
    {
        $cached = self::get();
        if ($cached['platform'] !== [] && $cached['token_count'] > 0) {
            return $cached['platform'];
        }

        self::sync();
        return self::platform();
    }
}
