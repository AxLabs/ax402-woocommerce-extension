<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Platform_Tokens;
use PHPUnit\Framework\TestCase;

final class PlatformTokensTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $platform;

    protected function setUp(): void
    {
        $this->platform = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/platform-config.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    public function test_network_for_mode(): void
    {
        $this->assertSame('eip155:845320402', Ax402_WC_Platform_Tokens::network_for_mode('sepolia'));
        $this->assertSame('eip155:8453', Ax402_WC_Platform_Tokens::network_for_mode('mainnet'));
    }

    public function test_find_usdc_token_sepolia(): void
    {
        $token = Ax402_WC_Platform_Tokens::find_usdc_token(
            $this->platform,
            Ax402_WC_Platform_Tokens::NETWORK_SEPOLIA
        );
        $this->assertSame('USDC', $token['symbol']);
        $this->assertSame('eip155:845320402', $token['network']);
    }

    public function test_build_accept(): void
    {
        $token = Ax402_WC_Platform_Tokens::find_usdc_token(
            $this->platform,
            Ax402_WC_Platform_Tokens::NETWORK_SEPOLIA
        );
        $accept = Ax402_WC_Platform_Tokens::build_accept($token, '22500000', 'exact');
        $this->assertSame('exact', $accept['scheme']);
        $this->assertSame('22500000', $accept['amount']);
        $this->assertSame('eip155:845320402', $accept['network']);
        $this->assertSame('0x036CbD53842c5426634e7929541eC2318f3dCF7e', $accept['asset']);
    }

    public function test_preferred_platform_domain(): void
    {
        $this->assertSame(
            'dev.x.ax402.io',
            Ax402_WC_Platform_Tokens::preferred_platform_domain($this->platform, 'sepolia')
        );
        $this->assertSame(
            'x.ax402.io',
            Ax402_WC_Platform_Tokens::preferred_platform_domain($this->platform, 'mainnet')
        );
    }

    public function test_gateway_url(): void
    {
        $url = Ax402_WC_Platform_Tokens::gateway_url(
            'shop.dev.x.ax402.io',
            '/wp-json/ax402/v1/fulfill/wc_order_x/abc'
        );
        $this->assertSame(
            'https://shop.dev.x.ax402.io/wp-json/ax402/v1/fulfill/wc_order_x/abc',
            $url
        );
    }
}
