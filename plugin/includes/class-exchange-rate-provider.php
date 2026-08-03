<?php
declare(strict_types=1);

defined('ABSPATH') || exit;


/**
 * Converts USD catalog amounts into settlement-token units.
 *
 * rate_usd_to_token() returns how many units of the token equal 1 USD
 * (e.g. "1" for USDC), or null when no rate is available.
 */
interface Ax402_WC_Exchange_Rate_Provider
{
    public function rate_usd_to_token(string $symbol, string $network): ?string;
}
