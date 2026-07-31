<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Composite_Exchange_Rates;
use Ax402_WC_Control_Plane_Client;
use Ax402_WC_Control_Plane_Exchange_Rates;
use Ax402_WC_Stablecoin_One_To_One_Rates;
use Ax402_WC_Stub_Market_Rates;
use PHPUnit\Framework\TestCase;

final class ExchangeRatesTest extends TestCase
{
    public function test_stablecoin_one_to_one(): void
    {
        $rates = new Ax402_WC_Stablecoin_One_To_One_Rates();
        $this->assertSame('1', $rates->rate_usd_to_token('USDC', 'eip155:8453'));
        $this->assertSame('1', $rates->rate_usd_to_token('usdt', 'eip155:845320402'));
        $this->assertNull($rates->rate_usd_to_token('xGAS', 'eip155:8453'));
    }

    public function test_stub_market_always_null(): void
    {
        $rates = new Ax402_WC_Stub_Market_Rates();
        $this->assertNull($rates->rate_usd_to_token('xGAS', 'eip155:8453'));
        $this->assertNull($rates->rate_usd_to_token('USDC', 'eip155:8453'));
    }

    public function test_composite_default_without_client_uses_stub(): void
    {
        $rates = Ax402_WC_Composite_Exchange_Rates::default();
        $this->assertSame('1', $rates->rate_usd_to_token('USDC', 'eip155:8453'));
        $this->assertNull($rates->rate_usd_to_token('XGAS', 'eip155:12227332'));
    }

    public function test_tokens_per_usd_from_price(): void
    {
        $this->assertSame('1', Ax402_WC_Control_Plane_Exchange_Rates::tokens_per_usd_from_price('1'));
        $this->assertNull(Ax402_WC_Control_Plane_Exchange_Rates::tokens_per_usd_from_price('0'));
        $tokens = Ax402_WC_Control_Plane_Exchange_Rates::tokens_per_usd_from_price('1.04796887214');
        $this->assertNotNull($tokens);
        $this->assertEqualsWithDelta(1.0 / 1.04796887214, (float) $tokens, 1e-12);
    }

    public function test_control_plane_exchange_rates_indexes_fixture(): void
    {
        $payload = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/exchange-rates.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $map = Ax402_WC_Control_Plane_Exchange_Rates::index_rates($payload);
        $this->assertArrayHasKey('USDC|eip155:84532', $map);
        $this->assertArrayHasKey('XGAS|eip155:12227332', $map);
        $this->assertEqualsWithDelta(1.0 / 0.999890948663, (float) $map['USDC|eip155:84532'], 1e-9);
    }

    public function test_index_rates_handles_empty_payload(): void
    {
        $this->assertSame([], Ax402_WC_Control_Plane_Exchange_Rates::index_rates([
            'quote' => 'usd',
            'rates' => [],
        ]));
    }

    public function test_control_plane_indexes_production_shaped_rates(): void
    {
        $map = Ax402_WC_Control_Plane_Exchange_Rates::index_rates([
            'quote' => 'usd',
            'rates' => [
                [
                    'symbol' => 'ZCHF',
                    'network' => 'eip155:8453',
                    'rate' => '1.2419800351',
                ],
                [
                    'symbol' => 'XGAS',
                    'network' => 'eip155:47763',
                    'rate' => '0.93681244889',
                ],
            ],
        ]);
        $this->assertArrayHasKey('ZCHF|eip155:8453', $map);
        $this->assertArrayHasKey('XGAS|eip155:47763', $map);
        $this->assertEqualsWithDelta(1.0 / 1.2419800351, (float) $map['ZCHF|eip155:8453'], 1e-9);
    }

    public function test_control_plane_client_provider_fetches_via_http(): void
    {
        $payload = (string) file_get_contents(dirname(__DIR__) . '/fixtures/exchange-rates.json');
        $client = new Ax402_WC_Control_Plane_Client(
            'https://api.staging.ax402.io',
            'test-key',
            static function (string $method, string $url, ?array $body) use ($payload): array {
                unset($body);
                \PHPUnit\Framework\Assert::assertSame('GET', $method);
                \PHPUnit\Framework\Assert::assertStringContainsString('/exchange-rates?quote=usd', $url);
                return ['status' => 200, 'body' => $payload];
            }
        );

        $rates = new Ax402_WC_Control_Plane_Exchange_Rates($client);
        $xgas = $rates->rate_usd_to_token('XGAS', 'eip155:12227332');
        $this->assertNotNull($xgas);
        $this->assertEqualsWithDelta(1.0 / 1.04796887214, (float) $xgas, 1e-12);

        // Stables still win via composite when client is wired.
        $composite = Ax402_WC_Composite_Exchange_Rates::default($client);
        $this->assertSame('1', $composite->rate_usd_to_token('USDC', 'eip155:84532'));
        $this->assertNotNull($composite->rate_usd_to_token('XGAS', 'eip155:12227332'));
    }
}
