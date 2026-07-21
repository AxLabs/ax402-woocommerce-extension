<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Keeps Ax402 gateway CORS origins in sync with this store's browser origins
 * so the pay page can call the gateway directly (no WordPress pay-proxy).
 */
final class Ax402_WC_Gateway_Cors
{
    public const OPTION_LAST_ORIGINS = 'ax402_wc_cors_origins';
    public const OPTION_LAST_SYNC_AT = 'ax402_wc_cors_synced_at';
    public const OPTION_LAST_ERROR = 'ax402_wc_cors_error';

    /**
     * Normalize a URL to an Ax402-allowed origin, or null if invalid.
     *
     * Valid: http(s)://FQDN|localhost|127.0.0.1|[::1] with optional port.
     * No path, query, fragment, wildcards, or credentials.
     */
    public static function normalize_origin(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        if (!self::is_allowed_host($host)) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    private static function is_allowed_host(string $host): bool
    {
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]' || $host === '::1') {
            return true;
        }
        // FQDN: at least one dot, labels of alnum/hyphen.
        if (!str_contains($host, '.')) {
            return false;
        }

        return (bool) preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i',
            $host
        );
    }

    /**
     * Browser origins for this WordPress install (home / site / WP_HOME).
     *
     * @return list<string>
     */
    public static function store_origins(): array
    {
        $candidates = [];
        if (function_exists('home_url')) {
            $candidates[] = home_url('/');
        }
        if (function_exists('site_url')) {
            $candidates[] = site_url('/');
        }
        if (defined('WP_HOME') && is_string(WP_HOME) && WP_HOME !== '') {
            $candidates[] = WP_HOME;
        }
        if (defined('WP_SITEURL') && is_string(WP_SITEURL) && WP_SITEURL !== '') {
            $candidates[] = WP_SITEURL;
        }

        $origins = [];
        foreach ($candidates as $url) {
            $origin = self::normalize_origin((string) $url);
            if ($origin !== null) {
                $origins[$origin] = $origin;
            }
        }

        return array_values($origins);
    }

    /**
     * Ensure store origins are present on the Ax402 API CORS list (idempotent adds).
     *
     * @return array{ok:bool,origins:list<string>,added:list<string>,error:string}
     */
    public static function ensure_store_origins(
        string $api_id,
        ?Ax402_WC_Control_Plane_Client $client = null
    ): array {
        $client ??= Ax402_WC_Settings::client();
        if ($client === null) {
            return [
                'ok' => false,
                'origins' => [],
                'added' => [],
                'error' => 'Ax402 API client is not configured',
            ];
        }
        if ($api_id === '') {
            return [
                'ok' => false,
                'origins' => [],
                'added' => [],
                'error' => 'Missing Ax402 api_id',
            ];
        }

        $wanted = self::store_origins();
        if ($wanted === []) {
            return [
                'ok' => false,
                'origins' => [],
                'added' => [],
                'error' => 'Could not derive a valid store origin from home/site URL',
            ];
        }

        try {
            $current = $client->get_cors_origins($api_id);
            $current_set = array_fill_keys($current, true);
            $added = [];
            $latest = $current;

            foreach ($wanted as $origin) {
                if (isset($current_set[$origin])) {
                    continue;
                }
                $latest = $client->add_cors_origin($api_id, $origin);
                $current_set[$origin] = true;
                $added[] = $origin;
            }

            self::remember($latest, '');

            return [
                'ok' => true,
                'origins' => $latest,
                'added' => $added,
                'error' => '',
            ];
        } catch (Throwable $e) {
            self::remember([], $e->getMessage());
            return [
                'ok' => false,
                'origins' => [],
                'added' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param list<string> $origins
     */
    private static function remember(array $origins, string $error): void
    {
        if (!function_exists('update_option')) {
            return;
        }
        update_option(self::OPTION_LAST_ORIGINS, array_values($origins), false);
        update_option(self::OPTION_LAST_SYNC_AT, $error === '' ? time() : (int) get_option(self::OPTION_LAST_SYNC_AT, 0), false);
        update_option(self::OPTION_LAST_ERROR, $error, false);
    }

    /**
     * @return array{origins:list<string>,synced_at:int,error:string,store_origins:list<string>}
     */
    public static function status(): array
    {
        $origins = get_option(self::OPTION_LAST_ORIGINS, []);
        if (!is_array($origins)) {
            $origins = [];
        }

        return [
            'origins' => array_values(array_map('strval', $origins)),
            'synced_at' => (int) get_option(self::OPTION_LAST_SYNC_AT, 0),
            'error' => (string) get_option(self::OPTION_LAST_ERROR, ''),
            'store_origins' => self::store_origins(),
        ];
    }
}
