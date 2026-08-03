<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Checkout Blocks payment method registration.
 */
final class Ax402_WC_Blocks_Support extends AbstractPaymentMethodType
{
    protected $name = Ax402_WC_Gateway_Ax402::GATEWAY_ID;

    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_ax402_settings', []);
    }

    public function is_active(): bool
    {
        $gateway = new Ax402_WC_Gateway_Ax402();
        return $gateway->is_available();
    }

    /**
     * @return string[]
     */
    public function get_payment_method_script_handles(): array
    {
        $asset_file = AX402_WC_PLUGIN_DIR . 'build/blocks.asset.php';
        $asset = is_readable($asset_file)
            ? require $asset_file
            : ['dependencies' => [], 'version' => AX402_WC_VERSION];

        // WooCommerce packages are webpack externals and are often omitted from *.asset.php.
        $dependencies = array_values(array_unique(array_merge(
            is_array($asset['dependencies'] ?? null) ? $asset['dependencies'] : [],
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'react',
            ]
        )));

        wp_register_script(
            'ax402-wc-blocks',
            AX402_WC_PLUGIN_URL . 'build/blocks.js',
            $dependencies,
            (string) ($asset['version'] ?? AX402_WC_VERSION),
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('ax402-wc-blocks', 'ax402-for-woocommerce');
        }

        return ['ax402-wc-blocks'];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_payment_method_data(): array
    {
        $gateway = new Ax402_WC_Gateway_Ax402();
        return [
            'title' => $gateway->title,
            'description' => $gateway->description,
            'supports' => array_filter($gateway->supports, [$gateway, 'supports']),
            'icon' => $gateway->icon,
        ];
    }
}
