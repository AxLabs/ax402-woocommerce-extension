<?php
declare(strict_types=1);

/**
 * 1:1 USD rates for common stablecoins.
 */
final class Ax402_WC_Stablecoin_One_To_One_Rates implements Ax402_WC_Exchange_Rate_Provider
{
    /** @var list<string> */
    public const STABLECOINS = ['USDC', 'USDT', 'USD', 'DAI', 'USDBC', 'EURC'];

    public function rate_usd_to_token(string $symbol, string $network): ?string
    {
        unset($network);
        $symbol = strtoupper(trim($symbol));
        if (in_array($symbol, self::STABLECOINS, true)) {
            return '1';
        }

        return null;
    }

    public static function is_stablecoin(string $symbol): bool
    {
        return in_array(strtoupper(trim($symbol)), self::STABLECOINS, true);
    }
}
