<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * UCP POST …/complete: 402 challenge relay + PAYMENT-SIGNATURE proxy.
 *
 * Min-leak adapter: HTTP 402 is on the shop complete URL; the relayed
 * PaymentRequired.resource stays the Ax402 gateway URL. Agents retry complete,
 * not resource.url. See docs/ucp.md.
 */
final class Ax402_WC_Ucp_Complete
{
    private Ax402_WC_Ucp_Gateway_Http $http;

    private Ax402_WC_Ucp_Checkout $checkout;

    private int $poll_attempts;

    private int $poll_sleep_seconds;

    public function __construct(
        ?Ax402_WC_Ucp_Gateway_Http $http = null,
        ?Ax402_WC_Ucp_Checkout $checkout = null,
        int $poll_attempts = 10,
        int $poll_sleep_seconds = 1
    ) {
        $this->http = $http ?? new Ax402_WC_Ucp_Gateway_Http();
        $this->checkout = $checkout ?? new Ax402_WC_Ucp_Checkout();
        $this->poll_attempts = max(0, $poll_attempts);
        $this->poll_sleep_seconds = max(0, $poll_sleep_seconds);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function handle(string $session_id, WP_REST_Request $request, array $body): WP_REST_Response|WP_Error
    {
        $order = Ax402_WC_Ucp_Checkout::order_from_session($session_id);
        if (!$order instanceof WC_Order) {
            return Ax402_WC_Ucp_Response::rest_error(
                404,
                [Ax402_WC_Ucp_Response::message('error', 'not_found', 'Checkout session not found.', 'unrecoverable')]
            );
        }

        Ax402_WC_Settlement_Reconcile::reconcile_order($order);
        $fresh = wc_get_order($order->get_id());
        if ($fresh instanceof WC_Order) {
            $order = $fresh;
        }

        if ($order->is_paid()) {
            return new WP_REST_Response(Ax402_WC_Ucp_Mapper::session($order), 200);
        }

        $computed = Ax402_WC_Ucp_Mapper::status_for_order($order);
        if ($computed['status'] !== Ax402_WC_Ucp_Status::READY) {
            $session = Ax402_WC_Ucp_Mapper::session($order);
            $session['ucp']['status'] = 'error';
            return new WP_REST_Response($session, 200);
        }

        try {
            $this->checkout->maybe_prepare($order);
            $order = wc_get_order($order->get_id()) ?: $order;
        } catch (Throwable $e) {
            return Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message('error', 'prepare_failed', $e->getMessage(), 'recoverable')]
            );
        }

        $options = Ax402_WC_Order_Payment::settlement_options_from_order($order);
        if ($options === []) {
            return Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message(
                    'error',
                    'payment_method_not_available',
                    'No settlement options are prepared for this session.',
                    'recoverable'
                )]
            );
        }

        $instrument = $this->selected_instrument($body);
        $matched = Ax402_WC_Ucp_Asset_Match::match($options, $instrument);
        $preference_given = $this->has_asset_preference($instrument);
        if ($preference_given && $matched === null) {
            $pairs = Ax402_WC_Ucp_Asset_Match::available_pairs($options);
            return Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message(
                    'error',
                    'payment_method_not_available',
                    'Requested network/asset is not available. Available: ' . wp_json_encode($pairs),
                    'recoverable'
                )]
            );
        }
        $matched ??= $options[0];

        $signature = $this->http->header_from_request($request, 'payment-signature');
        if ($signature === '') {
            $signature = $this->http->header_from_request($request, 'x-payment');
        }
        if ($signature === '') {
            $signature = Ax402_WC_Ucp_Mcp_Payment::signature_from_body($body);
        }

        if ($signature === '') {
            return $this->challenge($order, $matched);
        }

        return $this->submit_payment($order, $matched, $request, $signature, $body);
    }

    /**
     * @param array<string, mixed> $option
     */
    private function challenge(WC_Order $order, array $option): WP_REST_Response
    {
        $url = (string) ($option['gatewayUrl'] ?? '');
        if (!$this->assert_url($order, $url)) {
            return Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message('error', 'invalid', 'Settlement endpoint is not allowed.', 'recoverable')]
            );
        }

        try {
            $upstream = $this->http->request($url, 'GET', ['accept' => 'application/json']);
        } catch (Throwable $e) {
            return Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message('error', 'payment_failed', $e->getMessage(), 'recoverable')]
            );
        }

        $required = $upstream['headers']['payment-required'] ?? '';
        if ($upstream['status'] >= 200 && $upstream['status'] < 300 && $required === '') {
            $this->poll_paid($order);
            $fresh = wc_get_order($order->get_id());
            if ($fresh instanceof WC_Order && $fresh->is_paid()) {
                return new WP_REST_Response(Ax402_WC_Ucp_Mapper::session($fresh), 200);
            }
        }

        $response = new WP_REST_Response([
            'ucp' => Ax402_WC_Ucp_Response::ucp('dev.ucp.shopping.checkout', [
                'status' => 'error',
                'payment_handlers' => Ax402_WC_Ucp_Response::session_payment_handlers(),
            ]),
            'id' => $order->get_order_key(),
            'status' => Ax402_WC_Ucp_Status::READY,
            'messages' => [
                Ax402_WC_Ucp_Response::payment_required_message($order->get_order_key()),
            ],
            'links' => [Ax402_WC_Ucp_Response::payment_complete_link($order->get_order_key())],
        ], $upstream['status'] === 402 ? 402 : max($upstream['status'], 402));

        if ($required !== '') {
            $response->header('PAYMENT-REQUIRED', $required);
        }
        $response->header('Cache-Control', 'no-store');
        $response->header('Access-Control-Expose-Headers', 'PAYMENT-REQUIRED, PAYMENT-RESPONSE');

        return $response;
    }

    /**
     * @param array<string, mixed> $option
     */
    private function submit_payment(
        WC_Order $order,
        array $option,
        WP_REST_Request $request,
        string $signature,
        array $body = []
    ): WP_REST_Response {
        $token_id = (string) ($option['tokenId'] ?? '');
        try {
            Ax402_WC_Order_Payment::lock_settlement_token($order, $token_id);
            $order = wc_get_order($order->get_id()) ?: $order;
        } catch (InvalidArgumentException $e) {
            return Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message('error', 'payment_method_not_available', $e->getMessage(), 'recoverable')]
            );
        } catch (Throwable $e) {
            return Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message('error', 'payment_failed', $e->getMessage(), 'recoverable')]
            );
        }

        $url = (string) $order->get_meta(Ax402_WC_Order_Payment::META_GATEWAY_URL);
        if ($url === '') {
            $url = (string) ($option['gatewayUrl'] ?? '');
        }
        if (!$this->assert_url($order, $url)) {
            return Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message('error', 'invalid', 'Settlement endpoint is not allowed.', 'recoverable')]
            );
        }

        $headers = [
            'payment-signature' => $signature,
            'accept' => 'application/json',
        ];
        $sig_data = $this->http->header_from_request($request, 'payment-signature-data');
        if ($sig_data === '') {
            $sig_data = Ax402_WC_Ucp_Mcp_Payment::signature_data_from_body($body);
        }
        if ($sig_data !== '') {
            $headers['payment-signature-data'] = $sig_data;
        }

        try {
            $upstream = $this->http->request($url, 'POST', $headers, self::upstream_body($request));
        } catch (Throwable $e) {
            return Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message('error', 'payment_failed', $e->getMessage(), 'recoverable')]
            );
        }

        if ($upstream['status'] === 402 || $upstream['status'] >= 400) {
            $reason = $this->upstream_error_content($upstream);
            $response = Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message('error', 'payment_failed', $reason, 'recoverable')],
                'dev.ucp.shopping.checkout',
                [
                    'id' => $order->get_order_key(),
                    'status' => Ax402_WC_Ucp_Status::READY,
                ]
            );
            $required = $upstream['headers']['payment-required'] ?? '';
            if ($required !== '') {
                $response->header('PAYMENT-REQUIRED', $required);
            }

            return $response;
        }

        $receipt = $this->decode_payment_response($upstream['headers']['payment-response'] ?? '');
        $tx = $this->transaction_from_receipt($receipt);
        if ($tx !== '') {
            $order->set_transaction_id($tx);
            $order->save();
        }

        $pending_finality = false;
        $paid = $this->poll_paid($order);
        $order = wc_get_order($order->get_id()) ?: $order;
        if (!$paid) {
            $pending_finality = true;
        }

        $session = Ax402_WC_Ucp_Mapper::session($order);
        if ($pending_finality) {
            $session['status'] = Ax402_WC_Ucp_Status::COMPLETED;
            $session['order'] = [
                'id' => (string) $order->get_id(),
                'permalink_url' => $order->get_checkout_order_received_url(),
            ];
            $session['messages'] = array_merge(
                $session['messages'] ?? [],
                [Ax402_WC_Ucp_Response::message(
                    'info',
                    'pending_finality',
                    'Settlement was accepted; on-chain confirmation is still landing.',
                    'recoverable'
                )]
            );
        }

        $session['payment'] = $this->payment_with_receipt($order, $receipt, $tx);
        Ax402_WC_Ucp_Leak::assert_clean($session, Ax402_WC_Settings::all());

        $response = new WP_REST_Response($session, 200);
        if (($upstream['headers']['payment-response'] ?? '') !== '') {
            $response->header('PAYMENT-RESPONSE', $upstream['headers']['payment-response']);
        }

        return $response;
    }

    private function poll_paid(WC_Order $order): bool
    {
        $attempts = max(1, $this->poll_attempts);
        for ($i = 0; $i < $attempts; $i++) {
            Ax402_WC_Settlement_Reconcile::reconcile_order($order);
            $fresh = wc_get_order($order->get_id());
            if ($fresh instanceof WC_Order && $fresh->is_paid()) {
                return true;
            }
            if ($this->poll_sleep_seconds > 0 && $i < $attempts - 1) {
                sleep($this->poll_sleep_seconds);
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $option
     */
    private function assert_url(WC_Order $order, string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $settings = Ax402_WC_Settings::all();
        return Ax402_WC_Ucp_Gateway_Http::url_is_allowed(
            $url,
            [
                $settings['gateway_host'],
                $settings['hedera_gateway_host'],
            ],
            Ax402_WC_Ucp_Gateway_Http::allowed_paths_for_order($order)
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function selected_instrument(array $body): array
    {
        $payment = is_array($body['payment'] ?? null) ? $body['payment'] : [];
        $instruments = $payment['instruments'] ?? [];
        if (!is_array($instruments)) {
            return [];
        }
        foreach ($instruments as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['selected'])) {
                return $row;
            }
        }

        return is_array($instruments[0] ?? null) ? $instruments[0] : [];
    }

    /**
     * @param array<string, mixed> $instrument
     */
    private function has_asset_preference(array $instrument): bool
    {
        return trim((string) ($instrument['network'] ?? '')) !== ''
            || trim((string) ($instrument['asset'] ?? '')) !== ''
            || trim((string) ($instrument['token_id'] ?? $instrument['tokenId'] ?? '')) !== '';
    }

    /**
     * @param array{status:int, headers:array<string,string>, body:string} $upstream
     */
    private function upstream_error_content(array $upstream): string
    {
        $body = trim($upstream['body']);
        if ($body !== '') {
            $json = json_decode($body, true);
            if (is_array($json)) {
                foreach (['error', 'message', 'detail'] as $key) {
                    if (!empty($json[$key]) && is_string($json[$key])) {
                        return $json[$key];
                    }
                }
            }
            return substr($body, 0, 300);
        }

        return 'Payment was rejected (HTTP ' . $upstream['status'] . ').';
    }

    /**
     * Do not forward MCP JSON-RPC envelopes to the Ax402 gateway.
     */
    private static function upstream_body(WP_REST_Request $request): string
    {
        $raw = (string) $request->get_body();
        $parsed = json_decode($raw, true);
        if (is_array($parsed) && ($parsed['jsonrpc'] ?? '') === '2.0') {
            return '';
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode_payment_response(string $header): array
    {
        return Ax402_WC_Ucp_Mcp_Payment::decode_header($header);
    }

    /**
     * @param array<string, mixed> $receipt
     */
    private function transaction_from_receipt(array $receipt): string
    {
        foreach (['transaction', 'txHash', 'tx_hash', 'hash'] as $key) {
            if (!empty($receipt[$key]) && is_string($receipt[$key])) {
                return $receipt[$key];
            }
        }
        $settle = $receipt['settle'] ?? null;
        if (is_array($settle) && !empty($settle['transaction']) && is_string($settle['transaction'])) {
            return $settle['transaction'];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $receipt
     * @return array{instruments: list<array<string, mixed>>}
     */
    private function payment_with_receipt(WC_Order $order, array $receipt, string $tx): array
    {
        $base = Ax402_WC_Ucp_Mapper::payment_after_settle($order);
        $instrument = $base['instruments'][0];
        if ($tx !== '') {
            $instrument['display']['transaction'] = $tx;
        }
        if ($receipt !== []) {
            $instrument['x402_receipt'] = Ax402_WC_Ucp_Leak::redact_facilitator_urls($receipt);
        }
        $base['instruments'][0] = $instrument;

        return $base;
    }
}
