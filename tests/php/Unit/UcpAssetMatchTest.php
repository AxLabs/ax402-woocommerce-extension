<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Ucp_Asset_Match;
use PHPUnit\Framework\TestCase;

final class UcpAssetMatchTest extends TestCase
{
    /** @return list<array<string, string>> */
    private function options(): array
    {
        return [
            [
                'tokenId' => 'eip155:8453:usdc',
                'network' => 'eip155:8453',
                'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
                'symbol' => 'USDC',
            ],
            [
                'tokenId' => 'eip155:8453:xgas',
                'network' => 'eip155:8453',
                'asset' => '0x1111111111111111111111111111111111111111',
                'symbol' => 'xGAS',
            ],
        ];
    }

    public function test_defaults_to_primary(): void
    {
        $matched = Ax402_WC_Ucp_Asset_Match::match($this->options(), []);
        $this->assertSame('USDC', $matched['symbol'] ?? null);
    }

    public function test_matches_network_and_asset(): void
    {
        $matched = Ax402_WC_Ucp_Asset_Match::match($this->options(), [
            'network' => 'eip155:8453',
            'asset' => '0x1111111111111111111111111111111111111111',
        ]);
        $this->assertSame('xGAS', $matched['symbol'] ?? null);
    }

    public function test_unknown_pair_is_null(): void
    {
        $this->assertNull(Ax402_WC_Ucp_Asset_Match::match($this->options(), [
            'network' => 'eip155:1',
            'asset' => '0xdead',
        ]));
    }

    public function test_token_id_wins(): void
    {
        $matched = Ax402_WC_Ucp_Asset_Match::match($this->options(), [
            'token_id' => 'eip155:8453:xgas',
            'network' => 'eip155:8453',
            'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
        ]);
        $this->assertSame('xGAS', $matched['symbol'] ?? null);
    }

    public function test_available_pairs(): void
    {
        $pairs = Ax402_WC_Ucp_Asset_Match::available_pairs($this->options());
        $this->assertCount(2, $pairs);
        $this->assertSame('eip155:8453', $pairs[0]['network']);
    }
}
