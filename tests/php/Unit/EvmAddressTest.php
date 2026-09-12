<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Evm_Address;
use PHPUnit\Framework\TestCase;

final class EvmAddressTest extends TestCase
{
    public function test_lowercase_is_checksummed(): void
    {
        $parsed = Ax402_WC_Evm_Address::parse('0x5aaeb6053f3e94c9b9a09f33669435e7ef1beaed');
        $this->assertTrue($parsed['ok']);
        $this->assertSame('0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', $parsed['checksummed']);
    }

    public function test_valid_eip55_accepted(): void
    {
        $parsed = Ax402_WC_Evm_Address::parse('0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed');
        $this->assertTrue($parsed['ok']);
        $this->assertSame('0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', $parsed['checksummed']);
    }

    public function test_mixed_case_typo_rejected(): void
    {
        $parsed = Ax402_WC_Evm_Address::parse('0x5aaeb6053F3E94C9b9A09f33669435E7Ef1BeAed');
        $this->assertFalse($parsed['ok']);
        $this->assertSame(Ax402_WC_Evm_Address::ERROR_CHECKSUM, $parsed['error']);
    }

    public function test_too_short_rejected(): void
    {
        $parsed = Ax402_WC_Evm_Address::parse('0xabc');
        $this->assertFalse($parsed['ok']);
        $this->assertSame(Ax402_WC_Evm_Address::ERROR_FORMAT, $parsed['error']);
    }

    public function test_empty_ok(): void
    {
        $parsed = Ax402_WC_Evm_Address::parse('');
        $this->assertTrue($parsed['ok']);
        $this->assertSame('', $parsed['checksummed']);
        $this->assertFalse(Ax402_WC_Evm_Address::is_valid(''));
    }
}
