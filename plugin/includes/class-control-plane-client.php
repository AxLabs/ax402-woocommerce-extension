<?php
declare(strict_types=1);

defined('ABSPATH') || exit;


/**
 * Ax402 control-plane HTTP client (greenfield).
 */
final class Ax402_WC_Control_Plane_Client
{
    public function __construct(
        private string $base_url,
        private string $api_key,
        /** @var callable(string,string,array<string,mixed>|null):array{status:int,body:string,error?:string} */
        private $http = null,
    ) {
        $this->base_url = rtrim($base_url, '/');
        if ($this->http === null) {
            $this->http = [$this, 'wp_http'];
        }
    }

    public function base_url(): string
    {
        return $this->base_url;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status:int,data?:mixed,error?:string}
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $http = $this->http;
        $result = $http($method, $this->base_url . $path, $body);
        if (!empty($result['error'])) {
            return ['status' => (int) ($result['status'] ?? 0), 'error' => (string) $result['error']];
        }

        $status = (int) ($result['status'] ?? 0);
        $raw = (string) ($result['body'] ?? '');
        $data = $raw === '' ? null : json_decode($raw, true);

        if ($status >= 400) {
            $message = is_array($data) && isset($data['error']) ? (string) $data['error'] : 'Request failed';
            return ['status' => $status, 'error' => $message];
        }

        return ['status' => $status, 'data' => $data];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_platform_config(): array
    {
        $result = $this->request('GET', '/config/platform');
        if (isset($result['error'])) {
            throw new RuntimeException(esc_html('Platform config failed: ' . $result['error']));
        }
        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    /**
     * Facilitator-supported networks (CAIP-2 ids inside kinds[]).
     *
     * @return array<string, mixed>
     */
    public function get_supported_networks(): array
    {
        $result = $this->request('GET', '/supported-networks');
        if (isset($result['error'])) {
            throw new RuntimeException(esc_html('Supported networks failed: ' . $result['error']));
        }
        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    /**
     * Fetch USD (or other quote) prices for platform payment tokens.
     *
     * Response shape: { quote, rate_date, rates: [{ token_id, symbol, network, rate, ... }] }
     * Each `rate` is the quote-currency price of one token unit (e.g. USD per XGAS).
     *
     * @param string $quote Quote currency (default usd).
     * @param string|null $date Optional YYYY-MM-DD. When null, the API picks "today".
     * @return array{quote?:string,rate_date?:string,rates?:list<array<string,mixed>>}
     */
    public function get_exchange_rates(string $quote = 'usd', ?string $date = null): array
    {
        $quote = strtolower(trim($quote));
        if ($quote === '') {
            $quote = 'usd';
        }

        $query = 'quote=' . rawurlencode($quote);
        if ($date !== null && $date !== '') {
            $query .= '&date=' . rawurlencode($date);
        }

        $result = $this->request('GET', '/exchange-rates?' . $query);
        if (isset($result['error'])) {
            throw new RuntimeException(esc_html('Exchange rates failed: ' . $result['error']));
        }

        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list_apis(): array
    {
        $result = $this->request('GET', '/apis');
        if (isset($result['error'])) {
            throw new RuntimeException(esc_html('List APIs failed: ' . $result['error']));
        }
        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    /**
     * @param list<string>|null $accepted_token_ids When set, scopes the API to these
     *        platform token ids (required to avoid mixing eip155 + hedera on one API).
     * @return array<string, mixed>
     */
    public function create_api(
        string $name,
        string $slug,
        string $upstream_base_url,
        ?string $pay_to_address = null,
        ?array $accepted_token_ids = null,
        bool $accept_all_tokens = false,
    ): array {
        $body = [
            'name' => $name,
            'slug' => $slug,
            'upstream_base_url' => $upstream_base_url,
            'accept_all_tokens' => $accept_all_tokens,
        ];
        if ($pay_to_address !== null && $pay_to_address !== '') {
            $body['pay_to_mode'] = 'user_wallet';
            $body['pay_to_address'] = $pay_to_address;
        }
        if ($accepted_token_ids !== null) {
            $body['accepted_token_ids'] = array_values(array_filter(array_map(
                static fn ($id): string => (string) $id,
                $accepted_token_ids
            )));
        }

        $result = $this->request('POST', '/apis', $body);
        if (isset($result['error'])) {
            throw new RuntimeException(esc_html('Create API failed: ' . $result['error']));
        }
        if (!is_array($result['data'] ?? null) || empty($result['data']['id'])) {
            throw new RuntimeException('Create API returned no id');
        }

        return $result['data'];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_api(string $api_id): array
    {
        $result = $this->request('GET', '/apis/' . rawurlencode($api_id));
        if (isset($result['error'])) {
            throw new RuntimeException(esc_html('Get API failed: ' . $result['error']));
        }
        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update_api(string $api_id, array $body): array
    {
        $result = $this->request('PUT', '/apis/' . rawurlencode($api_id), $body);
        if (isset($result['error'])) {
            throw new RuntimeException(esc_html('Update API failed: ' . $result['error']));
        }
        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    public function delete_api(string $api_id): void
    {
        $result = $this->request('DELETE', '/apis/' . rawurlencode($api_id));
        if (isset($result['error']) && (int) ($result['status'] ?? 0) !== 404) {
            throw new RuntimeException(esc_html('Delete API failed: ' . $result['error']));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list_endpoints(string $api_id): array
    {
        $result = $this->request('GET', '/apis/' . rawurlencode($api_id) . '/endpoints');
        if (isset($result['error'])) {
            throw new RuntimeException(esc_html('List endpoints failed: ' . $result['error']));
        }
        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    /**
     * @param list<array<string, mixed>> $accepts
     * @param array{type:string,header?:string,value?:string}|null $upstream_auth
     * @return array<string, mixed>
     */
    public function create_endpoint(
        string $api_id,
        string $method,
        string $path_pattern,
        array $accepts,
        ?string $description = null,
        ?array $upstream_auth = null,
    ): array {
        $body = [
            'method' => strtoupper($method),
            'path_pattern' => $path_pattern,
            'accepts' => $accepts,
        ];
        if ($description !== null) {
            $body['description'] = $description;
        }
        if ($upstream_auth !== null) {
            $body['upstream_auth'] = $upstream_auth;
        }

        $result = $this->request('POST', '/apis/' . rawurlencode($api_id) . '/endpoints', $body);
        if (isset($result['error'])) {
            throw new RuntimeException(esc_html('Create endpoint failed: ' . $result['error']));
        }
        if (!is_array($result['data'] ?? null)) {
            throw new RuntimeException('Create endpoint returned invalid payload');
        }

        return $result['data'];
    }

    /**
     * @param list<array<string, mixed>> $accepts
     * @param array{type:string,header?:string,value?:string}|null $upstream_auth
     * @return array<string, mixed>
     */
    public function update_endpoint(
        string $api_id,
        string $endpoint_id,
        string $method,
        string $path_pattern,
        array $accepts,
        bool $enabled = true,
        ?string $description = null,
        ?array $upstream_auth = null,
    ): array {
        $body = [
            'method' => strtoupper($method),
            'path_pattern' => $path_pattern,
            'accepts' => $accepts,
            'enabled' => $enabled,
        ];
        if ($description !== null) {
            $body['description'] = $description;
        }
        if ($upstream_auth !== null) {
            $body['upstream_auth'] = $upstream_auth;
        }

        $result = $this->request(
            'PUT',
            '/apis/' . rawurlencode($api_id) . '/endpoints/' . rawurlencode($endpoint_id),
            $body
        );
        if (isset($result['error'])) {
            throw new RuntimeException(esc_html('Update endpoint failed: ' . $result['error']));
        }
        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    public function delete_endpoint(string $api_id, string $endpoint_id): void
    {
        $result = $this->request(
            'DELETE',
            '/apis/' . rawurlencode($api_id) . '/endpoints/' . rawurlencode($endpoint_id)
        );
        if (isset($result['error']) && (int) ($result['status'] ?? 0) !== 404) {
            throw new RuntimeException(esc_html('Delete endpoint failed: ' . $result['error']));
        }
    }

    /**
     * Settlements recorded by Ax402 after on-chain payment verification.
     *
     * @return list<array<string, mixed>>
     */
    public function list_settlements(string $api_id): array
    {
        $result = $this->request('GET', '/apis/' . rawurlencode($api_id) . '/settlements');
        if (isset($result['error'])) {
            throw new RuntimeException(esc_html('List settlements failed: ' . $result['error']));
        }

        return Ax402_WC_Settlement_Reconcile::normalize_settlements_payload($result['data'] ?? null);
    }

    /**
     * @return list<string>
     */
    public function get_cors_origins(string $api_id): array
    {
        $result = $this->request('GET', '/apis/' . rawurlencode($api_id) . '/cors');
        if (isset($result['error'])) {
            throw new RuntimeException(esc_html('Get CORS failed: ' . $result['error']));
        }
        $origins = is_array($result['data']['origins'] ?? null) ? $result['data']['origins'] : [];
        return array_values(array_filter(array_map('strval', $origins)));
    }

    /**
     * Replace the full CORS origin list for an API.
     *
     * @param list<string> $origins
     * @return list<string>
     */
    public function put_cors_origins(string $api_id, array $origins): array
    {
        $result = $this->request(
            'PUT',
            '/apis/' . rawurlencode($api_id) . '/cors',
            ['origins' => array_values($origins)]
        );
        if (isset($result['error'])) {
            throw new RuntimeException(esc_html('Put CORS failed: ' . $result['error']));
        }
        $out = is_array($result['data']['origins'] ?? null) ? $result['data']['origins'] : [];
        return array_values(array_filter(array_map('strval', $out)));
    }

    /**
     * Add one origin (idempotent).
     *
     * @return list<string>
     */
    public function add_cors_origin(string $api_id, string $origin): array
    {
        $result = $this->request(
            'POST',
            '/apis/' . rawurlencode($api_id) . '/cors',
            ['origin' => $origin]
        );
        if (isset($result['error'])) {
            throw new RuntimeException(esc_html('Add CORS origin failed: ' . $result['error']));
        }
        $out = is_array($result['data']['origins'] ?? null) ? $result['data']['origins'] : [];
        return array_values(array_filter(array_map('strval', $out)));
    }

    /**
     * @return list<string>
     */
    public function remove_cors_origin(string $api_id, string $origin): array
    {
        $result = $this->request(
            'DELETE',
            '/apis/' . rawurlencode($api_id) . '/cors?origin=' . rawurlencode($origin)
        );
        if (isset($result['error'])) {
            throw new RuntimeException(esc_html('Remove CORS origin failed: ' . $result['error']));
        }
        $out = is_array($result['data']['origins'] ?? null) ? $result['data']['origins'] : [];
        return array_values(array_filter(array_map('strval', $out)));
    }

    /**
     * @param list<array<string, mixed>> $endpoints
     * @return array<string, mixed>|null
     */
    public static function find_endpoint_by_path(array $endpoints, string $method, string $path_pattern): ?array
    {
        $method = strtoupper($method);
        foreach ($endpoints as $ep) {
            if (!is_array($ep)) {
                continue;
            }
            if (
                strtoupper((string) ($ep['method'] ?? '')) === $method
                && (string) ($ep['path_pattern'] ?? '') === $path_pattern
            ) {
                return $ep;
            }
        }
        return null;
    }

    /**
     * @param list<array<string, mixed>> $accepts
     * @param array{type:string,header?:string,value?:string}|null $upstream_auth
     * @return array<string, mixed>
     */
    public function upsert_endpoint(
        string $api_id,
        string $method,
        string $path_pattern,
        array $accepts,
        ?string $description = null,
        ?array $upstream_auth = null,
    ): array {
        $existing = self::find_endpoint_by_path($this->list_endpoints($api_id), $method, $path_pattern);
        if ($existing !== null && !empty($existing['id'])) {
            return $this->update_endpoint(
                $api_id,
                (string) $existing['id'],
                $method,
                $path_pattern,
                $accepts,
                true,
                $description,
                $upstream_auth
            );
        }

        return $this->create_endpoint(
            $api_id,
            $method,
            $path_pattern,
            $accepts,
            $description,
            $upstream_auth
        );
    }

    /**
     * Header the gateway should inject on upstream requests (SDK UpstreamAuthInput).
     *
     * Free ngrok serves ERR_NGROK_6024 to non-browser clients unless this header is present.
     *
     * @return array{type:string,header:string,value:string}|null
     */
    public static function upstream_auth_for_base_url(string $base_url): ?array
    {
        $host = (string) (wp_parse_url($base_url, PHP_URL_HOST) ?: '');
        if ($host === '' || stripos($host, 'ngrok') === false) {
            return null;
        }

        return [
            'type' => 'header',
            'header' => 'ngrok-skip-browser-warning',
            'value' => '1',
        ];
    }

    /**
     * HTTP transport via the WordPress HTTP API.
     *
     * @param array<string, mixed>|null $body
     * @return array{status:int,body:string,error?:string}
     */
    private function wp_http(string $method, string $url, ?array $body): array
    {
        if (!function_exists('wp_remote_request')) {
            return [
                'status' => 0,
                'body' => '',
                'error' => 'WordPress HTTP API (wp_remote_request) is unavailable',
            ];
        }

        $args = [
            'method' => $method,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-API-Key' => $this->api_key,
            ],
            'timeout' => 30,
        ];
        if ($body !== null) {
            $args['body'] = function_exists('wp_json_encode')
                ? wp_json_encode($body)
                : json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return ['status' => 0, 'body' => '', 'error' => $response->get_error_message()];
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
        ];
    }
}
