<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Settlement_Reconcile;
use PHPUnit\Framework\TestCase;

final class SettlementReconcileTest extends TestCase
{
    public function test_normalize_list_payload(): void
    {
        $rows = Ax402_WC_Settlement_Reconcile::normalize_settlements_payload([
            ['endpoint_id' => 'a', 'amount' => '1'],
            'skip',
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame('a', $rows[0]['endpoint_id']);
    }

    public function test_normalize_items_payload(): void
    {
        $rows = Ax402_WC_Settlement_Reconcile::normalize_settlements_payload([
            'items' => [
                ['endpoint_id' => 'b', 'amount' => '2'],
            ],
            'total' => 1,
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame('b', $rows[0]['endpoint_id']);
    }

    public function test_find_matching_settlement_by_endpoint(): void
    {
        $settlements = [
            ['endpoint_id' => 'ep-1', 'amount' => '100', 'tx' => '0x1'],
            ['endpoint_id' => 'ep-2', 'amount' => '200', 'tx' => '0x2'],
        ];

        $match = Ax402_WC_Settlement_Reconcile::find_matching_settlement($settlements, 'ep-2', '200');
        $this->assertNotNull($match);
        $this->assertSame('0x2', $match['tx']);

        $this->assertNull(
            Ax402_WC_Settlement_Reconcile::find_matching_settlement($settlements, '', '200')
        );
    }

    public function test_find_matching_settlement_accepts_non_primary_amount(): void
    {
        // Order meta primary atomic is USDC; buyer paid ZCHF on the same endpoint.
        $settlements = [
            [
                'endpoint_id' => 'ep-zchf',
                'amount' => '130000000000000000',
                'asset' => '0xc477AaB50E3b641f27c4814c1906864464Ad70D5',
                'tx' => '0xabc',
            ],
        ];

        $match = Ax402_WC_Settlement_Reconcile::find_matching_settlement(
            $settlements,
            'ep-zchf',
            '130000' // primary USDC atomic — must not block
        );
        $this->assertNotNull($match);
        $this->assertSame('0xabc', $match['tx']);
    }

    public function test_find_matching_settlement_prefers_acceptable_amount(): void
    {
        $settlements = [
            ['endpoint_id' => 'ep-1', 'amount' => '999', 'tx' => '0xold'],
            ['endpoint_id' => 'ep-1', 'amount' => '80759000000000000', 'tx' => '0xnew'],
        ];

        $match = Ax402_WC_Settlement_Reconcile::find_matching_settlement(
            $settlements,
            'ep-1',
            '100300',
            ['100300', '80759000000000000']
        );
        $this->assertNotNull($match);
        $this->assertSame('0xnew', $match['tx']);
    }
}
