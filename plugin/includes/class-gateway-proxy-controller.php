<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Same-origin proxy for browser paywall → Ax402 gateway.
 *
 * Prefer direct gateway calls with control-plane CORS. This proxy remains as a
 * fallback for gateways that have not yet redeployed per-API origin support.
 */
final class Ax402_WC_Gateway_Proxy_Controller
{
    private const FORWARD_REQUEST_HEADERS = [
        'payment-signature',
        'payment-signature-data',
        'x-payment',
        'content-type',
        'accept',
    ];

    private const FORWARD_RESPONSE_HEADERS = [
        'payment-required',
        'payment-response',
        'content-type',
    ];

    public function register(): void
    {
        register_rest_route(
            'ax402/v1',
            '/pay-proxy/(?P<order_key>[A-Za-z0-9_-]+)',
            [
                [
                    'methods' => ['GET', 'POST', 'HEAD'],
                    'callback' => [$this, 'proxy'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'order_key' => [
                            'required' => true,
                            'type' => 'string',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Streams the upstream gateway response (status, headers, raw body).
     */
    public function proxy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $order_key = (string) $request['order_key'];
        $order_id = wc_get_order_id_by_order_key($order_key);
        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order instanceof WC_Order) {
            return new WP_Error('ax402_not_found', 'Order not found', ['status' => 404]);
        }

        $gateway_url = (string) $order->get_meta(Ax402_WC_Order_Payment::META_GATEWAY_URL);
        if ($gateway_url === '' || !wp_http_validate_url($gateway_url)) {
            return new WP_Error('ax402_no_gateway', 'Gateway URL missing for order', ['status' => 409]);
        }

        if (!$this->is_allowed_gateway_url($gateway_url, $order)) {
            return new WP_Error('ax402_forbidden_target', 'Gateway URL is not allowed', ['status' => 403]);
        }

        $method = strtoupper($request->get_method());
        $headers = [];
        foreach (self::FORWARD_REQUEST_HEADERS as $name) {
            $value = $request->get_header($name);
            if (is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }

        $args = [
            'method' => $method === 'HEAD' ? 'GET' : $method,
            'timeout' => 60,
            'redirection' => 0,
            'headers' => $headers,
        ];

        $body = $request->get_body();
        if (is_string($body) && $body !== '' && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($gateway_url, $args);
        if (is_wp_error($response)) {
            return new WP_Error('ax402_proxy_failed', $response->get_error_message(), ['status' => 502]);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $response_body = $method === 'HEAD' ? '' : (string) wp_remote_retrieve_body($response);

        // Bypass WP REST JSON encoding so x402 clients see the raw upstream response.
        status_header($status);
        foreach (self::FORWARD_RESPONSE_HEADERS as $name) {
            $value = wp_remote_retrieve_header($response, $name);
            if (is_array($value)) {
                $value = isset($value[0]) ? (string) $value[0] : '';
            }
            if (is_string($value) && $value !== '') {
                header($this->normalize_header_name($name) . ': ' . $value, false);
            }
        }
        header('Access-Control-Expose-Headers: Payment-Required, Payment-Response, Content-Type', false);
        header('Cache-Control: no-store', false);

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw upstream proxy body
        echo $response_body;
        exit;
    }

    private function normalize_header_name(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }

    private function is_allowed_gateway_url(string $gateway_url, WC_Order $order): bool
    {
        $parts = wp_parse_url($gateway_url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }
        if (!in_array(strtolower((string) $parts['scheme']), ['https', 'http'], true)) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');
        $expected_path = (string) $order->get_meta(Ax402_WC_Order_Payment::META_PATH);
        if ($expected_path !== '' && $path !== $expected_path) {
            return false;
        }

        $settings = Ax402_WC_Settings::all();
        $allowed_host = strtolower((string) $settings['gateway_host']);
        $host = strtolower((string) $parts['host']);
        if ($allowed_host !== '' && $host === $allowed_host) {
            return true;
        }

        return str_ends_with($host, '.ax402.io') || str_ends_with($host, '.localhost');
    }
}
