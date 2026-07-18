<?php
declare(strict_types=1);

/**
 * Build x402 accept options from Ax402 /config/platform payment tokens.
 */
final class Ax402_WC_Platform_Tokens
{
    public const NETWORK_SEPOLIA = 'eip155:845320402';
    public const NETWORK_BASE_MAINNET = 'eip155:8453';

    /**
     * Map plugin network mode to CAIP-2 network id.
     */
    public static function network_for_mode(string $mode): string
    {
        return match ($mode) {
            'mainnet' => self::NETWORK_BASE_MAINNET,
            'sepolia' => self::NETWORK_SEPOLIA,
            default => throw new InvalidArgumentException('Unknown network mode: ' . $mode),
        };
    }

    /**
     * Find the first enabled USDC token for a network in platform config.
     *
     * @param array<string, mixed> $platform
     * @return array<string, mixed>
     */
    public static function find_usdc_token(array $platform, string $network): array
    {
        $tokens = $platform['payment_tokens'] ?? [];
        if (!is_array($tokens)) {
            throw new RuntimeException('Platform config missing payment_tokens');
        }

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            if (($token['enabled'] ?? true) === false) {
                continue;
            }
            if (strtoupper((string) ($token['symbol'] ?? '')) !== 'USDC') {
                continue;
            }
            if ((string) ($token['network'] ?? '') !== $network) {
                continue;
            }
            return $token;
        }

        throw new RuntimeException('No enabled USDC token found for network ' . $network);
    }

    /**
     * Build an accepts[] entry for an endpoint.
     *
     * @param array<string, mixed> $token
     * @return array{scheme:string,network:string,asset:string,amount:string,max_timeout_seconds:int,extra:array<string,mixed>}
     */
    public static function build_accept(array $token, string $amount_atomic, string $scheme = 'exact'): array
    {
        $schemes = $token['schemes'] ?? ['exact'];
        if (!is_array($schemes) || !in_array($scheme, $schemes, true)) {
            throw new InvalidArgumentException('Scheme ' . $scheme . ' is not supported for this token');
        }

        $extra = is_array($token['extra'] ?? null) ? $token['extra'] : [
            'name' => 'USDC',
            'version' => '2',
            'assetTransferMethod' => 'eip3009',
        ];

        return [
            'scheme' => $scheme,
            'network' => (string) $token['network'],
            'asset' => (string) $token['asset'],
            'amount' => $amount_atomic,
            'max_timeout_seconds' => 300,
            'extra' => $extra,
        ];
    }

    /**
     * Gateway base URL for a hostname using platform metadata.
     *
     * @param array<string, mixed>|null $platform
     */
    public static function gateway_base_url(string $host, ?array $platform = null): string
    {
        if ($host === '') {
            return '';
        }

        $is_local = str_ends_with($host, '.localhost') || $host === 'localhost';
        $port = (int) ($platform['gateway_port'] ?? 8090);
        if ($is_local && $port !== 80 && $port !== 443) {
            return 'http://' . $host . ':' . $port;
        }

        return 'https://' . $host;
    }

    /**
     * @param array<string, mixed>|null $platform
     */
    public static function gateway_url(string $host, string $path, ?array $platform = null): string
    {
        $base = self::gateway_base_url($host, $platform);
        if ($base === '') {
            return '';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $base . $path;
    }

    /**
     * Prefer development platform domain when network mode is sepolia.
     *
     * @param array<string, mixed> $platform
     */
    public static function preferred_platform_domain(array $platform, string $mode): string
    {
        if ($mode === 'sepolia') {
            $devs = $platform['development_platform_domains'] ?? [];
            if (is_array($devs)) {
                foreach ($devs as $row) {
                    if (is_array($row) && !empty($row['enabled']) && !empty($row['domain'])) {
                        if (!empty($row['is_primary'])) {
                            return (string) $row['domain'];
                        }
                    }
                }
                foreach ($devs as $row) {
                    if (is_array($row) && !empty($row['enabled']) && !empty($row['domain'])) {
                        return (string) $row['domain'];
                    }
                }
            }
        }

        return (string) ($platform['platform_domain'] ?? 'x.ax402.io');
    }
}
