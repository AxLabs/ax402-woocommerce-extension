<?php
declare(strict_types=1);

/**
 * Decimal / atomic amount helpers (no WordPress dependency).
 */
final class Ax402_WC_Money
{
    public const USDC_DECIMALS = 6;

    /**
     * Convert a decimal amount string to atomic units for the given decimals.
     *
     * @throws InvalidArgumentException
     */
    public static function to_atomic(string $amount, int $decimals = self::USDC_DECIMALS): string
    {
        if ($decimals < 0 || $decimals > 18) {
            throw new InvalidArgumentException('Invalid token decimals');
        }

        $trimmed = trim($amount);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Price is required');
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException('Enter a valid amount (e.g. 0.10)');
        }

        $parts = explode('.', $trimmed, 2);
        $whole = $parts[0];
        $frac = str_pad(substr($parts[1] ?? '', 0, $decimals), $decimals, '0');
        $atomic = ltrim($whole . $frac, '0');
        if ($atomic === '' || $atomic === '0') {
            throw new InvalidArgumentException('Price must be greater than 0');
        }

        return $atomic;
    }

    /**
     * Convert atomic units to a decimal string.
     */
    public static function from_atomic(string $atomic, int $decimals = self::USDC_DECIMALS): string
    {
        if ($decimals < 0 || $decimals > 18) {
            return '0';
        }
        if ($atomic === '' || !preg_match('/^\d+$/', $atomic)) {
            return '0';
        }

        $s = str_pad($atomic, $decimals + 1, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($s, 0, -$decimals), '0') ?: '0';
        $frac = rtrim(substr($s, -$decimals), '0');

        return $frac !== '' ? $whole . '.' . $frac : $whole;
    }

    /**
     * Convert a decimal USDC/USD amount string to atomic units.
     *
     * @throws InvalidArgumentException
     */
    public static function usdc_to_atomic(string $amount): string
    {
        return self::to_atomic($amount, self::USDC_DECIMALS);
    }

    /**
     * Convert atomic USDC units to a decimal string.
     */
    public static function atomic_to_usdc(string $atomic): string
    {
        return self::from_atomic($atomic, self::USDC_DECIMALS);
    }

    /**
     * Normalize a WooCommerce order total float/string to a USD decimal string.
     */
    public static function normalize_order_total(string|float|int $total): string
    {
        if (is_string($total)) {
            $total = trim($total);
        }
        if ($total === '' || !is_numeric($total)) {
            throw new InvalidArgumentException('Order total is not numeric');
        }

        return number_format((float) $total, self::USDC_DECIMALS, '.', '');
    }

    /**
     * Multiply USD amount by a token-per-USD rate (decimal strings).
     *
     * @throws InvalidArgumentException
     */
    public static function usd_to_token_amount(string $usd_amount, string $rate, int $decimals): string
    {
        if (!is_numeric($usd_amount) || !is_numeric($rate)) {
            throw new InvalidArgumentException('USD amount and rate must be numeric');
        }
        if ((float) $rate <= 0) {
            throw new InvalidArgumentException('Rate must be greater than 0');
        }

        $product = (float) $usd_amount * (float) $rate;
        return number_format($product, $decimals, '.', '');
    }
}
