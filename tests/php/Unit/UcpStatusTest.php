<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Ucp_Status;
use PHPUnit\Framework\TestCase;

final class UcpStatusTest extends TestCase
{
    public function test_virtual_ready(): void
    {
        $r = Ax402_WC_Ucp_Status::compute(false, false, false, false, false, false, true, true);
        $this->assertSame(Ax402_WC_Ucp_Status::READY, $r['status']);
        $this->assertSame([], $r['messages']);
    }

    public function test_shipping_without_address_is_incomplete(): void
    {
        $r = Ax402_WC_Ucp_Status::compute(false, false, true, false, false, false, true, true);
        $this->assertSame(Ax402_WC_Ucp_Status::INCOMPLETE, $r['status']);
        $this->assertSame('missing', $r['messages'][0]['code']);
        $this->assertSame('$.fulfillment.methods[0].destinations', $r['messages'][0]['path']);
    }

    public function test_shipping_with_address_needs_option(): void
    {
        $r = Ax402_WC_Ucp_Status::compute(false, false, true, true, false, true, true, true);
        $this->assertSame(Ax402_WC_Ucp_Status::INCOMPLETE, $r['status']);
        $this->assertSame('missing', $r['messages'][0]['code']);
    }

    public function test_shipping_ready_when_selected(): void
    {
        $r = Ax402_WC_Ucp_Status::compute(false, false, true, true, true, true, true, true);
        $this->assertSame(Ax402_WC_Ucp_Status::READY, $r['status']);
    }

    public function test_paid_and_canceled(): void
    {
        $this->assertSame(
            Ax402_WC_Ucp_Status::COMPLETED,
            Ax402_WC_Ucp_Status::compute(true, false, true, false, false, false, true, true)['status']
        );
        $this->assertSame(
            Ax402_WC_Ucp_Status::CANCELED,
            Ax402_WC_Ucp_Status::compute(false, true, false, false, false, false, true, true)['status']
        );
    }

    public function test_zero_total_incomplete(): void
    {
        $r = Ax402_WC_Ucp_Status::compute(false, false, false, false, false, false, false, true);
        $this->assertSame(Ax402_WC_Ucp_Status::INCOMPLETE, $r['status']);
    }

    public function test_no_shipping_rates_requires_escalation(): void
    {
        $r = Ax402_WC_Ucp_Status::compute(false, false, true, true, false, false, true, true);
        $this->assertSame(Ax402_WC_Ucp_Status::REQUIRES_ESCALATION, $r['status']);
        $this->assertSame('invalid', $r['messages'][0]['code']);
        $this->assertSame('requires_buyer_input', $r['messages'][0]['severity']);
        $this->assertNotSame('requires_escalation', $r['messages'][0]['code']);
    }
}
