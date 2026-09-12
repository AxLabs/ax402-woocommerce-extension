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
    public const ACTION_PAYMENT_CHALLENGE = 'org.x402.payment.challenge';
    public const ACTION_PAYMENT_CHALLENGE_ID = 'act_payment_challenge';
    public const PAYMENT_CHALLENGE_INSTRUCTIONS = 'Payment required. POST this session\'s complete URL to receive the x402 v2 challenge; the signed challenge carries the payment resource, accepted assets, and HTTP method. Pay the resource it names, then POST complete again.';

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function ucp(string $capability, array $extra = []): array
    {
        $ucp = [
            'version' => Ax402_WC_Ucp_Profile_Builder::UCP_VERSION,
            'capabilities' => [
                $capability => [Ax402_WC_Ucp_Profile_Builder::capability_ref($capability)],
            ],
        ];
        if ($capability === Ax402_WC_Ucp_Profile_Builder::CAP_CHECKOUT) {
            $ucp['payment_handlers'] = self::session_payment_handlers();
        }

        return array_merge($ucp, $extra);
    }

    /**
     * Shop REST URL to POST for a 402 challenge, then again after paying resource.url.
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
        $content = 'Payment required (org.x402.payment). This checkout is ready; complete without settlement does not place the order. '
            . 'POST ' . $url . ' issues HTTP 402 / PAYMENT-REQUIRED (MCP: PaymentRequired in structuredContent, also nested as payment_required; JSON-RPC stays HTTP 200). '
            . 'Apply the binding derivation rule: if payment_required.resource.url equals this complete URL (Same-URL path), retry this URL with PAYMENT-SIGNATURE (or JSON payment.payment_signature / payment.payment_signature_data, or MCP params._meta["x402/payment"]). '
            . 'If resource.url differs (External-URL path: Ax402 gateway inside the signed challenge), pay resource.url with standard x402 using the HTTP method from extensions.bazaar.info.input.method (typically GET; do not assume POST), then POST this complete URL again with a fresh Idempotency-Key (empty body or the same instrument selection) so the shop can reconcile. The shop will not relay PAYMENT-SIGNATURE to the gateway. Never pay the MCP JSON-RPC URL. '
            . 'payment_required.accepts lists every prepared settlement token for this order on that resource. '
            . 'See payment.instruments[] for display names and an optional preference. '
            . 'Do not pay this challenge with a network or asset that is absent from payment_required.accepts. '
            . 'Discovery x402.assets is merchant capability, not this order\'s quote. '
            . 'After settlement, GET this checkout and GET the order. '
            . 'Binding: ' . self::HANDLER_SPEC;

        return self::message($type, 'payment_required', $content, 'recoverable');
    }

    /**
     * Advisory Action for pending checkout. Text only: no payment coordinates.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function payment_challenge_actions(string $status): array
    {
        if ($status !== Ax402_WC_Ucp_Status::READY) {
            return [];
        }

        return [
            self::ACTION_PAYMENT_CHALLENGE => [[
                'id' => self::ACTION_PAYMENT_CHALLENGE_ID,
                'config' => [
                    'instructions' => self::PAYMENT_CHALLENGE_INSTRUCTIONS,
                ],
            ]],
        ];
    }

    /**
     * @return array{type:string, url:string, title:string}
     */
    public static function payment_complete_link(string $session_id): array
    {
        return [
            'type' => self::LINK_X402_COMPLETE,
            'url' => self::checkout_complete_url($session_id),
            'title' => 'UCP complete (POST; 402 challenge, then pay resource.url)',
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
     * Human checkout URL for UCP continue_url (MUST when status is requires_escalation).
     */
    public static function continue_url(): string
    {
        if (function_exists('wc_get_checkout_url')) {
            $url = wc_get_checkout_url();
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        if (function_exists('wc_get_page_permalink')) {
            $shop = wc_get_page_permalink('shop');
            if (is_string($shop) && $shop !== '') {
                return $shop;
            }
        }
        if (function_exists('home_url')) {
            return home_url('/');
        }

        return '';
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
