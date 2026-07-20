<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Composite_Exchange_Rates;
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

    public function test_composite_prefers_stable_then_stub(): void
    {
        $rates = Ax402_WC_Composite_Exchange_Rates::default();
        $this->assertSame('1', $rates->rate_usd_to_token('USDC', 'eip155:8453'));
        $this->assertNull($rates->rate_usd_to_token('xGAS', 'eip155:8453'));
    }
}
