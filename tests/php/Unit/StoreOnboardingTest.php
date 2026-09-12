<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Store_Onboarding;
use PHPUnit\Framework\TestCase;

final class StoreOnboardingTest extends TestCase
{
    /** @return array<string, mixed> */
    private function platform(): array
    {
        return [
            'payment_tokens' => [
                [
                    'id' => 'eip155:8453:0xusdc',
                    'symbol' => 'USDC',
                    'name' => 'USD Coin',
                    'network' => 'eip155:8453',
                    'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
                    'decimals' => 6,
                    'schemes' => ['exact'],
                    'enabled' => true,
                ],
                [
                    'id' => 'hedera:mainnet:0.0.456858',
                    'symbol' => 'USDC',
                    'name' => 'USD Coin',
                    'network' => 'hedera:mainnet',
                    'asset' => '0.0.456858',
                    'decimals' => 6,
                    'schemes' => ['exact'],
                    'enabled' => true,
                ],
            ],
        ];
    }

    public function test_mixed_tokens_use_pay_to_addresses(): void
    {
        $config = Ax402_WC_Store_Onboarding::recipient_config(
            $this->platform(),
            ['eip155:8453:0xusdc', 'hedera:mainnet:0.0.456858'],
            '0x1111111111111111111111111111111111111111',
            '0.0.12345'
        );

        $this->assertTrue($config['has_evm']);
        $this->assertTrue($config['has_hedera']);
        $this->assertSame('0x1111111111111111111111111111111111111111', $config['pay_to_address']);
        $this->assertSame(
            ['hedera:mainnet' => '0.0.12345'],
            $config['pay_to_addresses']
        );
        $this->assertCount(2, $config['accepted_token_ids']);
    }

    public function test_evm_only_has_empty_pay_to_map(): void
    {
        $config = Ax402_WC_Store_Onboarding::recipient_config(
            $this->platform(),
            ['eip155:8453:0xusdc'],
            '0xabc',
            '0.0.1'
        );

        $this->assertTrue($config['has_evm']);
        $this->assertFalse($config['has_hedera']);
        $this->assertSame('0xabc', $config['pay_to_address']);
        $this->assertSame([], $config['pay_to_addresses']);
    }

    public function test_hedera_only_uses_account_as_pay_to_address(): void
    {
        $config = Ax402_WC_Store_Onboarding::recipient_config(
            $this->platform(),
            ['hedera:mainnet:0.0.456858'],
            '0xabc',
            '0.0.12345'
        );

        $this->assertFalse($config['has_evm']);
        $this->assertTrue($config['has_hedera']);
        $this->assertSame('0.0.12345', $config['pay_to_address']);
        $this->assertSame(
            ['hedera:mainnet' => '0.0.12345'],
            $config['pay_to_addresses']
        );
    }
}
