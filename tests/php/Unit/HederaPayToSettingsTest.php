<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Settings;
use PHPUnit\Framework\TestCase;

final class HederaPayToSettingsTest extends TestCase
{
    public function test_valid_hedera_account_id(): void
    {
        $this->assertTrue(Ax402_WC_Settings::is_valid_hedera_account_id('0.0.123456'));
        $this->assertTrue(Ax402_WC_Settings::is_valid_hedera_account_id('0.0.1'));
        $this->assertFalse(Ax402_WC_Settings::is_valid_hedera_account_id('0xabc'));
        $this->assertFalse(Ax402_WC_Settings::is_valid_hedera_account_id('not-an-id'));
        $this->assertFalse(Ax402_WC_Settings::is_valid_hedera_account_id(''));
    }
}
