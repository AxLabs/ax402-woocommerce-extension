<?php
declare(strict_types=1);

defined('ABSPATH') || exit;


/**
 * Pure helpers for fulfill token checks (unit-testable).
 */
final class Ax402_WC_Fulfill_Auth
{
    public static function is_valid_token(string $expected, string $provided): bool
    {
        return $expected !== '' && hash_equals($expected, $provided);
    }

    /**
     * @param list<string> $allowed
     */
    public static function can_fulfill_status(string $status, array $allowed = ['pending', 'on-hold', 'failed']): bool
    {
        return in_array($status, $allowed, true);
    }
}
