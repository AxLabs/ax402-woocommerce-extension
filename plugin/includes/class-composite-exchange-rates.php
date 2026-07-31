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

    /**
     * Stablecoins stay 1:1; market tokens use the control-plane FX API when a client is available.
     */
    public static function default(
        ?Ax402_WC_Control_Plane_Client $client = null,
        bool $force_refresh_fx = false
    ): self {
        $providers = [new Ax402_WC_Stablecoin_One_To_One_Rates()];

        if ($client === null && function_exists('get_option')) {
            $client = Ax402_WC_Settings::client();
        }

        if ($client !== null) {
            $providers[] = new Ax402_WC_Control_Plane_Exchange_Rates($client, $force_refresh_fx);
        } else {
            $providers[] = new Ax402_WC_Stub_Market_Rates();
        }

        return new self($providers);
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
