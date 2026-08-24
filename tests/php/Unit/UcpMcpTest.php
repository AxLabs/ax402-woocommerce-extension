<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Ucp_Mcp;
use Ax402_WC_Ucp_Lines;
use PHPUnit\Framework\TestCase;

final class UcpMcpTest extends TestCase
{
    public function test_parse_rejects_non_object(): void
    {
        $parsed = Ax402_WC_Ucp_Mcp::parse_rpc('nope');
        $this->assertSame(-32700, $parsed['error']['code']);
    }

    public function test_parse_accepts_jsonrpc(): void
    {
        $parsed = Ax402_WC_Ucp_Mcp::parse_rpc([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/list',
            'params' => ['arguments' => ['meta' => ['ucp-agent' => ['profile' => 'https://a.example']]]],
        ]);
        $this->assertNull($parsed['error']);
        $this->assertSame(7, $parsed['id']);
        $this->assertSame('tools/list', $parsed['method']);
    }

    public function test_parse_lifts_root_meta_into_params(): void
    {
        $parsed = Ax402_WC_Ucp_Mcp::parse_rpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'complete_checkout',
            'params' => ['id' => 'wc_order_1'],
            '_meta' => ['x402/payment' => ['payload' => ['signature' => '0x1']]],
        ]);
        $this->assertNull($parsed['error']);
        $this->assertSame('complete_checkout', $parsed['method']);
        $this->assertSame('0x1', $parsed['params']['_meta']['x402/payment']['payload']['signature']);
    }

    public function test_initialize_result_echoes_protocol_version(): void
    {
        $result = Ax402_WC_Ucp_Mcp::initialize_result(['protocolVersion' => '2025-06-18']);
        $this->assertSame('2025-06-18', $result['protocolVersion']);
        $this->assertSame('ax402-woocommerce-ucp', $result['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $result['capabilities']);
    }

    public function test_tools_list_covers_shopping_ops(): void
    {
        $names = array_map(
            static fn (array $tool): string => $tool['name'],
            Ax402_WC_Ucp_Mcp::tool_descriptors()
        );
        foreach (Ax402_WC_Ucp_Mcp::TOOLS as $tool) {
            $this->assertContains($tool, $names);
        }
        $this->assertContains('create_cart', $names);
        $this->assertContains('get_order', $names);
        $this->assertContains('create_checkout', $names);
    }

    public function test_call_parts_unwrap_catalog_and_checkout(): void
    {
        $search = Ax402_WC_Ucp_Mcp::call_parts('search_catalog', [
            'meta' => ['ucp-agent' => ['profile' => 'https://a.example']],
            'catalog' => ['query' => 'Micropay', 'pagination' => ['limit' => 5]],
        ]);
        $this->assertSame('Micropay', $search['body']['query']);

        $get = Ax402_WC_Ucp_Mcp::call_parts('get_checkout', [
            'meta' => [],
            'id' => 'wc_order_abc',
        ]);
        $this->assertSame('wc_order_abc', $get['id']);

        $create = Ax402_WC_Ucp_Mcp::call_parts('create_checkout', [
            'checkout' => [
                'line_items' => [['item' => ['id' => '18'], 'quantity' => 1]],
            ],
        ]);
        $this->assertSame('18', $create['body']['line_items'][0]['item']['id']);

        $cart = Ax402_WC_Ucp_Mcp::call_parts('create_cart', [
            'cart' => [
                'line_items' => [['item' => ['id' => '18'], 'quantity' => 2]],
            ],
        ]);
        $this->assertSame('18', $cart['body']['line_items'][0]['item']['id']);

        $fromCart = Ax402_WC_Ucp_Mcp::call_parts('create_checkout', [
            'checkout' => ['cart_id' => 'wc_order_abc'],
        ]);
        $this->assertSame('wc_order_abc', $fromCart['body']['cart_id']);
    }

    public function test_call_parts_peels_cli_double_wrapped_cart(): void
    {
        $parts = Ax402_WC_Ucp_Mcp::call_parts('create_cart', [
            'meta' => ['ucp-agent' => ['profile' => 'https://a.example']],
            'cart' => [
                'cart' => [
                    'line_items' => [['item' => ['id' => '30'], 'quantity' => 1]],
                    'context' => ['address_country' => 'CH'],
                ],
            ],
        ]);
        $this->assertSame('30', $parts['body']['line_items'][0]['item']['id']);
        $this->assertSame('CH', $parts['body']['context']['address_country']);
    }

    public function test_call_parts_peels_cli_double_wrapped_checkout(): void
    {
        $parts = Ax402_WC_Ucp_Mcp::call_parts('create_checkout', [
            'checkout' => [
                'checkout' => [
                    'line_items' => [['item' => ['id' => '18'], 'quantity' => 1]],
                    'fulfillment' => ['methods' => []],
                ],
            ],
        ]);
        $this->assertSame('18', $parts['body']['line_items'][0]['item']['id']);
        $this->assertIsArray($parts['body']['fulfillment']);
    }

    public function test_unwrap_accepts_flat_cli_input_under_one_wrapper(): void
    {
        $body = Ax402_WC_Ucp_Mcp::unwrap_shopping_body([
            'cart' => [
                'line_items' => [['item' => ['id' => '18'], 'quantity' => 1]],
            ],
        ], 'cart');
        $this->assertSame('18', $body['line_items'][0]['item']['id']);
    }

    public function test_update_checkout_schema_documents_postal_fields(): void
    {
        $tools = Ax402_WC_Ucp_Mcp::tool_descriptors();
        $update = null;
        foreach ($tools as $tool) {
            if ($tool['name'] === 'update_checkout') {
                $update = $tool;
                break;
            }
        }
        $this->assertIsArray($update);
        $encoded = (string) json_encode($update);
        $this->assertStringContainsString('street_address', $encoded);
        $this->assertStringContainsString('address_country', $encoded);
        $this->assertStringContainsString('fulfillment', $encoded);
        $this->assertStringContainsString('positional', $update['description']);
        $this->assertStringContainsString('payment.instruments', $update['description']);
    }

    public function test_empty_line_items_rejected_before_order_writes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ax402_WC_Ucp_Lines::resolve([]);
    }
}
