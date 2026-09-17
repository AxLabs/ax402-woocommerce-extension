<?php
declare(strict_types=1);

defined('ABSPATH') || exit;


/**
 * Resolve EVM chain display name / public RPC / explorer from dynamic metadata.
 *
 * Prefers fields on Ax402 payment token `extra`, then one ethereum-lists chain
 * file via the GitHub Contents API. No Ax402-specific chain ids are hard-coded here.
 */
final class Ax402_WC_Chain_Metadata
{
    private const CHAIN_FILE_URL = 'https://api.github.com/repos/ethereum-lists/chains/contents/_data/chains/eip155-%d.json';
    private const CACHE_KEY_PREFIX = 'ax402_wc_chain_eip155_';
    private const CACHE_TTL = 86400;

    /** @var array<int, array<string, mixed>|null> */
    private static array $rows = [];

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

        if ($label !== '' && $rpc !== '' && $explorer !== '') {
            return [
                'label' => $label,
                'rpc_url' => $rpc,
                'explorer_url' => $explorer,
            ];
        }

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
        $network = trim($network);
        if (Ax402_WC_Platform_Tokens::is_hedera_network($network)) {
            $is_testnet = str_contains(strtolower($network), 'testnet');
            return [
                'label' => $is_testnet ? 'Hedera Testnet' : 'Hedera Mainnet',
                'rpc_url' => $is_testnet
                    ? 'https://testnet.mirrornode.hedera.com'
                    : 'https://mainnet-public.mirrornode.hedera.com',
                'explorer_url' => $is_testnet
                    ? 'https://hashscan.io/testnet'
                    : 'https://hashscan.io/mainnet',
            ];
        }

        $chain_id = self::eip155_chain_id($network);
        if ($chain_id === null) {
            return ['label' => '', 'rpc_url' => '', 'explorer_url' => ''];
        }

        $row = self::chain_row($chain_id);
        if (!is_array($row)) {
            return [
                'label' => 'Chain ' . $chain_id,
                'rpc_url' => '',
                'explorer_url' => '',
            ];
        }

        return self::metadata_from_chain_row($row, $chain_id);
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

    public static function chain_file_url(int $chain_id): string
    {
        return sprintf(self::CHAIN_FILE_URL, $chain_id);
    }

    /**
     * @param array<string, mixed> $row ethereum-lists chain object
     * @return array{label:string,rpc_url:string,explorer_url:string}
     */
    public static function metadata_from_chain_row(array $row, int $chain_id): array
    {
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
     * @return array<string, mixed>|null
     */
    private static function chain_row(int $chain_id): ?array
    {
        if (array_key_exists($chain_id, self::$rows)) {
            return self::$rows[$chain_id];
        }

        $cache_key = self::CACHE_KEY_PREFIX . $chain_id;
        if (function_exists('get_transient')) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                self::$rows[$chain_id] = $cached;
                return $cached;
            }
        }

        $raw = self::fetch_chain_json($chain_id);
        $decoded = self::decode_chain_json($raw);
        if (!is_array($decoded)) {
            self::$rows[$chain_id] = null;
            return null;
        }

        if (function_exists('set_transient')) {
            set_transient($cache_key, $decoded, self::CACHE_TTL);
        }

        self::$rows[$chain_id] = $decoded;
        return $decoded;
    }

    private static function fetch_chain_json(int $chain_id): string
    {
        if (!function_exists('wp_remote_get')) {
            return '';
        }

        $response = wp_remote_get(self::chain_file_url($chain_id), [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/vnd.github.raw+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
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

    /**
     * @return array<string, mixed>|null
     */
    private static function decode_chain_json(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        // GitHub Contents API wrapper when the raw media type is not honored.
        if (isset($decoded['encoding'], $decoded['content'])
            && $decoded['encoding'] === 'base64'
            && is_string($decoded['content'])
        ) {
            $inner = base64_decode(str_replace("\n", '', $decoded['content']), true);
            if (!is_string($inner) || $inner === '') {
                return null;
            }
            $decoded = json_decode($inner, true);
            if (!is_array($decoded)) {
                return null;
            }
        }

        return $decoded;
    }

    /** @internal tests */
    public static function reset_cache_for_tests(): void
    {
        self::$rows = [];
    }
}
