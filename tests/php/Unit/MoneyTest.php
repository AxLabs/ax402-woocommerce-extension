<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_usdc_to_atomic(): void
    {
        $this->assertSame('22500000', Ax402_WC_Money::usdc_to_atomic('22.50'));
        $this->assertSame('1', Ax402_WC_Money::usdc_to_atomic('0.000001'));
        $this->assertSame('1000000', Ax402_WC_Money::usdc_to_atomic('1'));
    }

    public function test_atomic_to_usdc(): void
    {
        $this->assertSame('22.5', Ax402_WC_Money::atomic_to_usdc('22500000'));
        $this->assertSame('1', Ax402_WC_Money::atomic_to_usdc('1000000'));
    }

    public function test_rejects_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Ax402_WC_Money::usdc_to_atomic('0');
    }

    public function test_normalize_order_total(): void
    {
        $this->assertSame('22.500000', Ax402_WC_Money::normalize_order_total(22.5));
        $this->assertSame('10.000000', Ax402_WC_Money::normalize_order_total('10'));
    }

    public function test_usd_to_token_amount_one_to_one(): void
    {
        $amount = Ax402_WC_Money::usd_to_token_amount('22.500000', '1', 6);
        $this->assertSame('22.500000', $amount);
        $this->assertSame('22500000', Ax402_WC_Money::to_atomic($amount, 6));
    }
}
