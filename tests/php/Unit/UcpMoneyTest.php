<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Ucp_Money;
use PHPUnit\Framework\TestCase;

final class UcpMoneyTest extends TestCase
{
    public function test_exact_cents(): void
    {
        $this->assertSame(1, Ax402_WC_Ucp_Money::usd_to_cents_exact('0.01'));
        $this->assertSame(10, Ax402_WC_Ucp_Money::usd_to_cents_exact('0.10'));
        $this->assertSame(10, Ax402_WC_Ucp_Money::usd_to_cents_exact('0.1'));
        $this->assertSame(25, Ax402_WC_Ucp_Money::usd_to_cents_exact('0.25'));
        $this->assertSame(100, Ax402_WC_Ucp_Money::usd_to_cents_exact('1'));
        $this->assertSame(13550, Ax402_WC_Ucp_Money::usd_to_cents_exact('135.50'));
        $this->assertSame(10, Ax402_WC_Ucp_Money::usd_to_cents_exact('0.1000'));
    }

    public function test_sub_cent_omitted(): void
    {
        $this->assertNull(Ax402_WC_Ucp_Money::usd_to_cents_exact('0.001'));
        $this->assertNull(Ax402_WC_Ucp_Money::usd_to_cents_exact('0.0003'));
        $this->assertTrue(Ax402_WC_Ucp_Money::is_sub_cent('0.001'));
        $this->assertFalse(Ax402_WC_Ucp_Money::is_sub_cent('0.01'));
    }

    public function test_ceil_never_undercharges(): void
    {
        $this->assertSame(1, Ax402_WC_Ucp_Money::usd_to_cents_ceil('0.001'));
        $this->assertSame(1, Ax402_WC_Ucp_Money::usd_to_cents_ceil('0.0003'));
        $this->assertSame(10, Ax402_WC_Ucp_Money::usd_to_cents_ceil('0.10'));
    }

    public function test_rejects_zero_and_non_numeric(): void
    {
        $this->assertNull(Ax402_WC_Ucp_Money::usd_to_cents_exact('0'));
        $this->assertNull(Ax402_WC_Ucp_Money::usd_to_cents_exact(''));
        $this->assertNull(Ax402_WC_Ucp_Money::usd_to_cents_exact('nope'));
    }
}
