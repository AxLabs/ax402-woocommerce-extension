<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Server-side HTTP to an allowlisted Ax402 gateway URL (UCP complete only).
 *
 * UCP complete may GET a challenge. It must not send PAYMENT-SIGNATURE
 * (binding §3.1.5 no-impersonation).
 *
 * Do not change {@see Ax402_WC_Gateway_Proxy_Controller} — that streams for humans.
 */
final class Ax402_WC_Ucp_Gateway_Http
{
    public const FORWARD_REQUEST_HEADERS = [
        'payment-signature',
        'payment-signature-data',
        'x-payment',
        'content-type',
        'accept',
    ];

    public const FORWARD_RESPONSE_HEADERS = [
        'payment-required',
        'payment-response',
        'content-type',
    ];

    /** @var callable|null fn(string $url, string $method, array $headers, string $body): array */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public static function host_is_allowed(string $host, array $configured_hosts): bool
    {
        $host = strtolower($host);
        $allowed = [];
        foreach ($configured_hosts as $configured) {
            $configured = strtolower(trim((string) $configured));
            if ($configured !== '') {
                $allowed[] = $configured;
            }
        }
        if (in_array($host, $allowed, true)) {
            return true;
        }

        return str_ends_with($host, '.ax402.io') || str_ends_with($host, '.localhost');
    }

    /**
     * @param list<string> $allowed_paths
     */
    public static function url_is_allowed(
        string $gateway_url,
        array $configured_hosts,
        array $allowed_paths
    ): bool {
        $parts = wp_parse_url($gateway_url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }
        if (!in_array(strtolower((string) $parts['scheme']), ['https', 'http'], true)) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($allowed_paths !== [] && !in_array($path, $allowed_paths, true)) {
            return false;
        }

        return self::host_is_allowed((string) $parts['host'], $configured_hosts);
    }

    /**
     * @return list<string>
     */
    public static function allowed_paths_for_order(WC_Order $order): array
    {
        $paths = [];
        $primary = (string) $order->get_meta(Ax402_WC_Order_Payment::META_PATH);
        if ($primary !== '') {
            $paths[] = $primary;
        }
        foreach (Ax402_WC_Order_Payment::settlement_options_from_order($order) as $option) {
            $path = (string) ($option['path'] ?? '');
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public function request(string $url, string $method, array $headers = [], string $body = ''): array
    {
        if ($this->transport !== null) {
            $result = ($this->transport)($url, strtoupper($method), $headers, $body);
            if (!is_array($result) || !isset($result['status'])) {
                throw new RuntimeException('Invalid gateway transport result');
            }

            return [
                'status' => (int) $result['status'],
                'headers' => isset($result['headers']) && is_array($result['headers'])
                    ? self::normalize_header_map($result['headers'])
                    : [],
                'body' => (string) ($result['body'] ?? ''),
            ];
        }

        $args = [
            'method' => strtoupper($method),
            'timeout' => 60,
            'redirection' => 0,
            'headers' => $headers,
        ];
        if ($body !== '' && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new RuntimeException(esc_html($response->get_error_message()));
        }

        $header_map = [];
        foreach (self::FORWARD_RESPONSE_HEADERS as $name) {
            $value = wp_remote_retrieve_header($response, $name);
            if (is_array($value)) {
                $value = isset($value[0]) ? (string) $value[0] : '';
            }
            if (is_string($value) && $value !== '') {
                $header_map[$name] = $value;
            }
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'headers' => $header_map,
            'body' => (string) wp_remote_retrieve_body($response),
        ];
    }

    public function header_from_request(WP_REST_Request $request, string $name): string
    {
        $value = $request->get_header($name);
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    private static function normalize_header_map(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            if (is_array($value)) {
                $value = isset($value[0]) ? (string) $value[0] : '';
            }
            $out[strtolower((string) $key)] = (string) $value;
        }

        return $out;
    }
}
