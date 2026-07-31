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

    public function test_find_matching_settlement(): void
    {
        $settlements = [
            ['endpoint_id' => 'ep-1', 'amount' => '100', 'tx' => '0x1'],
            ['endpoint_id' => 'ep-2', 'amount' => '200', 'tx' => '0x2'],
        ];

        $match = Ax402_WC_Settlement_Reconcile::find_matching_settlement($settlements, 'ep-2', '200');
        $this->assertNotNull($match);
        $this->assertSame('0x2', $match['tx']);

        $this->assertNull(
            Ax402_WC_Settlement_Reconcile::find_matching_settlement($settlements, 'ep-2', '999')
        );
        $this->assertNull(
            Ax402_WC_Settlement_Reconcile::find_matching_settlement($settlements, '', '200')
        );
    }
}
