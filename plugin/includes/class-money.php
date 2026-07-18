<?php
declare(strict_types=1);

/**
 * USDC amount helpers (no WordPress dependency).
 */
final class Ax402_WC_Money
{
    public const USDC_DECIMALS = 6;

    /**
     * Convert a decimal USDC/USD amount string to atomic units.
     *
     * @throws InvalidArgumentException
     */
    public static function usdc_to_atomic(string $amount): string
    {
        $trimmed = trim($amount);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Price is required');
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException('Enter a valid USDC amount (e.g. 0.10)');
        }

        $parts = explode('.', $trimmed, 2);
        $whole = $parts[0];
        $frac = str_pad(substr($parts[1] ?? '', 0, self::USDC_DECIMALS), self::USDC_DECIMALS, '0');
        $atomic = ltrim($whole . $frac, '0');
        if ($atomic === '' || $atomic === '0') {
            throw new InvalidArgumentException('Price must be greater than 0');
        }

        return $atomic;
    }

    /**
     * Convert atomic USDC units to a decimal string.
     */
    public static function atomic_to_usdc(string $atomic): string
    {
        if ($atomic === '' || !preg_match('/^\d+$/', $atomic)) {
            return '0';
        }

        $s = str_pad($atomic, self::USDC_DECIMALS + 1, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($s, 0, -self::USDC_DECIMALS), '0') ?: '0';
        $frac = rtrim(substr($s, -self::USDC_DECIMALS), '0');

        return $frac !== '' ? $whole . '.' . $frac : $whole;
    }

    /**
     * Normalize a WooCommerce order total float/string to a USDC decimal string.
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
}
