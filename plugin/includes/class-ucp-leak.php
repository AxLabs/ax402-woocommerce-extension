<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Detects Ax402 facilitator/gateway identifiers that must not appear in UCP JSON.
 *
 * The min-leak adapter still puts the gateway URL inside the x402
 * PAYMENT-REQUIRED header (signed `resource`). That header is not UCP JSON.
 * MCP copies the same PaymentRequired object into the tool result so clients
 * that cannot read HTTP headers can still sign; do not leak-scan that block.
 */
final class Ax402_WC_Ucp_Leak
{
    /**
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    public static function needles(array $settings = []): array
    {
        $needles = [
            'gatewayUrl',
            'gateway_url',
            'payment_url',
            'endpointId',
            'endpoint_id',
            'fulfill_token',
            'api_key',
            'ax402_live_',
            '/wp-json/ax402/v1/fulfill/',
        ];

        foreach ([
            'gateway_host',
            'hedera_gateway_host',
            'api_id',
            'hedera_api_id',
            'api_key',
            'api_slug',
            'hedera_api_slug',
        ] as $key) {
            $value = trim((string) ($settings[$key] ?? ''));
            if ($value !== '') {
                $needles[] = $value;
            }
        }

        return array_values(array_unique(array_filter($needles, static fn (string $n): bool => $n !== '')));
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @param list<string> $needles
     * @return list<string> needles that appear in the JSON
     */
    public static function scan(array $payload, array $needles): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return [];
        }

        $hits = [];
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($json, $needle)) {
                $hits[] = $needle;
            }
        }

        return $hits;
    }

    /**
     * Strip facilitator URLs from an x402 object before embedding it in UCP JSON.
     * The PAYMENT-RESPONSE header remains verbatim for clients that need it.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function redact_facilitator_urls(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::redact_facilitator_urls($value);
                continue;
            }
            if (!is_string($value) || $value === '') {
                continue;
            }
            $looks_url = str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
            $looks_ax402 = str_contains($value, '.ax402.io')
                || str_contains($value, '/wp-json/ax402/');
            if ($looks_ax402 || ($looks_url && in_array((string) $key, ['resource', 'resourceUrl', 'url'], true))) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @param array<string, mixed> $settings
     */
    public static function assert_clean(array $payload, array $settings = []): void
    {
        $hits = self::scan($payload, self::needles($settings));
        if ($hits !== []) {
            throw new RuntimeException('UCP payload leaked facilitator data: ' . implode(', ', $hits));
        }
    }
}
