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
        $this->assertSame('stablecoin-1to1', $options[0]['rate_source']);
        $this->assertNotSame('', $options[0]['captured_at']);
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

    public function test_hedera_helpers_and_accept_defaults(): void
    {
        $this->assertTrue(Ax402_WC_Platform_Tokens::is_hedera_network('hedera:mainnet'));
        $this->assertTrue(Ax402_WC_Platform_Tokens::is_hedera_network('hedera:testnet'));
        $this->assertFalse(Ax402_WC_Platform_Tokens::is_hedera_network('eip155:8453'));
        $this->assertSame('', Ax402_WC_Platform_Tokens::chain_id_hex('hedera:mainnet'));
        $this->assertTrue(Ax402_WC_Platform_Tokens::is_hedera_native_asset('0.0.0'));

        $hederaToken = [
            'id' => 'hedera:mainnet:0.0.456',
            'symbol' => 'USDC',
            'network' => 'hedera:mainnet',
            'asset' => '0.0.456',
            'decimals' => 6,
            'schemes' => ['exact'],
            'enabled' => true,
        ];
        $accept = Ax402_WC_Platform_Tokens::build_accept($hederaToken, '1000000', 'exact', [
            'kinds' => [
                [
                    'scheme' => 'exact',
                    'network' => 'hedera:mainnet',
                    'extra' => ['feePayer' => '0.0.999'],
                ],
            ],
            'signers' => ['hedera:*' => ['0.0.999']],
        ]);
        $this->assertSame('hedera:mainnet', $accept['network']);
        $this->assertArrayNotHasKey('assetTransferMethod', $accept['extra']);
        $this->assertSame('0.0.999', $accept['extra']['feePayer']);

        $this->assertSame(
            '0.0.888',
            Ax402_WC_Platform_Tokens::hedera_fee_payer('hedera:mainnet', [], [
                'signers' => ['hedera:*' => ['0.0.888']],
            ])
        );

        $platform = [
            'payment_tokens' => [$hederaToken],
        ];
        $this->assertTrue(
            Ax402_WC_Platform_Tokens::has_hedera_token_enabled($platform, [$hederaToken['id']])
        );
        $this->assertFalse(
            Ax402_WC_Platform_Tokens::has_hedera_token_enabled($this->platform, [
                'eip155:845320402:0x036cbd53842c5426634e7929541ec2318f3dcf7e',
            ])
        );
    }
}
