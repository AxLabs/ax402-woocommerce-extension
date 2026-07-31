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

    public function test_build_settlement_options_skips_unpriced_tokens(): void
    {
        $ids = [
            'eip155:845320402:0x036cbd53842c5426634e7929541ec2318f3dcf7e',
            'eip155:845320402:0xfde4c96c8593536e31f229ea8f37b2ada2699bb2',
            'eip155:8453:xgas-stub',
        ];
        $options = Ax402_WC_Platform_Tokens::build_settlement_options(
            $this->platform,
            $ids,
            '22.500000',
            'exact'
        );

        $this->assertCount(2, $options);
        $this->assertSame('USDC', $options[0]['symbol']);
        $this->assertSame('USDT', $options[1]['symbol']);
        $this->assertSame('22500000', $options[0]['amount_atomic']);
        $this->assertSame('22500000', $options[1]['amount_atomic']);
        $this->assertSame('22500000', $options[0]['accept']['amount']);
    }

    public function test_default_enabled_token_ids_sepolia_usdc(): void
    {
        $ids = Ax402_WC_Platform_Tokens::default_enabled_token_ids($this->platform, 'sepolia');
        $this->assertContains(
            'eip155:845320402:0x036cbd53842c5426634e7929541ec2318f3dcf7e',
            $ids
        );
        $this->assertNotContains(
            'eip155:8453:0x833589fcd6edb6e08f4c7c32d4f71b54bda02913',
            $ids
        );
    }

    public function test_default_enabled_token_ids_mainnet_excludes_sepolia(): void
    {
        $ids = Ax402_WC_Platform_Tokens::default_enabled_token_ids($this->platform, 'mainnet');
        $this->assertContains(
            'eip155:8453:0x833589fcd6edb6e08f4c7c32d4f71b54bda02913',
            $ids
        );
        $this->assertNotContains(
            'eip155:845320402:0x036cbd53842c5426634e7929541ec2318f3dcf7e',
            $ids
        );
    }

    public function test_chain_id_hex(): void
    {
        $this->assertSame('0x2105', Ax402_WC_Platform_Tokens::chain_id_hex('eip155:8453'));
    }

    public function test_network_catalog_from_platform_tokens(): void
    {
        $catalog = \Ax402_WC_Network_Catalog::from_platform($this->platform);
        $this->assertContains('eip155:8453', $catalog->networks());
        $this->assertContains('eip155:845320402', $catalog->networks());
        $this->assertNotSame('', $catalog->label('eip155:8453'));
    }
}
