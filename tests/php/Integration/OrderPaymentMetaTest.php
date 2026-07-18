<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Integration;

use Ax402_WC_Money;
use Ax402_WC_Order_Payment;
use Ax402_WC_Platform_Tokens;
use PHPUnit\Framework\TestCase;

/**
 * Integration-style checks that do not boot WordPress.
 * Full WC order flows are covered via wp-env manual/E2E.
 */
final class OrderPaymentMetaTest extends TestCase
{
    public function test_prepare_inputs_for_twenty_two_fifty_order(): void
    {
        $platform = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/platform-config.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $amount = Ax402_WC_Money::normalize_order_total('22.50');
        $atomic = Ax402_WC_Money::usdc_to_atomic($amount);
        $token = Ax402_WC_Platform_Tokens::find_usdc_token(
            $platform,
            Ax402_WC_Platform_Tokens::NETWORK_SEPOLIA
        );
        $accept = Ax402_WC_Platform_Tokens::build_accept($token, $atomic, 'exact');
        $path = Ax402_WC_Order_Payment::fulfill_path('wc_order_demo', 'aabbccdd');

        $this->assertSame('22.500000', $amount);
        $this->assertSame('22500000', $atomic);
        $this->assertSame('22500000', $accept['amount']);
        $this->assertStringContainsString('/fulfill/wc_order_demo/aabbccdd', $path);
    }
}
