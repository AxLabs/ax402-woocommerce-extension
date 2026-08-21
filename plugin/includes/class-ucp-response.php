<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * UCP JSON envelopes and messages (official snake_case).
 */
final class Ax402_WC_Ucp_Response
{
    public const HANDLER_SPEC = 'https://github.com/AxLabs/ucp-x402-binding';
    public const LINK_X402_COMPLETE = 'org.x402.complete';

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function ucp(string $capability, array $extra = []): array
    {
        $ucp = [
            'version' => Ax402_WC_Ucp_Profile_Builder::UCP_VERSION,
            'capabilities' => [
                $capability => [[
                    'version' => Ax402_WC_Ucp_Profile_Builder::UCP_VERSION,
                ]],
            ],
        ];

        return array_merge($ucp, $extra);
    }

    /**
     * Shop REST URL the x402 wallet must POST (HTTP 402, then PAYMENT-SIGNATURE).
     */
    public static function checkout_complete_url(string $session_id): string
    {
        $session_id = trim($session_id);
        if ($session_id === '') {
            return '';
        }
        $path = 'ucp/v1/checkout-sessions/' . rawurlencode($session_id) . '/complete';
        if (function_exists('rest_url')) {
            return rtrim((string) rest_url($path), '/');
        }

        return '/wp-json/' . $path;
    }

    /**
     * Next-step copy for agents (UCP CLI, MCP, REST). Wallet-agnostic.
     *
     * @return array<string, string>
     */
    public static function payment_required_message(string $session_id, string $type = 'error'): array
    {
        $url = self::checkout_complete_url($session_id);
        $content = 'Payment required (org.x402.payment). This checkout is ready; complete without an x402 signature does not place the order. '
            . 'Pay by HTTP POST ' . $url . ' using any x402 wallet (expect HTTP 402 / PAYMENT-REQUIRED, then retry that same URL with PAYMENT-SIGNATURE). '
            . 'On MCP, retry complete_checkout with params._meta["x402/payment"] after signing structuredContent.payment_required; do not treat the MCP JSON-RPC URL as the x402 resource. '
            . 'After settlement, GET this checkout and GET the order. '
            . 'Binding: ' . self::HANDLER_SPEC;

        return self::message($type, 'payment_required', $content, 'recoverable');
    }

    /**
     * @return array{type:string, url:string, title:string}
     */
    public static function payment_complete_link(string $session_id): array
    {
        return [
            'type' => self::LINK_X402_COMPLETE,
            'url' => self::checkout_complete_url($session_id),
            'title' => 'x402 payment URL (HTTP POST; 402 challenge)',
        ];
    }

    /**
     * Runtime payment_handlers on checkout responses: no x402 facilitator block.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function session_payment_handlers(): array
    {
        return [
            'org.x402.payment' => [[
                'id' => 'org.x402.payment',
                'version' => Ax402_WC_Ucp_Profile_Builder::HANDLER_VERSION,
                'available_instruments' => [
                    ['type' => 'x402'],
                ],
            ]],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function message(
        string $type,
        string $code,
        string $content,
        string $severity = 'recoverable',
        string $path = ''
    ): array {
        $message = [
            'type' => $type,
            'code' => $code,
            'content' => $content,
            'severity' => $severity,
        ];
        if ($path !== '') {
            $message['path'] = $path;
        }

        return $message;
    }

    /**
     * @param list<array<string, string>> $messages
     * @param array<string, mixed> $extra
     */
    public static function rest_error(
        int $http_status,
        array $messages,
        string $capability = 'dev.ucp.shopping.checkout',
        array $extra = []
    ): WP_REST_Response {
        $body = array_merge([
            'ucp' => self::ucp($capability, ['status' => 'error']),
            'messages' => $messages,
        ], $extra);

        return new WP_REST_Response($body, $http_status);
    }
}
