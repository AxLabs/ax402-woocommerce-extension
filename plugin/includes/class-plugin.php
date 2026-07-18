<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Main plugin orchestrator.
 */
final class Ax402_WC_Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->hooks();
    }

    private function hooks(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'register_gateway']);
        add_action('rest_api_init', [$this, 'register_rest']);
        // USDC micropayments need more than WooCommerce's default 2 decimals.
        add_filter('wc_get_price_decimals', [$this, 'price_decimals']);
        add_filter('formatted_woocommerce_price', [$this, 'format_price'], 10, 5);

        // woocommerce_blocks_loaded may already have fired by plugins_loaded:20.
        if (did_action('woocommerce_blocks_loaded')) {
            $this->register_blocks();
        } else {
            add_action('woocommerce_blocks_loaded', [$this, 'register_blocks']);
        }

        (new Ax402_WC_Pay_Page())->register();
        (new Ax402_WC_Facade())->register();
        (new Ax402_WC_Endpoint_Cleanup())->register();
    }

    /**
     * When Ax402 is enabled, show at least 4 decimal places so sub-cent prices
     * (e.g. $0.001) do not collapse to $0.00 in the shop.
     */
    public function price_decimals(int $decimals): int
    {
        if (!$this->is_gateway_enabled()) {
            return $decimals;
        }

        return max($decimals, 4);
    }

    /**
     * Trim trailing fractional zeros so $0.10 does not display as $0.1000.
     * (WooCommerce's built-in trim only strips all-zero fractions like .00.)
     *
     * @param string|float $formatted Formatted price string from number_format.
     */
    public function format_price(
        $formatted,
        float $price,
        int $decimals,
        string $decimal_separator,
        string $thousand_separator
    ): string {
        unset($price, $thousand_separator);

        if (!$this->is_gateway_enabled() || $decimals <= 0) {
            return (string) $formatted;
        }

        $formatted = (string) $formatted;
        if (!str_contains($formatted, $decimal_separator)) {
            return $formatted;
        }

        [$whole, $fraction] = explode($decimal_separator, $formatted, 2);
        $fraction = rtrim($fraction, '0');
        // Keep classic cent display ($0.10) while still showing sub-cent precision.
        if (strlen($fraction) < 2) {
            $fraction = str_pad($fraction, 2, '0');
        }

        return $whole . $decimal_separator . $fraction;
    }

    private function is_gateway_enabled(): bool
    {
        $settings = get_option('woocommerce_ax402_settings', []);
        return is_array($settings) && (($settings['enabled'] ?? '') === 'yes');
    }

    /**
     * @param array<int, class-string|string> $gateways
     * @return array<int, class-string|string>
     */
    public function register_gateway(array $gateways): array
    {
        $gateways[] = Ax402_WC_Gateway_Ax402::class;
        return $gateways;
    }

    public function register_rest(): void
    {
        (new Ax402_WC_Fulfill_Controller())->register();
        (new Ax402_WC_Agent_Rest_Controller())->register();
        (new Ax402_WC_Gateway_Proxy_Controller())->register();
    }

    public function register_blocks(): void
    {
        if (!class_exists(Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
            return;
        }

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            static function ($registry): void {
                $registry->register(new Ax402_WC_Blocks_Support());
            }
        );
    }

    public function __clone(): void
    {
        wc_doing_it_wrong(__FUNCTION__, 'Cloning is forbidden.', AX402_WC_VERSION);
    }

    public function __wakeup(): void
    {
        wc_doing_it_wrong(__FUNCTION__, 'Unserializing is forbidden.', AX402_WC_VERSION);
    }
}
