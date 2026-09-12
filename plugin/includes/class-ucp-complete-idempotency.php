<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * UCP 2026-08-25 complete idempotency (REST Idempotency-Key / MCP meta key).
 *
 * Same key returns the recorded complete response with no new settlement
 * effects. Distinct complete attempts (challenge vs reconcile) use a fresh key.
 */
final class Ax402_WC_Ucp_Complete_Idempotency
{
    public const META = '_ax402_ucp_complete_idempotency';
    public const MAX_KEYS = 16;
    public const MAX_KEY_LENGTH = 256;

    public static function from_request(WP_REST_Request $request): string
    {
        foreach (['Idempotency-Key', 'idempotency-key', 'idempotency_key'] as $name) {
            $value = self::normalize((string) $request->get_header($name));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $arguments MCP tool arguments
     */
    public static function from_mcp_arguments(array $arguments): string
    {
        $meta = $arguments['meta'] ?? null;
        if (!is_array($meta)) {
            return '';
        }

        return self::normalize((string) ($meta['idempotency-key'] ?? ''));
    }

    public static function normalize(string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > self::MAX_KEY_LENGTH) {
            return '';
        }

        return $key;
    }

    public static function replay(WC_Order $order, string $key): ?WP_REST_Response
    {
        if ($key === '') {
            return null;
        }
        $row = self::get(self::load($order), $key);
        if ($row === null) {
            return null;
        }

        return self::response_from_snapshot($row);
    }

    public static function remember(WC_Order $order, string $key, WP_REST_Response $response): WP_REST_Response
    {
        if ($key === '') {
            return $response;
        }
        $fresh = function_exists('wc_get_order') ? wc_get_order($order->get_id()) : null;
        if ($fresh instanceof WC_Order) {
            $order = $fresh;
        }
        $store = self::put(self::load($order), $key, self::snapshot($response));
        $encoded = wp_json_encode($store);
        if (!is_string($encoded) || $encoded === '') {
            return $response;
        }
        $order->update_meta_data(self::META, $encoded);
        $order->save();

        return $response;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function load(WC_Order $order): array
    {
        $raw = (string) $order->get_meta(self::META, true);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $store = [];
        foreach ($decoded as $key => $row) {
            if (!is_string($key) || $key === '' || !is_array($row)) {
                continue;
            }
            $store[$key] = $row;
        }

        return $store;
    }

    /**
     * @param array<string, array<string, mixed>> $store
     * @return array<string, mixed>|null
     */
    public static function get(array $store, string $key): ?array
    {
        if ($key === '' || !isset($store[$key]) || !is_array($store[$key])) {
            return null;
        }

        return $store[$key];
    }

    /**
     * @param array<string, array<string, mixed>> $store
     * @param array<string, mixed> $snapshot
     * @return array<string, array<string, mixed>>
     */
    public static function put(array $store, string $key, array $snapshot): array
    {
        unset($store[$key]);
        $store[$key] = $snapshot;
        while (count($store) > self::MAX_KEYS) {
            $first = array_key_first($store);
            if (!is_string($first)) {
                break;
            }
            unset($store[$first]);
        }

        return $store;
    }

    /**
     * @return array{status: int, data: mixed, headers: array<string, string>}
     */
    public static function snapshot(WP_REST_Response $response): array
    {
        $headers = [];
        foreach ($response->get_headers() as $name => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $headers[(string) $name] = (string) $value;
        }

        return [
            'status' => (int) $response->get_status(),
            'data' => $response->get_data(),
            'headers' => $headers,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function response_from_snapshot(array $snapshot): WP_REST_Response
    {
        $response = new WP_REST_Response(
            $snapshot['data'] ?? [],
            (int) ($snapshot['status'] ?? 200)
        );
        $headers = $snapshot['headers'] ?? [];
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                $response->header((string) $name, (string) $value, true);
            }
        }

        return $response;
    }
}
