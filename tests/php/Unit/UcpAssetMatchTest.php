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

    public function test_id_match_is_case_insensitive(): void
    {
        $matched = Ax402_WC_Ucp_Asset_Match::match($this->options(), [
            'id' => 'EIP155:8453:XGAS',
        ]);
        $this->assertSame('xGAS', $matched['symbol'] ?? null);
    }

    public function test_id_selects_token(): void
    {
        $matched = Ax402_WC_Ucp_Asset_Match::match($this->options(), [
            'id' => 'eip155:8453:xgas',
            'handler_id' => 'org.x402.payment',
            'type' => 'x402',
            'selected' => true,
        ]);
        $this->assertSame('xGAS', $matched['symbol'] ?? null);
    }

    public function test_preferred_instrument_ignores_unselected_list(): void
    {
        $preferred = Ax402_WC_Ucp_Asset_Match::preferred_instrument([
            [
                'id' => 'eip155:8453:usdc',
                'network' => 'eip155:8453',
                'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
                'selected' => false,
            ],
            [
                'id' => 'eip155:8453:xgas',
                'network' => 'eip155:47763',
                'asset' => '0x9a50C8804dC885F118835cD96d3Ea4D4A5131A01',
                'selected' => true,
            ],
        ]);
        $this->assertSame('eip155:8453:xgas', $preferred['id'] ?? null);

        $none = Ax402_WC_Ucp_Asset_Match::preferred_instrument([
            ['id' => 'a', 'network' => 'eip155:8453', 'asset' => '0x1'],
            ['id' => 'b', 'network' => 'eip155:47763', 'asset' => '0x2'],
        ]);
        $this->assertSame([], $none);

        $single = Ax402_WC_Ucp_Asset_Match::preferred_instrument([
            ['id' => 'eip155:47763:xgas', 'network' => 'eip155:47763', 'asset' => '0x9'],
        ]);
        $this->assertSame('eip155:47763:xgas', $single['id'] ?? null);
    }

    public function test_id_and_pair_conflict_is_null(): void
    {
        $this->assertNull(Ax402_WC_Ucp_Asset_Match::match($this->options(), [
            'id' => 'eip155:8453:xgas',
            'network' => 'eip155:8453',
            'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
        ]));
    }

    public function test_available_pairs(): void
    {
        $pairs = Ax402_WC_Ucp_Asset_Match::available_pairs($this->options());
        $this->assertCount(2, $pairs);
        $this->assertSame('eip155:8453', $pairs[0]['network']);
    }

    public function test_checkout_instruments_lists_each_token_without_gateway_url(): void
    {
        $options = [
            [
                'tokenId' => 'tok_usdc',
                'network' => 'eip155:8453',
                'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
                'symbol' => 'USDC',
                'gatewayUrl' => 'https://shop.example.x.ax402.io/secret',
            ],
            [
                'tokenId' => 'tok_xgas',
                'network' => 'eip155:47763',
                'asset' => '0x9a50C8804dC885F118835cD96d3Ea4D4A5131A01',
                'symbol' => 'XGAS',
                'gatewayUrl' => 'https://shop.example.x.ax402.io/other',
            ],
        ];
        $instruments = Ax402_WC_Ucp_Asset_Match::checkout_instruments($options, $options[1]);
        $this->assertCount(2, $instruments);
        $this->assertTrue($instruments[1]['selected']);
        $this->assertFalse($instruments[0]['selected']);
        $this->assertSame('eip155:47763', $instruments[1]['network']);
        $this->assertSame('XGAS', $instruments[1]['display']['name']);
        $json = json_encode($instruments);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('ax402.io', $json);
        $this->assertStringNotContainsString('gatewayUrl', $json);
    }
}
