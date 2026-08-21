<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * UCP catalog/checkout amounts are ISO 4217 integer minor units (USD cents).
 *
 * Human Woo prices may use 4+ decimals. UCP cannot represent $0.001 as a cent
 * without undercharging, so those SKUs are omitted from the UCP catalog.
 */
final class Ax402_WC_Ucp_Money
{
    public const CURRENCY = 'USD';

    /**
     * Convert a USD decimal to cents when it is exactly representable
     * (at most 2 significant fraction digits). Returns null for sub-cent SKUs.
     */
    public static function usd_to_cents_exact(string|float|int $amount): ?int
    {
        $normalized = self::normalize_decimal($amount);
        if ($normalized === null) {
            return null;
        }

        [$whole, $frac] = self::split_decimal($normalized);
        if (strlen($frac) > 2 && ltrim(substr($frac, 2), '0') !== '') {
            return null;
        }

        $frac2 = str_pad(substr($frac, 0, 2), 2, '0');
        $cents = (int) ltrim($whole . $frac2, '0');
        if ($cents <= 0 && ($whole !== '0' || ltrim($frac2, '0') !== '')) {
            return null;
        }

        return $cents > 0 ? $cents : null;
    }

    /**
     * Convert a USD decimal to cents, ceiling any extra fraction so we never
     * undercharge on tax/shipping remainders.
     */
    public static function usd_to_cents_ceil(string|float|int $amount): ?int
    {
        $exact = self::usd_to_cents_exact($amount);
        if ($exact !== null) {
            return $exact;
        }

        $normalized = self::normalize_decimal($amount);
        if ($normalized === null) {
            return null;
        }

        $cents = (int) ceil(((float) $normalized) * 100);
        return $cents > 0 ? $cents : null;
    }

    public static function is_sub_cent(string|float|int $amount): bool
    {
        $normalized = self::normalize_decimal($amount);
        if ($normalized === null) {
            return true;
        }

        return self::usd_to_cents_exact($normalized) === null;
    }

    /**
     * @return string|null Canonical non-negative decimal, or null if unusable.
     */
    public static function normalize_decimal(string|float|int $amount): ?string
    {
        if (is_string($amount)) {
            $amount = trim($amount);
        }
        if ($amount === '' || !is_numeric($amount)) {
            return null;
        }
        if ((float) $amount <= 0) {
            return null;
        }

        $formatted = number_format((float) $amount, 8, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? null : $formatted;
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function split_decimal(string $amount): array
    {
        $parts = explode('.', $amount, 2);
        return [$parts[0], $parts[1] ?? ''];
    }
}
