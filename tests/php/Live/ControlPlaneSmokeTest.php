<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Live;

use Ax402_WC_Control_Plane_Client;
use Ax402_WC_Money;
use Ax402_WC_Platform_Tokens;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Hits the public Ax402 control plane. Requires AX402_API_KEY.
 */
final class ControlPlaneSmokeTest extends TestCase
{
    private string $baseUrl;
    private string $apiKey;

    protected function setUp(): void
    {
        $this->apiKey = (string) getenv('AX402_API_KEY');
        $this->baseUrl = (string) (getenv('AX402_BASE_URL') ?: 'https://api.ax402.io');
        if ($this->apiKey === '') {
            $this->markTestSkipped('AX402_API_KEY not set');
        }
    }

    public function test_platform_and_list_apis(): void
    {
        $client = $this->client();
        $platform = $client->get_platform_config();
        $this->assertArrayHasKey('payment_tokens', $platform);
        $this->assertNotEmpty($platform['payment_tokens']);

        $apis = $client->list_apis();
        $this->assertIsArray($apis);
    }

    public function test_create_and_delete_temp_api_endpoint(): void
    {
        $payTo = (string) (getenv('AX402_PAY_TO_ADDRESS') ?: '');
        if ($payTo === '' || !preg_match('/^0x[a-fA-F0-9]{40}$/', $payTo)) {
            $this->markTestSkipped('AX402_PAY_TO_ADDRESS not set');
        }

        $client = $this->client();
        $platform = $client->get_platform_config();
        // Prefer Base mainnet USDC for live CRUD smoke — some accounts reject
        // eip155:845320402 until the sep/dev facilitator is enabled for the seller.
        $network = Ax402_WC_Platform_Tokens::NETWORK_BASE_MAINNET;
        try {
            $token = Ax402_WC_Platform_Tokens::find_usdc_token($platform, $network);
        } catch (Throwable $e) {
            $network = Ax402_WC_Platform_Tokens::NETWORK_SEPOLIA;
            $token = Ax402_WC_Platform_Tokens::find_usdc_token($platform, $network);
        }
        $accept = Ax402_WC_Platform_Tokens::build_accept(
            $token,
            Ax402_WC_Money::usdc_to_atomic('0.01'),
            'exact'
        );

        $slug = 'wc-test-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $api = null;
        try {
            $api = $client->create_api(
                'WC Test ' . $slug,
                $slug,
                'https://example.com',
                $payTo
            );
            $this->assertNotEmpty($api['id']);

            $endpoint = $client->create_endpoint(
                (string) $api['id'],
                'GET',
                '/wp-json/ax402/v1/fulfill/wc_order_test/abcd',
                [$accept],
                'phpunit temp',
                null,
                300
            );
            $this->assertNotEmpty($endpoint['id']);

            $client->delete_endpoint((string) $api['id'], (string) $endpoint['id']);
        } finally {
            if (is_array($api) && !empty($api['id'])) {
                $client->delete_api((string) $api['id']);
            }
        }
    }

    public function test_mixed_family_api_and_multi_accept_amounts(): void
    {
        $payTo = (string) (getenv('AX402_PAY_TO_ADDRESS') ?: '');
        if ($payTo === '' || !preg_match('/^0x[a-fA-F0-9]{40}$/', $payTo)) {
            $this->markTestSkipped('AX402_PAY_TO_ADDRESS not set');
        }

        $client = $this->client();
        $platform = $client->get_platform_config();
        $usdc = $this->find_token($platform, 'USDC', 'eip155:8453');
        $zchf = $this->find_token($platform, 'ZCHF', 'eip155:8453');
        if ($usdc === null || $zchf === null) {
            $this->markTestSkipped('Platform is missing Base USDC or ZCHF');
        }

        $hederaPayTo = trim((string) (getenv('AX402_PAY_TO_HEDERA_ACCOUNT_ID') ?: ''));
        $husdc = $this->find_token($platform, 'USDC', 'hedera:mainnet');
        $accepted = [(string) $usdc['id'], (string) $zchf['id']];
        $payToAddresses = [];
        if ($hederaPayTo !== '' && preg_match('/^\d+\.\d+\.\d+$/', $hederaPayTo) === 1 && $husdc !== null) {
            $accepted[] = (string) $husdc['id'];
            $payToAddresses['hedera:mainnet'] = $hederaPayTo;
        }

        $slug = 'wc-live-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $api = null;
        try {
            $api = $client->create_api(
                'WC live ' . $slug,
                $slug,
                'https://example.com',
                $payTo,
                $accepted,
                false,
                $payToAddresses !== [] ? $payToAddresses : null
            );
            $this->assertNotEmpty($api['id']);
            if ($payToAddresses !== []) {
                $this->assertSame($payToAddresses, $api['pay_to_addresses'] ?? []);
            }

            $usdcAmount = '100000';
            $zchfAmount = '80517000000000000';
            $path = '/repro/multi-accept-' . bin2hex(random_bytes(3));
            $endpoint = $client->create_endpoint(
                (string) $api['id'],
                'GET',
                $path,
                [
                    Ax402_WC_Platform_Tokens::build_accept($usdc, $usdcAmount, 'exact'),
                    Ax402_WC_Platform_Tokens::build_accept($zchf, $zchfAmount, 'exact'),
                ],
                'phpunit multi-accept',
                null,
                300
            );
            $this->assertNotEmpty($endpoint['id']);

            $storedZchf = null;
            foreach ($endpoint['accepts'] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (strcasecmp((string) ($row['asset'] ?? ''), (string) $zchf['asset']) === 0) {
                    $storedZchf = (string) ($row['amount'] ?? '');
                }
            }
            $this->assertSame($zchfAmount, $storedZchf);

            $host = $this->primary_host($api, $slug);
            $this->assertNotSame('', $host);
            $required = $this->payment_required('https://' . $host . $path);
            $this->assertIsArray($required);
            $gatewayZchf = null;
            foreach ($required['accepts'] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (strcasecmp((string) ($row['asset'] ?? ''), (string) $zchf['asset']) === 0) {
                    $gatewayZchf = (string) ($row['amount'] ?? '');
                }
            }
            $this->assertSame($zchfAmount, $gatewayZchf, 'Gateway 402 must preserve ZCHF atomics when USDC is co-listed');
        } finally {
            if (is_array($api) && !empty($api['id'])) {
                $client->delete_api((string) $api['id']);
            }
        }
    }

    private function client(): Ax402_WC_Control_Plane_Client
    {
        return new Ax402_WC_Control_Plane_Client($this->baseUrl, $this->apiKey, $this->transport());
    }

    /**
     * @return callable(string,string,array<string,mixed>|null):array{status:int,body:string,error?:string}
     */
    private function transport(): callable
    {
        $apiKey = $this->apiKey;

        return static function (string $method, string $url, ?array $body) use ($apiKey): array {
            $requestHeaders = [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-API-Key: ' . $apiKey,
            ];
            $http = [
                'method' => $method,
                'header' => implode("\r\n", $requestHeaders),
                'ignore_errors' => true,
                'timeout' => 60,
            ];
            if ($body !== null) {
                $http['content'] = json_encode($body);
            }
            $raw = @file_get_contents($url, false, stream_context_create(['http' => $http]));
            $legacy = null;
            if (!function_exists('http_get_last_response_headers')) {
                $legacy = $http_response_header ?? [];
            }
            $responseHeaders = self::http_response_headers($legacy);
            $status = 0;
            if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\b/', $responseHeaders[0], $match) === 1) {
                $status = (int) $match[1];
            }
            if ($raw === false && $status === 0) {
                return ['status' => 0, 'body' => '', 'error' => 'HTTP request failed'];
            }

            return [
                'status' => $status,
                'body' => is_string($raw) ? $raw : '',
            ];
        };
    }

    /**
     * @param array<string, mixed> $platform
     * @return array<string, mixed>|null
     */
    private function find_token(array $platform, string $symbol, string $network): ?array
    {
        foreach (Ax402_WC_Platform_Tokens::enabled_tokens($platform) as $token) {
            if (
                strtoupper((string) ($token['symbol'] ?? '')) === strtoupper($symbol)
                && (string) ($token['network'] ?? '') === $network
            ) {
                return $token;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $api
     */
    private function primary_host(array $api, string $slug): string
    {
        $domains = is_array($api['domains'] ?? null) ? $api['domains'] : [];
        foreach ($domains as $domain) {
            if (is_array($domain) && !empty($domain['is_primary']) && !empty($domain['hostname'])) {
                return (string) $domain['hostname'];
            }
        }
        foreach ($domains as $domain) {
            if (is_array($domain) && !empty($domain['hostname'])) {
                return (string) $domain['hostname'];
            }
        }

        return $slug . '.x.ax402.io';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payment_required(string $url): ?array
    {
        $http = [
            'method' => 'GET',
            'header' => "Accept: application/json\r\n",
            'ignore_errors' => true,
            'timeout' => 30,
        ];
        @file_get_contents($url, false, stream_context_create(['http' => $http]));
        $legacy = null;
        if (!function_exists('http_get_last_response_headers')) {
            $legacy = $http_response_header ?? [];
        }
        $headers = self::http_response_headers($legacy);
        if ($headers === []) {
            return null;
        }
        $encoded = '';
        foreach ($headers as $line) {
            if (preg_match('/^payment-required:\s*(\S+)/i', $line, $match) === 1) {
                $encoded = $match[1];
                break;
            }
        }
        if ($encoded === '') {
            return null;
        }
        $pad = str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $json = base64_decode(strtr($encoded . $pad, '-_', '+/'), true);
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param list<string>|null $legacyHeaders
     * @return list<string>
     */
    private static function http_response_headers(?array $legacyHeaders = null): array
    {
        if (function_exists('http_get_last_response_headers')) {
            $headers = http_get_last_response_headers();
            return is_array($headers) ? $headers : [];
        }

        return is_array($legacyHeaders) ? array_values($legacyHeaders) : [];
    }
}
