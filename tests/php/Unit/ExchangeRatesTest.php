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
        $urls = [];
        $client = new Ax402_WC_Control_Plane_Client(
            'https://api.staging.ax402.io',
            'test-key',
            static function (string $method, string $url, ?array $body) use ($payload, &$urls): array {
                unset($body);
                \PHPUnit\Framework\Assert::assertSame('GET', $method);
                $urls[] = $url;
                \PHPUnit\Framework\Assert::assertStringContainsString('/exchange-rates?quote=usd&date=', $url);
                return ['status' => 200, 'body' => $payload];
            }
        );

        $now = new \DateTimeImmutable('2026-08-03T12:00:00Z');
        $rates = new Ax402_WC_Control_Plane_Exchange_Rates($client, false, $now);
        $xgas = $rates->rate_usd_to_token('XGAS', 'eip155:12227332');
        $this->assertNotNull($xgas);
        $this->assertEqualsWithDelta(1.0 / 1.04796887214, (float) $xgas, 1e-12);
        $this->assertCount(1, $urls);
        $this->assertStringContainsString('date=2026-08-03', $urls[0]);
        $this->assertSame('2026-08-03', $rates->last_rate_date());

        // Stables still win via composite when client is wired.
        $composite = Ax402_WC_Composite_Exchange_Rates::default($client);
        $this->assertSame('1', $composite->rate_usd_to_token('USDC', 'eip155:84532'));
        $this->assertNotNull($composite->rate_usd_to_token('XGAS', 'eip155:12227332'));
    }

    public function test_candidate_rate_dates_weekday_and_weekend(): void
    {
        // Monday: today → yesterday (Sun) → Friday.
        $monday = new \DateTimeImmutable('2026-08-03T15:00:00Z');
        $this->assertSame(
            ['2026-08-03', '2026-08-02', '2026-07-31'],
            Ax402_WC_Control_Plane_Exchange_Rates::candidate_rate_dates($monday)
        );

        // Tuesday: today → yesterday (Mon); business-day fallback equals yesterday.
        $tuesday = new \DateTimeImmutable('2026-08-04T15:00:00Z');
        $this->assertSame(
            ['2026-08-04', '2026-08-03'],
            Ax402_WC_Control_Plane_Exchange_Rates::candidate_rate_dates($tuesday)
        );

        // Saturday: today → yesterday (Fri); business-day fallback equals yesterday.
        $saturday = new \DateTimeImmutable('2026-08-01T15:00:00Z');
        $this->assertSame(
            ['2026-08-01', '2026-07-31'],
            Ax402_WC_Control_Plane_Exchange_Rates::candidate_rate_dates($saturday)
        );

        // Sunday: today → yesterday (Sat) → Friday.
        $sunday = new \DateTimeImmutable('2026-08-02T15:00:00Z');
        $this->assertSame(
            ['2026-08-02', '2026-08-01', '2026-07-31'],
            Ax402_WC_Control_Plane_Exchange_Rates::candidate_rate_dates($sunday)
        );
    }

    public function test_falls_back_to_yesterday_when_today_empty(): void
    {
        $empty = json_encode(['quote' => 'usd', 'rate_date' => '2026-08-03', 'rates' => []], JSON_THROW_ON_ERROR);
        $filled = json_encode([
            'quote' => 'usd',
            'rate_date' => '2026-08-02',
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
        ], JSON_THROW_ON_ERROR);

        $urls = [];
        $client = new Ax402_WC_Control_Plane_Client(
            'https://api.ax402.io',
            'test-key',
            static function (string $method, string $url, ?array $body) use ($empty, $filled, &$urls): array {
                unset($method, $body);
                $urls[] = $url;
                if (str_contains($url, 'date=2026-08-04')) {
                    return ['status' => 200, 'body' => $empty];
                }
                if (str_contains($url, 'date=2026-08-03')) {
                    return ['status' => 200, 'body' => $filled];
                }

                return ['status' => 500, 'body' => '{"error":"unexpected date"}'];
            }
        );

        // Tuesday 2026-08-04 → try today, then yesterday (Monday).
        $now = new \DateTimeImmutable('2026-08-04T12:00:00Z');
        $rates = new Ax402_WC_Control_Plane_Exchange_Rates($client, true, $now);
        $this->assertNotNull($rates->rate_usd_to_token('ZCHF', 'eip155:8453'));
        $this->assertNotNull($rates->rate_usd_to_token('XGAS', 'eip155:47763'));
        $this->assertCount(2, $urls);
        $this->assertStringContainsString('date=2026-08-04', $urls[0]);
        $this->assertStringContainsString('date=2026-08-03', $urls[1]);
    }

    public function test_monday_falls_back_to_friday_after_yesterday_empty(): void
    {
        $empty = json_encode(['quote' => 'usd', 'rates' => []], JSON_THROW_ON_ERROR);
        $filled = json_encode([
            'quote' => 'usd',
            'rates' => [
                ['symbol' => 'XGAS', 'network' => 'eip155:47763', 'rate' => '1'],
            ],
        ], JSON_THROW_ON_ERROR);

        $urls = [];
        $client = new Ax402_WC_Control_Plane_Client(
            'https://api.ax402.io',
            'test-key',
            static function (string $method, string $url, ?array $body) use ($empty, $filled, &$urls): array {
                unset($method, $body);
                $urls[] = $url;
                if (str_contains($url, 'date=2026-08-03') || str_contains($url, 'date=2026-08-02')) {
                    return ['status' => 200, 'body' => $empty];
                }
                if (str_contains($url, 'date=2026-07-31')) {
                    return ['status' => 200, 'body' => $filled];
                }

                return ['status' => 500, 'body' => '{}'];
            }
        );

        // Monday: today → Sunday (yesterday) → Friday.
        $now = new \DateTimeImmutable('2026-08-03T12:00:00Z');
        $rates = new Ax402_WC_Control_Plane_Exchange_Rates($client, true, $now);
        $this->assertSame('1', $rates->rate_usd_to_token('XGAS', 'eip155:47763'));
        $this->assertCount(3, $urls);
        $this->assertStringContainsString('date=2026-08-03', $urls[0]);
        $this->assertStringContainsString('date=2026-08-02', $urls[1]);
        $this->assertStringContainsString('date=2026-07-31', $urls[2]);
    }
}
