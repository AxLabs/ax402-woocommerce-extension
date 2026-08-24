<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Ucp_Fulfillment;
use PHPUnit\Framework\TestCase;

final class UcpFulfillmentAddressTest extends TestCase
{
    public function test_official_postal_fields_win(): void
    {
        $dest = Ax402_WC_Ucp_Fulfillment::coerce_destination([
            'street_address' => 'Bahnhofstrasse 1',
            'address_locality' => 'Zurich',
            'postal_code' => '8001',
            'address_country' => 'ch',
        ]);
        $this->assertSame('Bahnhofstrasse 1', $dest['street_address']);
        $this->assertSame('Zurich', $dest['address_locality']);
        $this->assertSame('8001', $dest['postal_code']);
        $this->assertSame('CH', $dest['address_country']);
    }

    public function test_common_agent_aliases_are_mapped(): void
    {
        $dest = Ax402_WC_Ucp_Fulfillment::coerce_destination([
            'address_line_1' => 'Mystrasse 111',
            'city' => 'Zurich',
            'zip' => '8003',
            'country' => 'CH',
        ]);
        $this->assertSame('Mystrasse 111', $dest['street_address']);
        $this->assertSame('Zurich', $dest['address_locality']);
        $this->assertSame('8003', $dest['postal_code']);
        $this->assertSame('CH', $dest['address_country']);
    }

    public function test_nested_address_object(): void
    {
        $dest = Ax402_WC_Ucp_Fulfillment::coerce_destination([
            'id' => 'home',
            'address' => [
                'address_line1' => '1 Market St',
                'city' => 'San Francisco',
                'state' => 'CA',
                'postal_code' => '94105',
                'country' => 'US',
            ],
        ]);
        $this->assertSame('home', $dest['id']);
        $this->assertSame('1 Market St', $dest['street_address']);
        $this->assertSame('CA', $dest['address_region']);
        $this->assertSame('US', $dest['address_country']);
    }
}
