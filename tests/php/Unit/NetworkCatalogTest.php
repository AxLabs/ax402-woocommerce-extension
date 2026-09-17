<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Chain_Metadata;
use Ax402_WC_Network_Catalog;
use PHPUnit\Framework\TestCase;

final class NetworkCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        Ax402_WC_Chain_Metadata::reset_cache_for_tests();
    }

    public function test_networks_from_supported_payload(): void
    {
        $networks = Ax402_WC_Network_Catalog::networks_from_supported_payload([
            'kinds' => [
                ['scheme' => 'exact', 'network' => 'eip155:8453'],
                ['scheme' => 'exact', 'network' => 'eip155:47763'],
                ['scheme' => 'exact', 'network' => 'eip155:8453'],
            ],
        ]);
        $this->assertSame(['eip155:8453', 'eip155:47763'], $networks);
    }

    public function test_chain_metadata_prefers_token_extra(): void
    {
        $meta = Ax402_WC_Chain_Metadata::for_token([
            'network' => 'eip155:999999',
            'extra' => [
                'chainName' => 'Custom Chain',
                'rpcUrl' => 'https://rpc.example.test',
                'blockExplorerUrl' => 'https://explorer.example.test',
            ],
        ]);
        $this->assertSame('Custom Chain', $meta['label']);
        $this->assertSame('https://rpc.example.test', $meta['rpc_url']);
        $this->assertSame('https://explorer.example.test', $meta['explorer_url']);
    }

    public function test_metadata_from_chain_row_skips_templated_rpc(): void
    {
        $meta = Ax402_WC_Chain_Metadata::metadata_from_chain_row([
            'chainId' => 8453,
            'name' => 'Base',
            'rpc' => [
                'https://mainnet.infura.io/v3/${INFURA_API_KEY}',
                'https://mainnet.base.org',
            ],
            'explorers' => [['url' => 'https://basescan.org/']],
        ], 8453);
        $this->assertSame('Base', $meta['label']);
        $this->assertSame('https://mainnet.base.org', $meta['rpc_url']);
        $this->assertSame('https://basescan.org', $meta['explorer_url']);
    }

    public function test_chain_file_url_uses_github_contents_api(): void
    {
        $url = Ax402_WC_Chain_Metadata::chain_file_url(8453);
        $this->assertSame(
            'https://api.github.com/repos/ethereum-lists/chains/contents/_data/chains/eip155-8453.json',
            $url
        );
        $this->assertStringNotContainsString('chainid.network', $url);
        $this->assertStringNotContainsString('jsdelivr', $url);
        $this->assertStringNotContainsString('githubusercontent', $url);
    }

    public function test_catalog_merges_supported_networks(): void
    {
        $platform = [
            'payment_tokens' => [
                [
                    'id' => 't1',
                    'symbol' => 'USDC',
                    'name' => 'USDC',
                    'network' => 'eip155:8453',
                    'asset' => '0x1',
                    'decimals' => 6,
                    'enabled' => true,
                    'extra' => ['chainName' => 'Base From Token'],
                ],
            ],
        ];
        $catalog = Ax402_WC_Network_Catalog::from_platform($platform, [
            'kinds' => [
                ['network' => 'eip155:8453'],
                ['network' => 'eip155:47763'],
            ],
        ]);
        $this->assertSame('Base From Token', $catalog->label('eip155:8453'));
        $this->assertContains('eip155:47763', $catalog->networks());
    }
}
