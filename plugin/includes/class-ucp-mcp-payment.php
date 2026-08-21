<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * x402 payloads on UCP MCP (binding B3b + x402 MCP _meta).
 *
 * REST complete keeps the challenge in PAYMENT-REQUIRED. MCP has no usable
 * headers, so the same PaymentRequired object is copied into the tool result
 * as `payment_required` (and result._meta["x402/payment-required"]). The retry
 * carries the PaymentPayload as a structured argument or _meta["x402/payment"];
 * this helper encodes that object to the gateway's PAYMENT-SIGNATURE header.
 */
final class Ax402_WC_Ucp_Mcp_Payment
{
    public const META_PAYMENT = 'x402/payment';
    public const META_PAYMENT_DATA = 'x402/payment-data';
    public const META_PAYMENT_REQUIRED = 'x402/payment-required';
    public const META_PAYMENT_RESPONSE = 'x402/payment-response';

    /**
     * @param array<string, mixed> $params tools/call params (or OpenRPC params)
     * @param array<string, mixed> $arguments tool arguments
     */
    public static function signature_from_call(array $params, array $arguments): string
    {
        foreach (self::signature_candidates($params, $arguments) as $candidate) {
            $encoded = self::encode_header_payload($candidate);
            if ($encoded !== '') {
                return $encoded;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $arguments
     */
    public static function signature_data_from_call(array $params, array $arguments): string
    {
        $payment = self::payment_object($arguments);
        $candidates = [
            self::meta_value($params['_meta'] ?? null, self::META_PAYMENT_DATA),
            self::meta_value($arguments['_meta'] ?? null, self::META_PAYMENT_DATA),
            $arguments['payment_signature_data'] ?? null,
            $payment['payment_signature_data'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $encoded = self::encode_header_payload($candidate);
            if ($encoded !== '') {
                return $encoded;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $body unwrapped checkout (or cart) body
     */
    public static function signature_from_body(array $body): string
    {
        return self::signature_from_call([], $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function signature_data_from_body(array $body): string
    {
        return self::signature_data_from_call([], $body);
    }

    /**
     * Copy a captured signature into checkout.payment so call_parts keeps it.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function inject_signature(
        array $arguments,
        string $signature,
        string $signature_data = ''
    ): array {
        if ($signature === '') {
            return $arguments;
        }

        $has_checkout = isset($arguments['checkout']) && is_array($arguments['checkout']);
        $checkout = $has_checkout ? $arguments['checkout'] : $arguments;
        $payment = is_array($checkout['payment'] ?? null) ? $checkout['payment'] : [];
        if (!isset($payment['payment_signature']) || $payment['payment_signature'] === '') {
            $payment['payment_signature'] = $signature;
        }
        if ($signature_data !== '' && (!isset($payment['payment_signature_data']) || $payment['payment_signature_data'] === '')) {
            $payment['payment_signature_data'] = $signature_data;
        }
        $checkout['payment'] = $payment;
        if ($has_checkout) {
            $arguments['checkout'] = $checkout;

            return $arguments;
        }

        return $checkout;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode_header(string $header): array
    {
        $header = trim($header);
        if ($header === '') {
            return [];
        }
        $padded = strtr($header, '-_', '+/');
        $pad_len = (4 - (strlen($padded) % 4)) % 4;
        if ($pad_len > 0) {
            $padded .= str_repeat('=', $pad_len);
        }
        $raw = base64_decode($padded, true);
        if (!is_string($raw) || $raw === '') {
            return self::decode_json_object($header);
        }
        $json = json_decode($raw, true);

        return is_array($json) ? $json : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode_json_object(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $json = json_decode($raw, true);

        return is_array($json) ? $json : [];
    }

    /**
     * Encode a PaymentRequired / PaymentPayload for an x402 HTTP header.
     */
    public static function encode_header_payload(mixed $value): string
    {
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim === '') {
                return '';
            }
            if (str_starts_with($trim, '{') || str_starts_with($trim, '[')) {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    return self::base64url((string) wp_json_encode($decoded));
                }
            }

            return $trim;
        }
        if (is_array($value) && $value !== []) {
            $json = wp_json_encode($value);

            return is_string($json) && $json !== '' ? self::base64url($json) : '';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data checkout JSON (mutated with payment_required)
     * @param array<string, string> $headers inner HTTP headers (lowercase keys ok)
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public static function attach_to_tool_result(array $data, array $headers): array
    {
        $meta = [];
        $required = self::header_value($headers, 'payment-required');
        $decoded_required = self::decode_header($required);
        if ($decoded_required === [] && isset($data['payment_required']) && is_array($data['payment_required'])) {
            $decoded_required = $data['payment_required'];
        }
        if ($decoded_required !== []) {
            $data['payment_required'] = $decoded_required;
            $meta[self::META_PAYMENT_REQUIRED] = $decoded_required;
        }

        $response = self::header_value($headers, 'payment-response');
        $decoded_response = self::decode_header($response);
        if ($decoded_response !== []) {
            $meta[self::META_PAYMENT_RESPONSE] = $decoded_response;
        }

        return ['data' => $data, 'meta' => $meta];
    }

    /**
     * @param array<string, mixed> $headers
     */
    public static function header_value(array $headers, string $name): string
    {
        $want = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $want) {
                continue;
            }
            if (is_array($value)) {
                return isset($value[0]) ? (string) $value[0] : '';
            }

            return (string) $value;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $arguments
     * @return list<mixed>
     */
    private static function signature_candidates(array $params, array $arguments): array
    {
        $payment = self::payment_object($arguments);
        $instrument = is_array($payment['instruments'][0] ?? null) ? $payment['instruments'][0] : [];

        return [
            self::meta_value($params['_meta'] ?? null, self::META_PAYMENT),
            self::meta_value($arguments['_meta'] ?? null, self::META_PAYMENT),
            $arguments['payment_signature'] ?? null,
            $arguments['x402'] ?? null,
            $payment['payment_signature'] ?? null,
            $payment['x402_payment'] ?? null,
            $payment['x402'] ?? null,
            $instrument['payment_signature'] ?? null,
            $instrument['x402'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private static function payment_object(array $arguments): array
    {
        $checkout = isset($arguments['checkout']) && is_array($arguments['checkout'])
            ? $arguments['checkout']
            : $arguments;
        $payment = $checkout['payment'] ?? [];

        return is_array($payment) ? $payment : [];
    }

    private static function meta_value(mixed $meta, string $key): mixed
    {
        if (!is_array($meta) || !array_key_exists($key, $meta)) {
            return null;
        }

        return $meta[$key];
    }

    private static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
