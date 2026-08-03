<?php
declare(strict_types=1);

defined('ABSPATH') || exit;


/**
 * Resolve EVM chain display name / public RPC / explorer from dynamic metadata.
 *
 * Prefers fields on Ax402 payment token `extra`, then ethereum-lists via chainid.network.
 * No Ax402-specific chain ids are hard-coded here.
 */
final class Ax402_WC_Chain_Metadata
{
    private const CHAINLIST_URL = 'https://chainid.network/chains.json';
    private const CACHE_KEY = 'ax402_wc_chainlist_v1';
    private const CACHE_TTL = 86400;

    /** @var array<int, array<string, mixed>>|null */
    private static ?array $chainlist = null;

    /**
     * @param array<string, mixed> $token Platform payment token row
     * @return array{label:string,rpc_url:string,explorer_url:string}
     */
    public static function for_token(array $token): array
    {
        $network = (string) ($token['network'] ?? '');
        $extra = is_array($token['extra'] ?? null) ? $token['extra'] : [];

        $label = self::string_from_extra($extra, [
            'chainName',
            'networkName',
            'network_label',
            'chain_label',
        ]);
        $rpc = self::string_from_extra($extra, [
            'rpcUrl',
            'rpc_url',
            'rpc',
        ]);
        if ($rpc === '' && isset($extra['rpcUrls']) && is_array($extra['rpcUrls'])) {
            foreach ($extra['rpcUrls'] as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $rpc = $candidate;
                    break;
                }
            }
        }
        $explorer = self::string_from_extra($extra, [
            'blockExplorerUrl',
            'block_explorer_url',
            'explorerUrl',
            'explorer',
        ]);

        $from_list = self::from_caip2($network);
        if ($label === '') {
            $label = $from_list['label'];
        }
        if ($rpc === '') {
            $rpc = $from_list['rpc_url'];
        }
        if ($explorer === '') {
            $explorer = $from_list['explorer_url'];
        }

        if ($label === '') {
            $label = $network !== '' ? $network : 'Unknown network';
        }

        return [
            'label' => $label,
            'rpc_url' => $rpc,
            'explorer_url' => $explorer,
        ];
    }

    /**
     * @return array{label:string,rpc_url:string,explorer_url:string}
     */
    public static function from_caip2(string $network): array
    {
        $chain_id = self::eip155_chain_id($network);
        if ($chain_id === null) {
            return ['label' => '', 'rpc_url' => '', 'explorer_url' => ''];
        }

        $row = self::chainlist()[$chain_id] ?? null;
        if (!is_array($row)) {
            return [
                'label' => 'Chain ' . $chain_id,
                'rpc_url' => '',
                'explorer_url' => '',
            ];
        }

        $rpc = '';
        $rpcs = $row['rpc'] ?? [];
        if (is_array($rpcs)) {
            foreach ($rpcs as $candidate) {
                if (!is_string($candidate) || $candidate === '') {
                    continue;
                }
                // Skip templates that need API keys.
                if (str_contains($candidate, '${') || str_contains($candidate, '{')) {
                    continue;
                }
                $rpc = $candidate;
                break;
            }
        }

        $explorer = '';
        $explorers = $row['explorers'] ?? [];
        if (is_array($explorers)) {
            foreach ($explorers as $ex) {
                if (is_array($ex) && !empty($ex['url']) && is_string($ex['url'])) {
                    $explorer = rtrim((string) $ex['url'], '/');
                    break;
                }
            }
        }

        return [
            'label' => (string) ($row['name'] ?? ('Chain ' . $chain_id)),
            'rpc_url' => $rpc,
            'explorer_url' => $explorer,
        ];
    }

    public static function eip155_chain_id(string $network): ?int
    {
        if (!str_starts_with($network, 'eip155:')) {
            return null;
        }
        $id = substr($network, strlen('eip155:'));
        if (!ctype_digit($id)) {
            return null;
        }

        return (int) $id;
    }

    /**
     * @param list<string> $keys
     * @param array<string, mixed> $extra
     */
    private static function string_from_extra(array $extra, array $keys): string
    {
        foreach ($keys as $key) {
            if (!empty($extra[$key]) && is_string($extra[$key])) {
                return trim($extra[$key]);
            }
        }

        return '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function chainlist(): array
    {
        if (self::$chainlist !== null) {
            return self::$chainlist;
        }

        if (function_exists('get_transient')) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                self::$chainlist = self::index_chainlist($cached);
                return self::$chainlist;
            }
        }

        $raw = self::fetch_chainlist_json();
        $decoded = is_string($raw) && $raw !== ''
            ? json_decode($raw, true)
            : null;
        if (!is_array($decoded)) {
            self::$chainlist = [];
            return self::$chainlist;
        }

        if (function_exists('set_transient')) {
            set_transient(self::CACHE_KEY, $decoded, self::CACHE_TTL);
        }

        self::$chainlist = self::index_chainlist($decoded);
        return self::$chainlist;
    }

    /**
     * @param list<mixed>|array<string, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function index_chainlist(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $row['chainId'] ?? null;
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                continue;
            }
            $out[(int) $id] = $row;
        }

        return $out;
    }

    private static function fetch_chainlist_json(): string
    {
        if (!function_exists('wp_remote_get')) {
            return '';
        }

        $response = wp_remote_get(self::CHAINLIST_URL, [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) {
            return '';
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return '';
        }

        return (string) wp_remote_retrieve_body($response);
    }

    /** @internal tests */
    public static function reset_cache_for_tests(): void
    {
        self::$chainlist = null;
    }
}
