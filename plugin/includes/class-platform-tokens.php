<?php
declare(strict_types=1);

defined('ABSPATH') || exit;


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
            default => throw new InvalidArgumentException(esc_html('Unknown network mode: ' . $mode)),
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
     * Hex chain id for wallet_switchEthereumChain (EVM only).
     */
    public static function chain_id_hex(string $network): string
    {
        if (self::is_hedera_network($network)) {
            return '';
        }

        $id = Ax402_WC_Chain_Metadata::eip155_chain_id($network);
        if ($id === null) {
            return '';
        }

        return '0x' . dechex($id);
    }

    public static function is_hedera_network(string $network): bool
    {
        return str_starts_with(strtolower(trim($network)), 'hedera:');
    }

    /**
     * True when enabled token ids include any Hedera-network token.
     *
     * @param array<string, mixed> $platform
     * @param list<string> $enabled_token_ids
     */
    public static function has_hedera_token_enabled(array $platform, array $enabled_token_ids): bool
    {
        foreach ($enabled_token_ids as $token_id) {
            $token = self::find_token_by_id($platform, $token_id);
            if ($token === null) {
                continue;
            }
            if (self::is_hedera_network((string) ($token['network'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when enabled token ids include any non-Hedera (EVM) token.
     *
     * @param array<string, mixed> $platform
     * @param list<string> $enabled_token_ids
     */
    public static function has_evm_token_enabled(array $platform, array $enabled_token_ids): bool
    {
        foreach ($enabled_token_ids as $token_id) {
            $token = self::find_token_by_id($platform, $token_id);
            if ($token === null) {
                continue;
            }
            if (!self::is_hedera_network((string) ($token['network'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    public static function is_native_asset(string $asset): bool
    {
        $asset = strtolower(trim($asset));
        return $asset === '' || preg_match('/^0x0+$/', $asset) === 1;
    }

    /**
     * Hedera native HBAR asset forms used by platform rows.
     */
    public static function is_hedera_native_asset(string $asset): bool
    {
        $asset = strtolower(trim($asset));
        if ($asset === '' || $asset === 'hbar' || $asset === '0.0.0') {
            return true;
        }

        return preg_match('/^0x0+$/', $asset) === 1;
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

        throw new RuntimeException(esc_html('No enabled USDC token found for network ' . $network));
    }

    /**
     * Default merchant selection seeded from platform tokens.
     * Prefer tokens matching the environment seed (mainnet vs sepolia/dev).
     *
     * @param array<string, mixed> $platform
     * @return list<string>
     */
    public static function default_enabled_token_ids(array $platform, string $network_mode = 'mainnet'): array
    {
        $ids = [];
        foreach (self::enabled_tokens($platform) as $token) {
            $network = (string) ($token['network'] ?? '');
            if (!self::network_matches_mode($network, $network_mode)) {
                continue;
            }
            $ids[] = (string) $token['id'];
        }

        // Fallback: if the seed filtered everything out, keep prior “all tokens” behavior.
        if ($ids === []) {
            foreach (self::enabled_tokens($platform) as $token) {
                $ids[] = (string) $token['id'];
            }
        }

        return $ids;
    }

    /**
     * Ax402 uses eip155:845320402 as its Base Sepolia / development network id.
     */
    public static function network_matches_mode(string $network, string $network_mode): bool
    {
        $network = trim($network);
        if ($network === '') {
            return false;
        }

        $is_dev = $network === self::NETWORK_SEPOLIA
            || $network === 'eip155:84532'
            || str_contains($network, '845320402')
            || str_contains($network, '84532');

        return $network_mode === 'sepolia' ? $is_dev : !$is_dev;
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
     *   is_hedera:bool,
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
        $supported_networks = self::supported_networks_payload();
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
                $accept = self::build_accept($token, $atomic, $scheme, $supported_networks);
            } catch (Throwable $e) {
                continue;
            }

            $meta = Ax402_WC_Chain_Metadata::for_token($token);
            $label = $meta['label'] !== '' ? $meta['label'] : $catalog->label($network);
            $rpc = $meta['rpc_url'] !== '' ? $meta['rpc_url'] : $catalog->rpc_url($network);
            $explorer = $meta['explorer_url'] !== '' ? $meta['explorer_url'] : $catalog->explorer_url($network);
            $is_hedera = self::is_hedera_network($network);
            $is_stable = Ax402_WC_Stablecoin_One_To_One_Rates::is_stablecoin($symbol);
            $rate_source = $is_stable ? 'stablecoin-1to1' : 'ax402-control-plane';
            $rate_date = '';
            if (!$is_stable && $rates instanceof Ax402_WC_Composite_Exchange_Rates) {
                $rate_date = $rates->last_rate_date();
            } elseif (!$is_stable && $rates instanceof Ax402_WC_Control_Plane_Exchange_Rates) {
                $rate_date = $rates->last_rate_date();
            }

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
                'rate_date' => $rate_date,
                'rate_source' => $rate_source,
                'captured_at' => gmdate('c'),
                'chain_id_hex' => self::chain_id_hex($network),
                'rpc_url' => $rpc,
                'explorer_url' => $explorer,
                'is_hedera' => $is_hedera,
                'accept' => $accept,
            ];
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $token
     * @param array<string, mixed>|null $supported_networks Facilitator /supported-networks payload
     * @return array{scheme:string,network:string,asset:string,amount:string,max_timeout_seconds:int,extra:array<string,mixed>}
     */
    public static function build_accept(
        array $token,
        string $amount_atomic,
        string $scheme = 'exact',
        ?array $supported_networks = null
    ): array {
        $schemes = $token['schemes'] ?? ['exact'];
        if (!is_array($schemes) || !in_array($scheme, $schemes, true)) {
            throw new InvalidArgumentException(esc_html('Scheme ' . $scheme . ' is not supported for this token'));
        }

        $symbol = (string) ($token['symbol'] ?? 'TOKEN');
        $network = (string) ($token['network'] ?? '');
        if (is_array($token['extra'] ?? null)) {
            $extra = $token['extra'];
        } elseif (self::is_hedera_network($network)) {
            // Do not inject EVM eip3009 defaults for Hedera tokens.
            $extra = [
                'name' => $symbol,
            ];
        } else {
            $extra = [
                'name' => $symbol,
                'version' => '2',
                'assetTransferMethod' => 'eip3009',
            ];
        }

        if (self::is_hedera_network($network)) {
            $fee_payer = self::hedera_fee_payer($network, $extra, $supported_networks);
            if ($fee_payer === '') {
                throw new RuntimeException(
                    'Hedera facilitator feePayer is required in payment requirements (missing from token extra and /supported-networks)'
                );
            }
            $extra['feePayer'] = $fee_payer;
        }

        return [
            'scheme' => $scheme,
            'network' => $network,
            'asset' => (string) $token['asset'],
            'amount' => $amount_atomic,
            'max_timeout_seconds' => 300,
            'extra' => $extra,
        ];
    }

    /**
     * Resolve facilitator fee-payer for Hedera exact payments.
     *
     * Buyer signs a TransferTransaction with transactionId.accountId = feePayer;
     * facilitator co-signs and pays network fees at settle time.
     *
     * @param array<string, mixed> $extra
     * @param array<string, mixed>|null $supported_networks
     */
    public static function hedera_fee_payer(
        string $network,
        array $extra = [],
        ?array $supported_networks = null
    ): string {
        $from_extra = trim((string) ($extra['feePayer'] ?? $extra['fee_payer'] ?? ''));
        if ($from_extra !== '') {
            return $from_extra;
        }

        $supported_networks ??= self::supported_networks_payload();
        if (!is_array($supported_networks)) {
            return '';
        }

        $kinds = $supported_networks['kinds'] ?? null;
        if (is_array($kinds)) {
            $network_l = strtolower(trim($network));
            foreach ($kinds as $kind) {
                if (!is_array($kind)) {
                    continue;
                }
                if (strtolower(trim((string) ($kind['network'] ?? ''))) !== $network_l) {
                    continue;
                }
                $kind_extra = is_array($kind['extra'] ?? null) ? $kind['extra'] : [];
                $fee = trim((string) ($kind_extra['feePayer'] ?? $kind_extra['fee_payer'] ?? ''));
                if ($fee !== '') {
                    return $fee;
                }
            }
        }

        $signers = $supported_networks['signers'] ?? null;
        if (is_array($signers)) {
            foreach ([$network, 'hedera:*'] as $key) {
                $list = $signers[$key] ?? null;
                if (!is_array($list) || $list === []) {
                    continue;
                }
                $fee = trim((string) $list[0]);
                if ($fee !== '') {
                    return $fee;
                }
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function supported_networks_payload(): ?array
    {
        if (!class_exists('Ax402_WC_Platform_Config_Store') || !function_exists('get_option')) {
            return null;
        }

        $supported = Ax402_WC_Platform_Config_Store::get()['supported_networks'] ?? null;
        return is_array($supported) ? $supported : null;
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
