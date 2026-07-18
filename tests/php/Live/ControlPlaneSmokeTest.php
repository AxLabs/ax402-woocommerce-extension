<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Live;

use Ax402_WC_Control_Plane_Client;
use Ax402_WC_Money;
use Ax402_WC_Platform_Tokens;
use PHPUnit\Framework\TestCase;

/**
 * Hits the public Ax402 control plane. Requires AX402_API_KEY.
 */
final class ControlPlaneSmokeTest extends TestCase
{
    private string $baseUrl;
    private string $apiKey;

    protected function setUp(): void
    {
        $this->apiKey = (string) getenv('AX402_API_KEY');
        $this->baseUrl = (string) (getenv('AX402_BASE_URL') ?: 'https://api.ax402.io');
        if ($this->apiKey === '') {
            $this->markTestSkipped('AX402_API_KEY not set');
        }
    }

    public function test_platform_and_list_apis(): void
    {
        $client = new Ax402_WC_Control_Plane_Client($this->baseUrl, $this->apiKey);
        $platform = $client->get_platform_config();
        $this->assertArrayHasKey('payment_tokens', $platform);
        $this->assertNotEmpty($platform['payment_tokens']);

        $apis = $client->list_apis();
        $this->assertIsArray($apis);
    }

    public function test_create_and_delete_temp_api_endpoint(): void
    {
        $payTo = (string) (getenv('AX402_PAY_TO_ADDRESS') ?: '');
        if ($payTo === '' || !preg_match('/^0x[a-fA-F0-9]{40}$/', $payTo)) {
            $this->markTestSkipped('AX402_PAY_TO_ADDRESS not set');
        }

        $client = new Ax402_WC_Control_Plane_Client($this->baseUrl, $this->apiKey);
        $platform = $client->get_platform_config();
        // Prefer Base mainnet USDC for live CRUD smoke — some accounts reject
        // eip155:845320402 until the sep/dev facilitator is enabled for the seller.
        $network = Ax402_WC_Platform_Tokens::NETWORK_BASE_MAINNET;
        try {
            $token = Ax402_WC_Platform_Tokens::find_usdc_token($platform, $network);
        } catch (Throwable $e) {
            $network = Ax402_WC_Platform_Tokens::NETWORK_SEPOLIA;
            $token = Ax402_WC_Platform_Tokens::find_usdc_token($platform, $network);
        }
        $accept = Ax402_WC_Platform_Tokens::build_accept(
            $token,
            Ax402_WC_Money::usdc_to_atomic('0.01'),
            'exact'
        );

        $slug = 'wc-test-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $api = null;
        try {
            $api = $client->create_api(
                'WC Test ' . $slug,
                $slug,
                'https://example.com',
                $payTo
            );
            $this->assertNotEmpty($api['id']);

            $endpoint = $client->create_endpoint(
                (string) $api['id'],
                'GET',
                '/wp-json/ax402/v1/fulfill/wc_order_test/abcd',
                [$accept],
                'phpunit temp'
            );
            $this->assertNotEmpty($endpoint['id']);

            $client->delete_endpoint((string) $api['id'], (string) $endpoint['id']);
        } finally {
            if (is_array($api) && !empty($api['id'])) {
                $client->delete_api((string) $api['id']);
            }
        }
    }
}
