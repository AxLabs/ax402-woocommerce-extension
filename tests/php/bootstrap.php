<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$plugin = $root . '/plugin';

// Lightweight stubs for classes that optionally touch WP in unit tests.
if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}
if (!defined('AX402_WC_VERSION')) {
    define('AX402_WC_VERSION', '0.2.0');
}
if (!defined('AX402_WC_PLUGIN_DIR')) {
    define('AX402_WC_PLUGIN_DIR', $plugin . '/');
}
if (!defined('AX402_WC_PLUGIN_URL')) {
    define('AX402_WC_PLUGIN_URL', 'http://example.test/wp-content/plugins/ax402-for-woocommerce/');
}
if (!defined('AX402_WC_PLUGIN_FILE')) {
    define('AX402_WC_PLUGIN_FILE', $plugin . '/ax402-for-woocommerce.php');
}

if (!function_exists('wp_json_encode')) {
    /**
     * @param mixed $data
     */
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        unset($depth);

        return json_encode($data, $options);
    }
}

require_once $plugin . '/includes/class-money.php';
require_once $plugin . '/includes/class-platform-tokens.php';
require_once $plugin . '/includes/class-control-plane-client.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Ax402_WC_')) {
        return;
    }
    $relative = strtolower(str_replace('_', '-', substr($class, strlen('Ax402_WC_'))));
    $path = AX402_WC_PLUGIN_DIR . 'includes/class-' . $relative . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});
