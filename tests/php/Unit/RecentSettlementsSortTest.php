<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Recent_Settlements;
use PHPUnit\Framework\TestCase;

final class RecentSettlementsSortTest extends TestCase
{
    public function test_sort_newest_first_by_created_at(): void
    {
        $rows = [
            ['id' => 'old', 'created_at' => '2026-01-01T00:00:00Z'],
            ['id' => 'new', 'created_at' => '2026-09-01T00:00:00Z'],
        ];
        $sorted = Ax402_WC_Recent_Settlements::sort_newest_first($rows);
        $this->assertSame('new', $sorted[0]['id']);
        $this->assertSame('old', $sorted[1]['id']);
    }

    public function test_millis_timestamps(): void
    {
        $this->assertSame(1700000000, Ax402_WC_Recent_Settlements::row_time(['timestamp' => 1700000000000]));
        $this->assertSame(1700000000, Ax402_WC_Recent_Settlements::row_time(['timestamp' => 1700000000]));
    }

    public function test_take_latest_marks_ellipsis_only_when_more_than_limit(): void
    {
        $five = [
            ['id' => '1'],
            ['id' => '2'],
            ['id' => '3'],
            ['id' => '4'],
            ['id' => '5'],
        ];
        $limited = Ax402_WC_Recent_Settlements::take_latest($five, 5);
        $this->assertCount(5, $limited['rows']);
        $this->assertFalse($limited['has_more']);

        $six = $five;
        $six[] = ['id' => '6'];
        $limited = Ax402_WC_Recent_Settlements::take_latest($six, 5);
        $this->assertCount(5, $limited['rows']);
        $this->assertTrue($limited['has_more']);
        $this->assertSame('1', $limited['rows'][0]['id']);

        $none = Ax402_WC_Recent_Settlements::take_latest([], 5);
        $this->assertSame([], $none['rows']);
        $this->assertFalse($none['has_more']);
    }

    public function test_format_amount_uses_token_decimals_and_comma(): void
    {
        $this->assertSame('USDC · 1,5', Ax402_WC_Recent_Settlements::format_amount('1500000', 'USDC', 6));
        $this->assertSame('USDC · 0,001', Ax402_WC_Recent_Settlements::format_amount('1000', 'USDC', 6));
        $this->assertSame(
            'ZCHF · 0,13',
            Ax402_WC_Recent_Settlements::format_amount('130000000000000000', 'ZCHF', 18)
        );
        $this->assertSame('USDC · 1,5', Ax402_WC_Recent_Settlements::format_amount('1.5', 'USDC', 6));
    }

    public function test_format_when_uses_utc_fallback_without_wordpress(): void
    {
        $this->assertSame('Nov 14, 2023 22:13', Ax402_WC_Recent_Settlements::format_when(1700000000));
        $this->assertSame('UTC', Ax402_WC_Recent_Settlements::timezone_label(1700000000));
        $this->assertSame('—', Ax402_WC_Recent_Settlements::format_when(0));
        $this->assertSame('', Ax402_WC_Recent_Settlements::timezone_label(0));
    }
}
