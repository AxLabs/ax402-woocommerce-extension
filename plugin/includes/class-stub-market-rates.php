<?php
declare(strict_types=1);

/**
 * Placeholder market-rate client until a real FX/oracle API exists.
 * Always returns null so non-stable tokens are omitted from checkout.
 */
final class Ax402_WC_Stub_Market_Rates implements Ax402_WC_Exchange_Rate_Provider
{
    public function rate_usd_to_token(string $symbol, string $network): ?string
    {
        unset($symbol, $network);
        return null;
    }
}
