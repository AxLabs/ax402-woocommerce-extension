<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Settings;
use PHPUnit\Framework\TestCase;

final class SettingsEnvironmentBaseUrlTest extends TestCase
{
    /** @var string|false */
    private $previousGetenv;

    private mixed $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousGetenv = getenv('AX402_BASE_URL');
        $this->previousEnv = $_ENV['AX402_BASE_URL'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousGetenv === false) {
            putenv('AX402_BASE_URL');
        } else {
            putenv('AX402_BASE_URL=' . $this->previousGetenv);
        }
        if ($this->previousEnv === null) {
            unset($_ENV['AX402_BASE_URL']);
        } else {
            $_ENV['AX402_BASE_URL'] = $this->previousEnv;
        }
    }

    public function test_environment_base_url_reads_putenv(): void
    {
        unset($_ENV['AX402_BASE_URL']);
        putenv('AX402_BASE_URL=https://api.staging.ax402.io');
        $this->assertSame('https://api.staging.ax402.io', Ax402_WC_Settings::environment_base_url());
    }

    public function test_environment_base_url_empty_when_unset(): void
    {
        putenv('AX402_BASE_URL');
        unset($_ENV['AX402_BASE_URL']);
        $this->assertSame('', Ax402_WC_Settings::environment_base_url());
    }
}
