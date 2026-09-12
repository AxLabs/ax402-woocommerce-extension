<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Ucp_Complete_Idempotency;
use Ax402_WC_Ucp_Mcp;
use PHPUnit\Framework\TestCase;
use WP_REST_Response;

final class UcpCompleteIdempotencyTest extends TestCase
{
    public function test_mcp_arguments_require_meta_key(): void
    {
        $this->assertSame('', Ax402_WC_Ucp_Complete_Idempotency::from_mcp_arguments([
            'id' => 'wc_order_1',
            'checkout' => [],
        ]));
        $this->assertSame('', Ax402_WC_Ucp_Complete_Idempotency::from_mcp_arguments([
            'meta' => ['ucp-agent' => ['profile' => 'https://a.example']],
        ]));
        $this->assertSame('k-1', Ax402_WC_Ucp_Complete_Idempotency::from_mcp_arguments([
            'meta' => ['idempotency-key' => 'k-1'],
        ]));
    }

    public function test_normalize_rejects_empty_and_oversized_keys(): void
    {
        $this->assertSame('', Ax402_WC_Ucp_Complete_Idempotency::normalize('  '));
        $this->assertSame('abc', Ax402_WC_Ucp_Complete_Idempotency::normalize(' abc '));
        $this->assertSame('', Ax402_WC_Ucp_Complete_Idempotency::normalize(str_repeat('a', 257)));
    }

    public function test_put_replays_same_key_and_drops_oldest(): void
    {
        $first = ['status' => 402, 'data' => ['status' => 'ready_for_complete'], 'headers' => ['PAYMENT-REQUIRED' => 'pr']];
        $store = Ax402_WC_Ucp_Complete_Idempotency::put([], 'a', $first);
        $this->assertSame($first, Ax402_WC_Ucp_Complete_Idempotency::get($store, 'a'));

        for ($i = 0; $i < Ax402_WC_Ucp_Complete_Idempotency::MAX_KEYS; $i++) {
            $store = Ax402_WC_Ucp_Complete_Idempotency::put($store, 'k' . $i, [
                'status' => 200,
                'data' => ['i' => $i],
                'headers' => [],
            ]);
        }
        $this->assertNull(Ax402_WC_Ucp_Complete_Idempotency::get($store, 'a'));
        $this->assertCount(Ax402_WC_Ucp_Complete_Idempotency::MAX_KEYS, $store);
        $this->assertNotNull(Ax402_WC_Ucp_Complete_Idempotency::get($store, 'k15'));
    }

    public function test_snapshot_round_trip_preserves_402_header(): void
    {
        $response = new WP_REST_Response(['status' => 'ready_for_complete'], 402);
        $response->header('PAYMENT-REQUIRED', 'encoded-challenge');
        $response->header('Cache-Control', 'no-store');
        $replay = Ax402_WC_Ucp_Complete_Idempotency::response_from_snapshot(
            Ax402_WC_Ucp_Complete_Idempotency::snapshot($response)
        );
        $this->assertSame(402, $replay->get_status());
        $this->assertSame(['status' => 'ready_for_complete'], $replay->get_data());
        $this->assertSame('encoded-challenge', $replay->get_headers()['PAYMENT-REQUIRED']);
    }

    public function test_complete_checkout_tool_requires_idempotency_key(): void
    {
        $tools = Ax402_WC_Ucp_Mcp::tool_descriptors();
        $complete = null;
        foreach ($tools as $tool) {
            if ($tool['name'] === 'complete_checkout') {
                $complete = $tool;
                break;
            }
        }
        $this->assertIsArray($complete);
        $this->assertContains('idempotency-key', $complete['inputSchema']['properties']['meta']['required']);
        $this->assertNotContains('idempotency-key', $complete['inputSchema']['required'] ?? []);
    }
}
