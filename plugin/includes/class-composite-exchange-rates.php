<?php
declare(strict_types=1);

/**
 * Tries providers in order; first non-null rate wins.
 */
final class Ax402_WC_Composite_Exchange_Rates implements Ax402_WC_Exchange_Rate_Provider
{
    /** @var list<Ax402_WC_Exchange_Rate_Provider> */
    private array $providers;

    /**
     * @param list<Ax402_WC_Exchange_Rate_Provider> $providers
     */
    public function __construct(array $providers)
    {
        $this->providers = $providers;
    }

    public static function default(): self
    {
        return new self([
            new Ax402_WC_Stablecoin_One_To_One_Rates(),
            new Ax402_WC_Stub_Market_Rates(),
        ]);
    }

    public function rate_usd_to_token(string $symbol, string $network): ?string
    {
        foreach ($this->providers as $provider) {
            $rate = $provider->rate_usd_to_token($symbol, $network);
            if ($rate !== null && $rate !== '') {
                return $rate;
            }
        }

        return null;
    }
}
