<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Keccak;
use PHPUnit\Framework\TestCase;

final class KeccakTest extends TestCase
{
    public function test_keccak256_empty_string(): void
    {
        $this->assertSame(
            'c5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470',
            Ax402_WC_Keccak::hash256_hex('')
        );
    }

    public function test_keccak256_abc(): void
    {
        $this->assertSame(
            '4e03657aea45a94fc7d47ba826c8d667c0d1e6e33a64a036ec44f58fa12d6c45',
            Ax402_WC_Keccak::hash256_hex('abc')
        );
    }
}
