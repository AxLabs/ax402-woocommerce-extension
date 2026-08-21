<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * UCP shopping MCP: JSON-RPC 2.0 POST at /wp-json/ucp/v1/mcp.
 *
 * Official 2026-04-08 OpenRPC methods wrap the same catalog/cart/checkout/order
 * classes as REST. Shopify UCP CLI 0.6.x negotiates MCP only. x402 payment on
 * this transport uses structured `payment_required` / `_meta["x402/payment"]`
 * (binding B3b); HTTP 402 headers stay for REST.
 */
final class Ax402_WC_Ucp_Mcp
{
    public const TOOLS = [
        'search_catalog',
        'lookup_catalog',
        'get_product',
        'create_cart',
        'get_cart',
        'update_cart',
        'cancel_cart',
        'create_checkout',
        'get_checkout',
        'update_checkout',
        'complete_checkout',
        'cancel_checkout',
        'get_order',
    ];

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (!Ax402_WC_Settings::ucp_enabled()) {
            return Ax402_WC_Ucp_Response::rest_error(
                404,
                [Ax402_WC_Ucp_Response::message(
                    'error',
                    'not_found',
                    'UCP is disabled on this store.',
                    'unrecoverable'
                )]
            );
        }

        $raw = $request->get_json_params();
        $parsed = self::parse_rpc($raw);
        if ($parsed['error'] !== null) {
            return new WP_REST_Response(self::rpc_error(
                $parsed['id'],
                $parsed['error']['code'],
                $parsed['error']['message']
            ), 200);
        }

        $method = $parsed['method'];
        $id = $parsed['id'];
        $params = $parsed['params'];

        if ($method === 'initialize') {
            return new WP_REST_Response(self::rpc_result($id, self::initialize_result($params)), 200);
        }
        if ($method === 'notifications/initialized') {
            if ($id === null) {
                return new WP_REST_Response(null, 204);
            }
            return new WP_REST_Response([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => new \stdClass(),
            ], 200);
        }
        if ($method === 'ping') {
            return new WP_REST_Response([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => new \stdClass(),
            ], 200);
        }
        if ($method === 'tools/list') {
            return new WP_REST_Response(self::rpc_result($id, [
                'tools' => self::tool_descriptors(),
            ]), 200);
        }
        if ($method === 'tools/call') {
            return $this->tools_call($id, $params, $request);
        }
        if (in_array($method, self::TOOLS, true)) {
            return $this->tools_call($id, [
                'name' => $method,
                'arguments' => $params,
                '_meta' => is_array($params['_meta'] ?? null) ? $params['_meta'] : [],
            ], $request);
        }

        return new WP_REST_Response(self::rpc_error($id, -32601, 'Method not found'), 200);
    }

    /**
     * @param mixed $raw
     * @return array{id: string|int|null, method: string, params: array<string, mixed>, error: array{code:int, message:string}|null}
     */
    public static function parse_rpc(mixed $raw): array
    {
        $empty = [
            'id' => null,
            'method' => '',
            'params' => [],
            'error' => null,
        ];
        if (!is_array($raw)) {
            $empty['error'] = ['code' => -32700, 'message' => 'Parse error'];
            return $empty;
        }
        $id = $raw['id'] ?? null;
        if ($id !== null && !is_string($id) && !is_int($id)) {
            $empty['error'] = ['code' => -32600, 'message' => 'Invalid Request'];
            return $empty;
        }
        $empty['id'] = $id;
        if (($raw['jsonrpc'] ?? '') !== '2.0' || !is_string($raw['method'] ?? null) || $raw['method'] === '') {
            $empty['error'] = ['code' => -32600, 'message' => 'Invalid Request'];
            return $empty;
        }
        $empty['method'] = (string) $raw['method'];
        $params = $raw['params'] ?? [];
        $empty['params'] = is_array($params) ? $params : [];
        if (isset($raw['_meta']) && is_array($raw['_meta']) && !isset($empty['params']['_meta'])) {
            $empty['params']['_meta'] = $raw['_meta'];
        }

        return $empty;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function initialize_result(array $params): array
    {
        $requested = trim((string) ($params['protocolVersion'] ?? ''));

        return [
            'protocolVersion' => $requested !== '' ? $requested : '2025-03-26',
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'ax402-woocommerce-ucp',
                'version' => defined('AX402_WC_VERSION') ? AX402_WC_VERSION : '0.2.0',
            ],
        ];
    }

    /**
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public static function tool_descriptors(): array
    {
        $meta = [
            'type' => 'object',
            'additionalProperties' => true,
            'properties' => [
                'ucp-agent' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'properties' => [
                        'profile' => ['type' => 'string'],
                    ],
                ],
                'idempotency-key' => ['type' => 'string'],
            ],
        ];
        $object = [
            'type' => 'object',
            'additionalProperties' => true,
        ];

        $catalog = [
            'type' => 'object',
            'required' => ['meta', 'catalog'],
            'additionalProperties' => true,
            'properties' => [
                'meta' => $meta,
                'catalog' => $object,
            ],
        ];
        $checkout_create = [
            'type' => 'object',
            'required' => ['meta', 'checkout'],
            'additionalProperties' => true,
            'properties' => [
                'meta' => $meta,
                'checkout' => $object,
            ],
        ];
        $by_id = [
            'type' => 'object',
            'required' => ['meta', 'id'],
            'additionalProperties' => true,
            'properties' => [
                'meta' => $meta,
                'id' => ['type' => 'string'],
                'checkout' => $object,
            ],
        ];

        $cart_create = [
            'type' => 'object',
            'required' => ['meta', 'cart'],
            'additionalProperties' => true,
            'properties' => [
                'meta' => $meta,
                'cart' => $object,
            ],
        ];
        $cart_by_id = [
            'type' => 'object',
            'required' => ['meta', 'id'],
            'additionalProperties' => true,
            'properties' => [
                'meta' => $meta,
                'id' => ['type' => 'string'],
                'cart' => $object,
            ],
        ];
        $order_by_id = [
            'type' => 'object',
            'required' => ['meta', 'id'],
            'additionalProperties' => true,
            'properties' => [
                'meta' => $meta,
                'id' => ['type' => 'string'],
            ],
        ];

        $schemas = [
            'search_catalog' => ['Search the product catalog.', $catalog],
            'lookup_catalog' => ['Look up products by identifier.', $catalog],
            'get_product' => ['Get a single product by identifier.', $catalog],
            'create_cart' => ['Create a cart session.', $cart_create],
            'get_cart' => ['Get a cart session.', $cart_by_id],
            'update_cart' => ['Update a cart session.', $cart_by_id],
            'cancel_cart' => ['Cancel a cart session.', $cart_by_id],
            'create_checkout' => ['Create a checkout session.', $checkout_create],
            'get_checkout' => ['Get a checkout session.', $by_id],
            'update_checkout' => ['Update a checkout session.', $by_id],
            'complete_checkout' => [
                'Place the order after x402 payment. Without a signature this returns payment_required and does not settle. Pay by POST {shop}/wp-json/ucp/v1/checkout-sessions/{id}/complete with any x402 wallet (HTTP 402, then PAYMENT-SIGNATURE on the same URL), or retry this tool with params._meta["x402/payment"] after signing structuredContent.payment_required. Do not POST the MCP JSON-RPC URL as the x402 resource. Then get_checkout / get_order. Spec: https://github.com/AxLabs/ucp-x402-binding',
                $by_id,
            ],
            'cancel_checkout' => ['Cancel a checkout session.', $by_id],
            'get_order' => ['Get a placed order.', $order_by_id],
        ];

        $tools = [];
        foreach ($schemas as $name => [$description, $schema]) {
            $tools[] = [
                'name' => $name,
                'description' => $description,
                'inputSchema' => $schema,
            ];
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{id: string, body: array<string, mixed>}
     */
    public static function call_parts(string $tool, array $arguments): array
    {
        $nested = match ($tool) {
            'search_catalog', 'lookup_catalog', 'get_product' => 'catalog',
            'create_cart', 'update_cart' => 'cart',
            'create_checkout', 'update_checkout', 'complete_checkout' => 'checkout',
            default => '',
        };
        $body = $nested === '' ? $arguments : self::unwrap_shopping_body($arguments, $nested);
        $id = '';
        if (isset($arguments['id']) && (is_string($arguments['id']) || is_int($arguments['id']))) {
            $id = (string) $arguments['id'];
        } elseif (isset($body['id']) && (is_string($body['id']) || is_int($body['id']))) {
            $id = (string) $body['id'];
        }

        return ['id' => $id, 'body' => $body];
    }

    /**
     * REST is flat; MCP nests under catalog/cart/checkout. UCP CLI `--input`
     * is wrapped again, so `{ "cart": { "line_items": [] } }` arrives as
     * `{ cart: { cart: { line_items } } }`. Peel until resource fields appear.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function unwrap_shopping_body(array $payload, string $nested): array
    {
        if ($nested === '') {
            return $payload;
        }
        $body = $payload;
        for ($i = 0; $i < 5; $i++) {
            if (self::is_unwrapped_shopping_body($body, $nested)) {
                return $body;
            }
            $inner = $body[$nested] ?? null;
            if (!is_array($inner)) {
                return $body;
            }
            $body = $inner;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function is_unwrapped_shopping_body(array $body, string $nested): bool
    {
        $keys = match ($nested) {
            'cart', 'checkout' => ['line_items', 'cart_id', 'fulfillment', 'buyer', 'context'],
            'catalog' => ['query', 'ids', 'id', 'filters', 'pagination'],
            default => [],
        };
        foreach ($keys as $key) {
            if (array_key_exists($key, $body)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function tools_call(string|int|null $id, array $params, WP_REST_Request $request): WP_REST_Response
    {
        $name = isset($params['name']) ? (string) $params['name'] : '';
        $arguments = isset($params['arguments']) && is_array($params['arguments'])
            ? $params['arguments']
            : [];
        if ($name === '' || !in_array($name, self::TOOLS, true)) {
            return new WP_REST_Response(self::rpc_error($id, -32601, 'Unknown tool'), 200);
        }

        $signature = Ax402_WC_Ucp_Mcp_Payment::signature_from_call($params, $arguments);
        $signature_data = Ax402_WC_Ucp_Mcp_Payment::signature_data_from_call($params, $arguments);
        if ($signature !== '') {
            $arguments = Ax402_WC_Ucp_Mcp_Payment::inject_signature($arguments, $signature, $signature_data);
            $request->set_header('PAYMENT-SIGNATURE', $signature);
            if ($signature_data !== '') {
                $request->set_header('PAYMENT-SIGNATURE-DATA', $signature_data);
            }
        }

        $parts = self::call_parts($name, $arguments);
        $inner = $this->dispatch($name, $parts['id'], $parts['body'], $request);
        if ($inner instanceof WP_Error) {
            return new WP_REST_Response(self::rpc_error(
                $id,
                -32603,
                $inner->get_error_message()
            ), 200);
        }

        $data = $inner->get_data();
        if (!is_array($data)) {
            $data = [];
        }
        $http = (int) $inner->get_status();
        $attached = Ax402_WC_Ucp_Mcp_Payment::attach_to_tool_result($data, $inner->get_headers());
        $data = $attached['data'];
        $tool = self::tool_result_payload($data, $http, $attached['meta']);
        // JSON-RPC tools/call must stay HTTP 200. Shopify UCP CLI treats a 402
        // status as TRANSPORT_HTTP_ERROR and never reads PAYMENT-REQUIRED.
        $response = new WP_REST_Response(self::rpc_result($id, $tool), 200);

        foreach ($inner->get_headers() as $header => $value) {
            $header = (string) $header;
            if ($header === '') {
                continue;
            }
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $response->header($header, (string) $value, false);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function dispatch(
        string $tool,
        string $session_id,
        array $body,
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $catalog = new Ax402_WC_Ucp_Catalog();
        $cart = new Ax402_WC_Ucp_Cart();
        $checkout = new Ax402_WC_Ucp_Checkout();
        $order = new Ax402_WC_Ucp_Order();

        return match ($tool) {
            'search_catalog' => $catalog->search($body),
            'lookup_catalog' => $catalog->lookup($body),
            'get_product' => $catalog->get_product($body),
            'create_cart' => $cart->create($body),
            'get_cart' => $cart->get($session_id),
            'update_cart' => $cart->update($session_id, $body),
            'cancel_cart' => $cart->cancel($session_id),
            'create_checkout' => $checkout->create($body),
            'get_checkout' => $checkout->get($session_id),
            'update_checkout' => $checkout->update($session_id, $body),
            'complete_checkout' => (new Ax402_WC_Ucp_Complete())->handle($session_id, $request, $body),
            'cancel_checkout' => $checkout->cancel($session_id),
            'get_order' => $order->get($session_id),
            default => Ax402_WC_Ucp_Response::rest_error(
                400,
                [Ax402_WC_Ucp_Response::message('error', 'invalid', 'Unknown tool', 'unrecoverable')]
            ),
        };
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function tool_result_payload(array $data, int $http, array $meta = []): array
    {
        $payload = [
            'structuredContent' => $data,
            'content' => [[
                'type' => 'text',
                'text' => (string) wp_json_encode($data),
            ]],
            'isError' => $http >= 400 && $http !== 402,
        ];
        if ($meta !== []) {
            $payload['_meta'] = $meta;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function rpc_result(string|int|null $id, array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rpc_error(string|int|null $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
