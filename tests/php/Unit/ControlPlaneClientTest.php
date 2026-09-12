<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Control_Plane_Client;
use PHPUnit\Framework\TestCase;

final class ControlPlaneClientTest extends TestCase
{
    public function test_upsert_creates_when_missing(): void
    {
        $calls = [];
        $http = static function (string $method, string $url, ?array $body) use (&$calls): array {
            $calls[] = [$method, $url, $body];
            if ($method === 'GET' && str_ends_with($url, '/endpoints')) {
                return ['status' => 200, 'body' => '[]'];
            }
            if ($method === 'POST' && str_ends_with($url, '/endpoints')) {
                return [
                    'status' => 201,
                    'body' => json_encode([
                        'id' => 'ep1',
                        'method' => 'GET',
                        'path_pattern' => $body['path_pattern'] ?? '',
                    ], JSON_THROW_ON_ERROR),
                ];
            }
            return ['status' => 500, 'body' => '', 'error' => 'unexpected'];
        };

        $client = new Ax402_WC_Control_Plane_Client('https://api.test', 'key', $http);
        $auth = [
            'type' => 'header',
            'header' => 'ngrok-skip-browser-warning',
            'value' => '1',
        ];
        $result = $client->upsert_endpoint(
            'api1',
            'GET',
            '/wp-json/ax402/v1/fulfill/k/t',
            [['scheme' => 'exact', 'network' => 'eip155:845320402', 'asset' => '0x1', 'amount' => '1000000']],
            'order',
            $auth,
            null,
            300
        );

        $this->assertSame('ep1', $result['id']);
        $this->assertSame('POST', $calls[0][0]);
        $this->assertSame('/wp-json/ax402/v1/fulfill/k/t', $calls[0][2]['path_pattern']);
        $this->assertSame($auth, $calls[0][2]['upstream_auth']);
        $this->assertSame(300, $calls[0][2]['ttl']);
    }

    public function test_upsert_updates_when_exists(): void
    {
        $calls = [];
        $http = static function (string $method, string $url, ?array $body) use (&$calls): array {
            $calls[] = [$method, $url, $body];
            if ($method === 'PUT' && str_contains($url, '/endpoints/ep9')) {
                return [
                    'status' => 200,
                    'body' => json_encode(['id' => 'ep9', 'enabled' => true], JSON_THROW_ON_ERROR),
                ];
            }
            return ['status' => 500, 'body' => '', 'error' => 'unexpected ' . $method . ' ' . $url];
        };

        $client = new Ax402_WC_Control_Plane_Client('https://api.test', 'key', $http);
        $auth = [
            'type' => 'header',
            'header' => 'ngrok-skip-browser-warning',
            'value' => '1',
        ];
        $result = $client->upsert_endpoint(
            'api1',
            'GET',
            '/wp-json/ax402/v1/fulfill/k/t',
            [['scheme' => 'exact', 'network' => 'eip155:845320402', 'asset' => '0x1', 'amount' => '2000000']],
            null,
            $auth,
            'ep9'
        );

        $this->assertSame('ep9', $result['id']);
        $this->assertSame('PUT', $calls[0][0]);
        $this->assertSame($auth, $calls[0][2]['upstream_auth']);
        $this->assertArrayNotHasKey('ttl', $calls[0][2]);
    }

    public function test_upstream_auth_for_ngrok_base_url(): void
    {
        $this->assertSame(
            [
                'type' => 'header',
                'header' => 'ngrok-skip-browser-warning',
                'value' => '1',
            ],
            Ax402_WC_Control_Plane_Client::upstream_auth_for_base_url(
                'https://example.ngrok-free.dev'
            )
        );
        $this->assertNull(
            Ax402_WC_Control_Plane_Client::upstream_auth_for_base_url('https://shop.example.com')
        );
    }

    public function test_find_endpoint_by_path(): void
    {
        $found = Ax402_WC_Control_Plane_Client::find_endpoint_by_path(
            [
                ['id' => 'a', 'method' => 'GET', 'path_pattern' => '/a'],
                ['id' => 'b', 'method' => 'post', 'path_pattern' => '/b'],
            ],
            'POST',
            '/b'
        );
        $this->assertSame('b', $found['id']);
    }

    public function test_create_api_sends_pay_to_addresses(): void
    {
        $calls = [];
        $http = static function (string $method, string $url, ?array $body) use (&$calls): array {
            $calls[] = [$method, $url, $body];
            if ($method === 'POST' && str_ends_with($url, '/apis')) {
                return [
                    'status' => 201,
                    'body' => json_encode(['id' => 'api1', 'slug' => $body['slug'] ?? ''], JSON_THROW_ON_ERROR),
                ];
            }
            return ['status' => 500, 'body' => '', 'error' => 'unexpected'];
        };

        $client = new Ax402_WC_Control_Plane_Client('https://api.test', 'key', $http);
        $client->create_api(
            'Store',
            'wc-test',
            'https://shop.example',
            '0xabc',
            ['eip155:8453:usdc', 'hedera:mainnet:usdc'],
            false,
            ['hedera:mainnet' => '0.0.1']
        );

        $this->assertSame('user_wallet', $calls[0][2]['pay_to_mode']);
        $this->assertSame('0xabc', $calls[0][2]['pay_to_address']);
        $this->assertSame(['hedera:mainnet' => '0.0.1'], $calls[0][2]['pay_to_addresses']);
        $this->assertSame(
            ['eip155:8453:usdc', 'hedera:mainnet:usdc'],
            $calls[0][2]['accepted_token_ids']
        );
    }
}
