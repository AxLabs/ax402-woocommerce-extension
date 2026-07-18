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
        $result = $client->upsert_endpoint(
            'api1',
            'GET',
            '/wp-json/ax402/v1/fulfill/k/t',
            [['scheme' => 'exact', 'network' => 'eip155:845320402', 'asset' => '0x1', 'amount' => '1000000']],
            'order'
        );

        $this->assertSame('ep1', $result['id']);
        $this->assertSame('POST', $calls[1][0]);
        $this->assertSame('/wp-json/ax402/v1/fulfill/k/t', $calls[1][2]['path_pattern']);
    }

    public function test_upsert_updates_when_exists(): void
    {
        $http = static function (string $method, string $url, ?array $body): array {
            if ($method === 'GET' && str_ends_with($url, '/endpoints')) {
                return [
                    'status' => 200,
                    'body' => json_encode([
                        [
                            'id' => 'ep9',
                            'method' => 'GET',
                            'path_pattern' => '/wp-json/ax402/v1/fulfill/k/t',
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            }
            if ($method === 'PUT' && str_contains($url, '/endpoints/ep9')) {
                return [
                    'status' => 200,
                    'body' => json_encode(['id' => 'ep9', 'enabled' => true], JSON_THROW_ON_ERROR),
                ];
            }
            return ['status' => 500, 'body' => '', 'error' => 'unexpected ' . $method . ' ' . $url];
        };

        $client = new Ax402_WC_Control_Plane_Client('https://api.test', 'key', $http);
        $result = $client->upsert_endpoint(
            'api1',
            'GET',
            '/wp-json/ax402/v1/fulfill/k/t',
            [['scheme' => 'exact', 'network' => 'eip155:845320402', 'asset' => '0x1', 'amount' => '2000000']]
        );

        $this->assertSame('ep9', $result['id']);
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
}
