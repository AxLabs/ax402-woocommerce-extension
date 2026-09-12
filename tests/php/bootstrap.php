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

if (!function_exists('esc_html')) {
    /**
     * @param mixed $text
     */
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_parse_url')) {
    /**
     * @return mixed
     */
    function wp_parse_url($url, $component = -1)
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('wp_strip_all_tags')) {
    /**
     * @param mixed $string
     */
    function wp_strip_all_tags($string, $remove_breaks = false)
    {
        $string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string);
        $string = strip_tags((string) $string);
        if ($remove_breaks) {
            $string = preg_replace('/[\r\n\t ]+/', ' ', $string);
        }

        return trim((string) $string);
    }
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

if (!function_exists('__')) {
    /**
     * @param mixed $text
     */
    function __($text, $domain = null)
    {
        unset($domain);

        return (string) $text;
    }
}

if (!class_exists('WP_REST_Response')) {
    /**
     * Minimal stub for unit tests that snapshot complete responses.
     */
    class WP_REST_Response
    {
        /** @var mixed */
        public $data;

        public int $status;

        /** @var array<string, string> */
        public array $headers = [];

        /**
         * @param mixed $data
         */
        public function __construct($data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        /**
         * @return mixed
         */
        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        /**
         * @return array<string, string>
         */
        public function get_headers(): array
        {
            return $this->headers;
        }

        public function header(string $key, string $value, bool $replace = true): void
        {
            if ($replace || !isset($this->headers[$key])) {
                $this->headers[$key] = $value;
                return;
            }
            $this->headers[$key] .= ', ' . $value;
        }
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
