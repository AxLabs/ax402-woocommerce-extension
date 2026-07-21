<?php
declare(strict_types=1);

/**
 * Build x402 accept options from Ax402 /config/platform payment tokens.
 *
 * Tokens and networks come from the control plane — nothing is hard-coded per chain/asset
 * beyond optional environment seeding (dev vs prod platform domain).
 */
final class Ax402_WC_Platform_Tokens
{
    /**
     * Legacy helpers kept for older tests / onboarding seed.
     * Prefer {@see Ax402_WC_Network_Catalog} for display/RPC.
     */
    public const NETWORK_SEPOLIA = 'eip155:845320402';
    public const NETWORK_BASE_MAINNET = 'eip155:8453';

    /**
     * Map plugin environment seed to a CAIP-2 network hint (onboarding only).
     */
    public static function network_for_mode(string $mode): string
    {
        return match ($mode) {
            'mainnet' => self::NETWORK_BASE_MAINNET,
            'sepolia' => self::NETWORK_SEPOLIA,
            default => throw new InvalidArgumentException('Unknown network mode: ' . $mode),
        };
    }

    public static function network_label(string $network, ?Ax402_WC_Network_Catalog $catalog = null): string
    {
        $catalog ??= self::catalog();
        return $catalog->label($network);
    }

    /**
     * @return array<string, string>
     */
    public static function rpc_urls(?Ax402_WC_Network_Catalog $catalog = null): array
    {
        $catalog ??= self::catalog();
        return $catalog->rpc_map();
    }

    public static function rpc_url_for_network(string $network, ?Ax402_WC_Network_Catalog $catalog = null): string
    {
        $catalog ??= self::catalog();
        return $catalog->rpc_url($network);
    }

    public static function explorer_url_for_network(string $network, ?Ax402_WC_Network_Catalog $catalog = null): string
    {
        $catalog ??= self::catalog();
        return $catalog->explorer_url($network);
    }

    public static function catalog(?array $platform = null, ?array $supported = null): Ax402_WC_Network_Catalog
    {
        if ($platform !== null) {
            return Ax402_WC_Network_Catalog::from_platform($platform, $supported);
        }
        if (function_exists('get_option')) {
            return Ax402_WC_Network_Catalog::from_store();
        }

        return new Ax402_WC_Network_Catalog();
    }

    /**
     * Hex chain id for wallet_switchEthereumChain.
     */
    public static function chain_id_hex(string $network): string
    {
        $id = Ax402_WC_Chain_Metadata::eip155_chain_id($network);
        if ($id === null) {
            return '';
        }

        return '0x' . dechex($id);
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

        usort(
            $out,
            static function (array $a, array $b): int {
                return ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
            }
        );

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
     * @deprecated Prefer selecting from enabled platform tokens dynamically.
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
     * Default merchant selection: all enabled platform tokens (sorted).
     * Checkout still omits tokens without a resolvable FX rate.
     *
     * @param array<string, mixed> $platform
     * @return list<string>
     */
    public static function default_enabled_token_ids(array $platform, string $network_mode = 'mainnet'): array
    {
        unset($network_mode);
        $ids = [];
        foreach (self::enabled_tokens($platform) as $token) {
            $ids[] = (string) $token['id'];
        }

        return $ids;
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
     *   explorer_url:string,
     *   accept:array<string,mixed>
     * }>
     */
    public static function build_settlement_options(
        array $platform,
        array $enabled_token_ids,
        string $usd_total,
        string $scheme = 'exact',
        ?Ax402_WC_Exchange_Rate_Provider $rates = null,
        ?Ax402_WC_Network_Catalog $catalog = null
    ): array {
        $rates ??= Ax402_WC_Composite_Exchange_Rates::default();
        $catalog ??= self::catalog($platform);
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

            $meta = Ax402_WC_Chain_Metadata::for_token($token);
            $label = $meta['label'] !== '' ? $meta['label'] : $catalog->label($network);
            $rpc = $meta['rpc_url'] !== '' ? $meta['rpc_url'] : $catalog->rpc_url($network);
            $explorer = $meta['explorer_url'] !== '' ? $meta['explorer_url'] : $catalog->explorer_url($network);

            $options[] = [
                'token_id' => $token_id,
                'symbol' => $symbol,
                'name' => (string) ($token['name'] ?? $symbol),
                'network' => $network,
                'network_label' => $label,
                'asset' => (string) $token['asset'],
                'decimals' => $decimals,
                'amount' => $amount,
                'amount_atomic' => $atomic,
                'rate' => $rate,
                'chain_id_hex' => self::chain_id_hex($network),
                'rpc_url' => $rpc,
                'explorer_url' => $explorer,
                'accept' => $accept,
            ];
        }

        return $options;
    }

    /**
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
     * Prefer development platform domain when environment seed is sepolia.
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
