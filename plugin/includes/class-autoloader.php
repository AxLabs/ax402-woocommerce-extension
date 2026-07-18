<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * PSR-4-ish autoloader for Ax402_WC_* classes in includes/.
 */
final class Ax402_WC_Autoloader
{
    public static function register(): void
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    public static function autoload(string $class): void
    {
        if (!str_starts_with($class, 'Ax402_WC_')) {
            return;
        }

        $relative = strtolower(str_replace('_', '-', substr($class, strlen('Ax402_WC_'))));
        $path = AX402_WC_PLUGIN_DIR . 'includes/class-' . $relative . '.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }
}
