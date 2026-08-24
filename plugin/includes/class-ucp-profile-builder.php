<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Builds the official UCP business profile (discovery document).
 *
 * Follow official UCP 2026-04-08: `services` is an object of arrays with
 * `transport` + `endpoint`. REST stays first so existing REST agents keep
 * working; MCP is advertised second for the Shopify UCP CLI (MCP-only
 * negotiation in CLI 0.6.x). Official UCP and the binding `examples/discovery.json`
 * both use maps of arrays; omit `map_order`.
 */
final class Ax402_WC_Ucp_Profile_Builder
{
    public const UCP_VERSION = '2026-04-08';
    public const HANDLER_VERSION = '2026-08-20';
    public const QUOTE_WINDOW = 600;
    public const TRANSIENT_KEY = 'ax402_wc_ucp_profile_v2';
    public const TRANSIENT_TTL = 300;
    public const SPEC_BASE = 'https://ucp.dev/2026-04-08';

    public const HANDLER_SPEC = 'https://github.com/AxLabs/ucp-x402-binding';
    public const HANDLER_SCHEMA = 'https://github.com/AxLabs/ucp-x402-binding/blob/main/schema/handler.schema.json';
    public const SHOPPING_SPEC = self::SPEC_BASE . '/specification/overview';
    public const SHOPPING_REST_SCHEMA = self::SPEC_BASE . '/services/shopping/rest.openapi.json';
    public const SHOPPING_MCP_SCHEMA = self::SPEC_BASE . '/services/shopping/mcp.openrpc.json';

    private const CAIP2 = '/^[a-z0-9-]{3,8}:[-_a-zA-Z0-9]{1,32}$/';

    /** Namespaces that have a real schema in the binding repo. Hedera is omitted. */
    private const NETWORK_SCHEMAS = [
        'eip155' => 'https://github.com/AxLabs/ucp-x402-binding/blob/main/schema/networks/eip155.schema.json',
        'neo' => 'https://github.com/AxLabs/ucp-x402-binding/blob/main/schema/networks/neo.schema.json',
        'solana' => 'https://github.com/AxLabs/ucp-x402-binding/blob/main/schema/networks/solana.schema.json',
    ];

    /**
     * @param array<string, mixed>|null $settings
     * @param array<string, mixed>|null $platform
     * @return array{ucp: array<string, mixed>}
     */
    public static function build(
        ?array $settings = null,
        ?array $platform = null,
        string $endpoint = '',
        string $mcp_endpoint = ''
    ): array {
        $settings ??= function_exists('get_option') ? Ax402_WC_Settings::all() : [];
        $platform ??= function_exists('get_option')
            ? Ax402_WC_Platform_Config_Store::platform_or_sync()
            : [];
        if ($endpoint === '' && function_exists('rest_url')) {
            $endpoint = rest_url('ucp/v1');
        }
        $endpoint = rtrim($endpoint, '/');
        if ($mcp_endpoint === '') {
            $mcp_endpoint = $endpoint !== '' ? $endpoint . '/mcp' : '';
        }
        if ($mcp_endpoint === '' && function_exists('rest_url')) {
            $mcp_endpoint = rest_url('ucp/v1/mcp');
        }
        $mcp_endpoint = rtrim($mcp_endpoint, '/');

        $token_ids = [];
        if (function_exists('get_option')) {
            $token_ids = Ax402_WC_Settings::enabled_token_ids($platform);
        } elseif (isset($settings['enabled_token_ids']) && is_array($settings['enabled_token_ids'])) {
            $token_ids = array_values(array_map('strval', $settings['enabled_token_ids']));
        }

        $x402 = self::x402_block($platform, $token_ids, $settings);

        $payment_handlers = [];
        if ($x402 !== null) {
            $payment_handlers['org.x402.payment'] = [[
                'id' => 'org.x402.payment',
                'version' => self::HANDLER_VERSION,
                'spec' => self::HANDLER_SPEC,
                'schema' => self::HANDLER_SCHEMA,
                'available_instruments' => [
                    ['type' => 'x402'],
                ],
                'x402' => $x402,
            ]];
        }

        $profile = [
            'ucp' => [
                'version' => self::UCP_VERSION,
                'services' => [
                    'dev.ucp.shopping' => [
                        [
                            'version' => self::UCP_VERSION,
                            'transport' => 'rest',
                            'endpoint' => $endpoint !== '' ? $endpoint : 'https://example.test/wp-json/ucp/v1',
                            'spec' => self::SHOPPING_SPEC,
                            'schema' => self::SHOPPING_REST_SCHEMA,
                        ],
                        [
                            'version' => self::UCP_VERSION,
                            'transport' => 'mcp',
                            'endpoint' => $mcp_endpoint !== '' ? $mcp_endpoint : 'https://example.test/wp-json/ucp/v1/mcp',
                            'spec' => self::SHOPPING_SPEC,
                            'schema' => self::SHOPPING_MCP_SCHEMA,
                        ],
                    ],
                ],
                'capabilities' => [
                    'dev.ucp.shopping.catalog.search' => [[
                        'version' => self::UCP_VERSION,
                        'spec' => self::SPEC_BASE . '/specification/catalog',
                        'schema' => self::SPEC_BASE . '/schemas/shopping/catalog_search.json',
                    ]],
                    'dev.ucp.shopping.catalog.lookup' => [[
                        'version' => self::UCP_VERSION,
                        'spec' => self::SPEC_BASE . '/specification/catalog',
                        'schema' => self::SPEC_BASE . '/schemas/shopping/catalog_lookup.json',
                    ]],
                    'dev.ucp.shopping.cart' => [[
                        'version' => self::UCP_VERSION,
                        'spec' => self::SPEC_BASE . '/specification/cart',
                        'schema' => self::SPEC_BASE . '/schemas/shopping/cart.json',
                    ]],
                    'dev.ucp.shopping.checkout' => [[
                        'version' => self::UCP_VERSION,
                        'spec' => self::SPEC_BASE . '/specification/checkout',
                        'schema' => self::SPEC_BASE . '/schemas/shopping/checkout.json',
                    ]],
                    'dev.ucp.shopping.fulfillment' => [[
                        'version' => self::UCP_VERSION,
                        'spec' => self::SPEC_BASE . '/specification/fulfillment',
                        'schema' => self::SPEC_BASE . '/schemas/shopping/fulfillment.json',
                        'extends' => 'dev.ucp.shopping.checkout',
                    ]],
                    'dev.ucp.shopping.order' => [[
                        'version' => self::UCP_VERSION,
                        'spec' => self::SPEC_BASE . '/specification/order',
                        'schema' => self::SPEC_BASE . '/schemas/shopping/order.json',
                    ]],
                ],
                'payment_handlers' => $payment_handlers,
            ],
        ];

        Ax402_WC_Ucp_Leak::assert_clean($profile, is_array($settings) ? $settings : []);

        return $profile;
    }

    /**
     * @return array{ucp: array<string, mixed>}
     */
    public static function cached(): array
    {
        if (function_exists('get_transient')) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if (is_array($cached) && isset($cached['ucp']) && is_array($cached['ucp'])) {
                /** @var array{ucp: array<string, mixed>} $cached */
                return $cached;
            }
        }

        $profile = self::build();
        if (function_exists('set_transient')) {
            set_transient(self::TRANSIENT_KEY, $profile, self::TRANSIENT_TTL);
        }

        return $profile;
    }

    public static function bust(): void
    {
        if (function_exists('delete_transient')) {
            delete_transient(self::TRANSIENT_KEY);
        }
    }

    /**
     * @param array<string, mixed> $platform
     * @param list<string> $token_ids
     * @param array<string, mixed> $settings
     * @return array<string, mixed>|null
     */
    public static function x402_block(array $platform, array $token_ids, array $settings): ?array
    {
        $networks = [];
        $assets = [];
        $seen_assets = [];

        foreach ($token_ids as $token_id) {
            $token = Ax402_WC_Platform_Tokens::find_token_by_id($platform, $token_id);
            if ($token === null) {
                continue;
            }
            $network = (string) ($token['network'] ?? '');
            $asset = (string) ($token['asset'] ?? '');
            if ($network === '' || $asset === '' || preg_match(self::CAIP2, $network) !== 1) {
                continue;
            }

            $networks[$network] = true;
            $key = strtolower($network . '|' . $asset);
            if (isset($seen_assets[$key])) {
                continue;
            }
            $seen_assets[$key] = true;

            $entry = [
                'network' => $network,
                'asset' => $asset,
                'decimals' => (int) ($token['decimals'] ?? 6),
            ];
            $symbol = (string) ($token['symbol'] ?? '');
            if ($symbol !== '') {
                $entry['symbol'] = substr($symbol, 0, 11);
            }
            $assets[] = $entry;
        }

        if ($networks === [] || $assets === []) {
            return null;
        }

        $namespaces = [];
        foreach (array_keys($networks) as $network) {
            $ns = explode(':', $network, 2)[0];
            if (isset(self::NETWORK_SCHEMAS[$ns])) {
                $namespaces[$ns] = self::NETWORK_SCHEMAS[$ns];
            }
        }

        $block = [
            'networks' => array_keys($networks),
            'assets' => $assets,
            'quote_window' => self::QUOTE_WINDOW,
            'schemes' => ['exact'],
        ];
        if ($namespaces !== []) {
            $block['network_schemas'] = $namespaces;
        }

        $max = trim((string) ($settings['ucp_max_amount'] ?? ''));
        if ($max !== '' && preg_match('/^[0-9]+$/', $max) === 1) {
            $block['max_amount'] = $max;
        }

        return $block;
    }
}
