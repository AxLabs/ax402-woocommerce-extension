<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Ucp_Profile_Builder;
use Ax402_WC_Ucp_Leak;
use PHPUnit\Framework\TestCase;

final class UcpProfileBuilderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $platform;

    /** @var array<string, mixed> */
    private array $schema;

    protected function setUp(): void
    {
        $this->platform = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/platform-config.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->schema = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/ucp/handler.schema.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    public function test_official_services_object_not_url_array(): void
    {
        $profile = $this->profile();
        $services = $profile['ucp']['services']['dev.ucp.shopping'][0];
        $this->assertIsArray($profile['ucp']['services']);
        $this->assertSame('rest', $services['transport']);
        $this->assertSame('https://shop.example/wp-json/ucp/v1', $services['endpoint']);
        $this->assertSame(Ax402_WC_Ucp_Profile_Builder::UCP_VERSION, $profile['ucp']['version']);
        $this->assertSame('2026-08-25', $profile['ucp']['version']);
        $mcp = $profile['ucp']['services']['dev.ucp.shopping'][1];
        $this->assertSame('mcp', $mcp['transport']);
        $this->assertSame('https://shop.example/wp-json/ucp/v1/mcp', $mcp['endpoint']);
        $this->assertStringContainsString('2026-08-25', (string) $services['schema']);
        $this->assertStringContainsString('2026-08-25', (string) $mcp['schema']);
        $this->assertArrayHasKey('dev.ucp.shopping.cart', $profile['ucp']['capabilities']);
        $this->assertArrayHasKey('dev.ucp.shopping.catalog.lookup', $profile['ucp']['capabilities']);
        $this->assertArrayHasKey('dev.ucp.shopping.order', $profile['ucp']['capabilities']);
        $this->assertArrayNotHasKey('map_order', $profile['ucp']);
        $this->assertArrayNotHasKey('supported_versions', $profile['ucp']);
        $this->assertIsArray($profile['ucp']['payment_handlers']['org.x402.payment']);
        $search = $profile['ucp']['capabilities']['dev.ucp.shopping.catalog.search'][0];
        $this->assertStringContainsString('/specification/shopping/catalog', (string) $search['spec']);
        $checkout = $profile['ucp']['capabilities']['dev.ucp.shopping.checkout'][0];
        $this->assertStringContainsString('/specification/shopping/checkout', (string) $checkout['spec']);
        $fulfillment = $profile['ucp']['capabilities']['dev.ucp.shopping.fulfillment'][0];
        $this->assertSame('dev.ucp.shopping.checkout', $fulfillment['extends']);
        $this->assertStringContainsString('/specification/shopping/extensions/fulfillment', (string) $fulfillment['spec']);
        $this->assertSame(Ax402_WC_Ucp_Profile_Builder::HANDLER_SCHEMA, $profile['ucp']['payment_handlers']['org.x402.payment'][0]['schema']);
        $this->assertSame('2026-08-25', $profile['ucp']['payment_handlers']['org.x402.payment'][0]['version']);
    }

    public function test_handler_matches_binding_schema_rules(): void
    {
        $handler = $this->profile()['ucp']['payment_handlers']['org.x402.payment'][0];
        $this->assertSame('org.x402.payment', $handler['id']);
        $this->assertMatchesRegularExpression(
            '/' . $this->schema['properties']['version']['pattern'] . '/',
            $handler['version']
        );
        $this->assertSame($this->schema['properties']['id']['const'], $handler['id']);
        foreach (['id', 'version', 'spec', 'schema', 'x402'] as $required) {
            $this->assertArrayHasKey($required, $handler);
        }

        $x402 = $handler['x402'];
        $this->assertNotEmpty($x402['networks']);
        $this->assertNotEmpty($x402['assets']);
        $this->assertSame(600, $x402['quote_window']);
        $this->assertSame(['exact'], $x402['schemes']);
        $this->assertSame('1000000', $x402['max_amount']);
        $caip = $this->schema['properties']['x402']['properties']['networks']['items']['pattern'];
        foreach ($x402['networks'] as $network) {
            $this->assertMatchesRegularExpression('/' . $caip . '/', $network);
        }
        foreach ($x402['assets'] as $asset) {
            $this->assertContains($asset['network'], $x402['networks']);
            $this->assertArrayHasKey('decimals', $asset);
        }
        $this->assertArrayHasKey('eip155', $x402['network_schemas']);
        $this->assertArrayNotHasKey('hedera', $x402['network_schemas']);
    }

    public function test_no_facilitator_leakage(): void
    {
        $settings = $this->settings();
        $profile = Ax402_WC_Ucp_Profile_Builder::build(
            $settings,
            $this->platform,
            'https://shop.example/wp-json/ucp/v1'
        );
        $this->assertSame([], Ax402_WC_Ucp_Leak::scan($profile, Ax402_WC_Ucp_Leak::needles($settings)));
        $json = (string) json_encode($profile);
        $this->assertStringNotContainsString('shop.dev.x.ax402.io', $json);
        $this->assertStringNotContainsString('api_should_not_leak', $json);
        $this->assertStringNotContainsString('gatewayUrl', $json);
        $this->assertStringNotContainsString('payment_url', $json);
    }

    public function test_empty_tokens_omits_handler(): void
    {
        $profile = Ax402_WC_Ucp_Profile_Builder::build(
            ['enabled_token_ids' => []],
            $this->platform,
            'https://shop.example/wp-json/ucp/v1'
        );
        $this->assertSame([], $profile['ucp']['payment_handlers']);
    }

    /**
     * @return array{ucp: array<string, mixed>}
     */
    private function profile(): array
    {
        return Ax402_WC_Ucp_Profile_Builder::build(
            $this->settings(),
            $this->platform,
            'https://shop.example/wp-json/ucp/v1'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'enabled_token_ids' => [
                'eip155:845320402:0x036cbd53842c5426634e7929541ec2318f3dcf7e',
                'eip155:845320402:0xfde4c96c8593536e31f229ea8f37b2ada2699bb2',
            ],
            'ucp_max_amount' => '1000000',
            'gateway_host' => 'shop.dev.x.ax402.io',
            'api_id' => 'api_should_not_leak',
            'api_key' => 'ax402_live_secret',
        ];
    }
}
