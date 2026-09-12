<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Settings_Readiness;
use PHPUnit\Framework\TestCase;

final class SettingsReadinessTest extends TestCase
{
    /** @return array<string, mixed> */
    private function platform(): array
    {
        return [
            'payment_tokens' => [
                [
                    'id' => 'eip155:8453:0xusdc',
                    'symbol' => 'USDC',
                    'network' => 'eip155:8453',
                    'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
                    'enabled' => true,
                ],
                [
                    'id' => 'hedera:mainnet:0.0.456858',
                    'symbol' => 'USDC',
                    'network' => 'hedera:mainnet',
                    'asset' => '0.0.456858',
                    'enabled' => true,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function readyContext(): array
    {
        return [
            'currency' => 'USD',
            'api_key' => 'ax402_live_test',
            'pay_to_address' => '0x1111111111111111111111111111111111111111',
            'pay_to_hedera_account_id' => '',
            'walletconnect_project_id' => '',
            'token_ids' => ['eip155:8453:0xusdc'],
            'platform' => $this->platform(),
        ];
    }

    public function test_ready_ignores_gateway_enabled_flag(): void
    {
        $this->assertTrue(Ax402_WC_Settings_Readiness::is_ready($this->readyContext()));
        $ids = array_column(Ax402_WC_Settings_Readiness::items($this->readyContext()), 'id');
        $this->assertNotContains('hedera_wallet', $ids);
        $this->assertNotContains('walletconnect', $ids);
    }

    public function test_currency_must_be_usd(): void
    {
        $ctx = $this->readyContext();
        $ctx['currency'] = 'EUR';
        $this->assertFalse(Ax402_WC_Settings_Readiness::is_ready($ctx));
        $ids = array_column(Ax402_WC_Settings_Readiness::items($ctx), 'id');
        $this->assertContains('currency', $ids);
    }

    public function test_missing_api_key(): void
    {
        $ctx = $this->readyContext();
        $ctx['api_key'] = '';
        $this->assertFalse(Ax402_WC_Settings_Readiness::is_ready($ctx));
    }

    public function test_missing_evm_wallet(): void
    {
        $ctx = $this->readyContext();
        $ctx['pay_to_address'] = '';
        $this->assertFalse(Ax402_WC_Settings_Readiness::is_ready($ctx));
    }

    public function test_empty_platform_requires_evm_wallet(): void
    {
        $ctx = $this->readyContext();
        $ctx['platform'] = [];
        $ctx['pay_to_address'] = '';
        $this->assertFalse(Ax402_WC_Settings_Readiness::is_ready($ctx));
        $ids = array_column(Ax402_WC_Settings_Readiness::items($ctx), 'id');
        $this->assertContains('evm_wallet', $ids);
    }

    public function test_hedera_token_requires_account_and_walletconnect(): void
    {
        $ctx = $this->readyContext();
        $ctx['token_ids'] = ['hedera:mainnet:0.0.456858'];
        $ctx['pay_to_address'] = '';
        $this->assertFalse(Ax402_WC_Settings_Readiness::is_ready($ctx));

        $ctx['pay_to_hedera_account_id'] = '0.0.12345';
        $this->assertFalse(Ax402_WC_Settings_Readiness::is_ready($ctx));

        $ctx['walletconnect_project_id'] = 'abc';
        $this->assertTrue(Ax402_WC_Settings_Readiness::is_ready($ctx));
        $ids = array_column(Ax402_WC_Settings_Readiness::items($ctx), 'id');
        $this->assertContains('hedera_wallet', $ids);
        $this->assertContains('walletconnect', $ids);
        $this->assertNotContains('evm_wallet', $ids);
    }
}
