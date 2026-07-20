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
     * @return array<string, string> CAIP-2 => human label
     */
    public static function network_labels(): array
    {
        return [
            self::NETWORK_BASE_MAINNET => 'Base mainnet',
            self::NETWORK_SEPOLIA => 'Base Sepolia',
        ];
    }

    public static function network_label(string $network): string
    {
        $labels = self::network_labels();
        return $labels[$network] ?? $network;
    }

    /**
     * @return array<string, string> CAIP-2 => public RPC URL
     */
    public static function rpc_urls(): array
    {
        return [
            self::NETWORK_BASE_MAINNET => 'https://mainnet.base.org',
            self::NETWORK_SEPOLIA => 'https://sepolia.base.org',
        ];
    }

    public static function rpc_url_for_network(string $network): string
    {
        $map = self::rpc_urls();
        return $map[$network] ?? '';
    }

    /**
     * Hex chain id for wallet_switchEthereumChain (without 0x prefix digits from CAIP-2).
     */
    public static function chain_id_hex(string $network): string
    {
        if (!str_starts_with($network, 'eip155:')) {
            return '';
        }
        $id = substr($network, strlen('eip155:'));
        if (!ctype_digit($id)) {
            return '';
        }

        return '0x' . dechex((int) $id);
    }

    public static function is_native_asset(string $asset): bool
    {
        $asset = strtolower(trim($asset));
        return $asset === '' || preg_match('/^0x0+$/', $asset) === 1;
    }

    /**
     * @param array<string, mixed> $platform
     * @return list<array<string, mixed>>
     */
    public static function enabled_tokens(array $platform): array
    {
        $tokens = $platform['payment_tokens'] ?? [];
        if (!is_array($tokens)) {
            return [];
        }

        $out = [];
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            if (($token['enabled'] ?? true) === false) {
                continue;
            }
            if ((string) ($token['id'] ?? '') === '' || (string) ($token['symbol'] ?? '') === '') {
                continue;
            }
            if ((string) ($token['network'] ?? '') === '' || (string) ($token['asset'] ?? '') === '') {
                continue;
            }
            $out[] = $token;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $platform
     * @return array<string, mixed>|null
     */
    public static function find_token_by_id(array $platform, string $token_id): ?array
    {
        foreach (self::enabled_tokens($platform) as $token) {
            if ((string) ($token['id'] ?? '') === $token_id) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Find the first enabled USDC token for a network in platform config.
     *
     * @param array<string, mixed> $platform
     * @return array<string, mixed>
     */
    public static function find_usdc_token(array $platform, string $network): array
    {
        foreach (self::enabled_tokens($platform) as $token) {
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
     * Default token ids when merchant has not configured a selection yet.
     *
     * @param array<string, mixed> $platform
     * @return list<string>
     */
    public static function default_enabled_token_ids(array $platform, string $network_mode = 'mainnet'): array
    {
        try {
            $network = self::network_for_mode($network_mode);
            $token = self::find_usdc_token($platform, $network);
            $id = (string) ($token['id'] ?? '');
            return $id !== '' ? [$id] : [];
        } catch (Throwable $e) {
            $ids = [];
            foreach (self::enabled_tokens($platform) as $token) {
                if (strtoupper((string) ($token['symbol'] ?? '')) === 'USDC') {
                    $ids[] = (string) $token['id'];
                }
            }
            return $ids;
        }
    }

    /**
     * Build settlement options (accepts + display meta) for an USD order total.
     *
     * @param array<string, mixed> $platform
     * @param list<string> $enabled_token_ids
     * @return list<array{
     *   token_id:string,
     *   symbol:string,
     *   name:string,
     *   network:string,
     *   network_label:string,
     *   asset:string,
     *   decimals:int,
     *   amount:string,
     *   amount_atomic:string,
     *   rate:string,
     *   chain_id_hex:string,
     *   rpc_url:string,
     *   accept:array<string,mixed>
     * }>
     */
    public static function build_settlement_options(
        array $platform,
        array $enabled_token_ids,
        string $usd_total,
        string $scheme = 'exact',
        ?Ax402_WC_Exchange_Rate_Provider $rates = null
    ): array {
        $rates ??= Ax402_WC_Composite_Exchange_Rates::default();
        $options = [];

        foreach ($enabled_token_ids as $token_id) {
            $token = self::find_token_by_id($platform, $token_id);
            if ($token === null) {
                continue;
            }

            $symbol = (string) $token['symbol'];
            $network = (string) $token['network'];
            $rate = $rates->rate_usd_to_token($symbol, $network);
            if ($rate === null) {
                continue;
            }

            $decimals = (int) ($token['decimals'] ?? 6);
            if ($decimals < 0 || $decimals > 18) {
                continue;
            }

            try {
                $amount = Ax402_WC_Money::usd_to_token_amount($usd_total, $rate, $decimals);
                $atomic = Ax402_WC_Money::to_atomic($amount, $decimals);
                $accept = self::build_accept($token, $atomic, $scheme);
            } catch (Throwable $e) {
                continue;
            }

            $options[] = [
                'token_id' => $token_id,
                'symbol' => $symbol,
                'name' => (string) ($token['name'] ?? $symbol),
                'network' => $network,
                'network_label' => self::network_label($network),
                'asset' => (string) $token['asset'],
                'decimals' => $decimals,
                'amount' => $amount,
                'amount_atomic' => $atomic,
                'rate' => $rate,
                'chain_id_hex' => self::chain_id_hex($network),
                'rpc_url' => self::rpc_url_for_network($network),
                'accept' => $accept,
            ];
        }

        return $options;
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

        $symbol = (string) ($token['symbol'] ?? 'TOKEN');
        $extra = is_array($token['extra'] ?? null) ? $token['extra'] : [
            'name' => $symbol,
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
