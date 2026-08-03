<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Order_Payment;
use PHPUnit\Framework\TestCase;

final class FulfillPathTest extends TestCase
{
    public function test_fulfill_path_encoding(): void
    {
        $path = Ax402_WC_Order_Payment::fulfill_path('wc_order_abc', 'deadbeef');
        $this->assertSame('/wp-json/ax402/v1/fulfill/wc_order_abc/deadbeef', $path);

        $withSlug = Ax402_WC_Order_Payment::fulfill_path('wc_order_abc', 'deadbeef', 'a1b2c3d4e5f6');
        $this->assertSame(
            '/wp-json/ax402/v1/fulfill/wc_order_abc/deadbeef/a1b2c3d4e5f6',
            $withSlug
        );
        $this->assertSame(12, strlen(Ax402_WC_Order_Payment::token_path_slug('eip155:8453:0xabc')));
    }
}
