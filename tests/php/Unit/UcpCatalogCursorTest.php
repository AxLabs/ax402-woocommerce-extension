<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Ucp_Catalog;
use PHPUnit\Framework\TestCase;

final class UcpCatalogCursorTest extends TestCase
{
    public function test_empty_cursor_is_page_one(): void
    {
        $this->assertSame(1, Ax402_WC_Ucp_Catalog::page_from_cursor(''));
    }

    public function test_round_trip_page_token(): void
    {
        $cursor = base64_encode('{"page":3}');
        $this->assertSame(3, Ax402_WC_Ucp_Catalog::page_from_cursor($cursor));
    }

    public function test_garbage_cursor_falls_back(): void
    {
        $this->assertSame(1, Ax402_WC_Ucp_Catalog::page_from_cursor('!!!'));
    }
}
