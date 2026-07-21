<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Control_Plane_Client;
use Ax402_WC_Gateway_Cors;
use PHPUnit\Framework\TestCase;

final class GatewayCorsTest extends TestCase
{
    public function test_normalize_origin_strips_path(): void
    {
        $this->assertSame(
            'https://shop.example.com',
            Ax402_WC_Gateway_Cors::normalize_origin('https://shop.example.com/checkout?x=1')
        );
        $this->assertSame(
            'http://localhost:8888',
            Ax402_WC_Gateway_Cors::normalize_origin('http://localhost:8888/wp-admin/')
        );
        $this->assertSame(
            'http://127.0.0.1:3000',
            Ax402_WC_Gateway_Cors::normalize_origin('http://127.0.0.1:3000')
        );
    }

    public function test_normalize_origin_rejects_invalid(): void
    {
        $this->assertNull(Ax402_WC_Gateway_Cors::normalize_origin('ftp://shop.example.com'));
        $this->assertNull(Ax402_WC_Gateway_Cors::normalize_origin('https://user:pass@shop.example.com'));
        $this->assertNull(Ax402_WC_Gateway_Cors::normalize_origin('not-a-url'));
        $this->assertNull(Ax402_WC_Gateway_Cors::normalize_origin('http://hostname-without-tld'));
    }

    public function test_client_cors_methods(): void
    {
        $calls = [];
        $client = new Ax402_WC_Control_Plane_Client(
            'https://api.staging.ax402.io',
            'key',
            static function (string $method, string $url, ?array $body) use (&$calls): array {
                $calls[] = [$method, $url, $body];
                if ($method === 'GET' && str_ends_with($url, '/cors')) {
                    return [
                        'status' => 200,
                        'body' => json_encode(['origins' => ['https://a.example']], JSON_THROW_ON_ERROR),
                    ];
                }
                if ($method === 'POST' && str_ends_with($url, '/cors')) {
                    return [
                        'status' => 200,
                        'body' => json_encode([
                            'origins' => ['https://a.example', (string) ($body['origin'] ?? '')],
                        ], JSON_THROW_ON_ERROR),
                    ];
                }
                if ($method === 'PUT' && str_ends_with($url, '/cors')) {
                    return [
                        'status' => 200,
                        'body' => json_encode(['origins' => $body['origins'] ?? []], JSON_THROW_ON_ERROR),
                    ];
                }
                return ['status' => 500, 'body' => '', 'error' => 'unexpected'];
            }
        );

        $this->assertSame(['https://a.example'], $client->get_cors_origins('api1'));
        $this->assertSame(
            ['https://a.example', 'http://localhost:8888'],
            $client->add_cors_origin('api1', 'http://localhost:8888')
        );
        $this->assertSame(
            ['https://shop.example.com'],
            $client->put_cors_origins('api1', ['https://shop.example.com'])
        );
        $this->assertSame('POST', $calls[1][0]);
        $this->assertSame(['origin' => 'http://localhost:8888'], $calls[1][2]);
    }
}
