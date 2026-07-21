<?php
declare(strict_types=1);

/**
 * Live Ax402 control-plane exchange rates (`GET /exchange-rates?quote=usd`).
 *
 * API `rate` values are quote-currency prices per 1 token (USD per token from CoinGecko).
 * This provider converts them to tokens-per-1-USD for {@see Ax402_WC_Money::usd_to_token_amount()}.
 */
final class Ax402_WC_Control_Plane_Exchange_Rates implements Ax402_WC_Exchange_Rate_Provider
{
    private const CACHE_TTL_SECONDS = 300;

    private Ax402_WC_Control_Plane_Client $client;

    /** @var array<string, string>|null symbol|network => tokens-per-USD */
    private ?array $tokens_per_usd = null;

    private bool $loaded = false;

    public function __construct(Ax402_WC_Control_Plane_Client $client)
    {
        $this->client = $client;
    }

    public function rate_usd_to_token(string $symbol, string $network): ?string
    {
        $this->ensure_loaded();
        if ($this->tokens_per_usd === null) {
            return null;
        }

        $key = $this->lookup_key($symbol, $network);
        return $this->tokens_per_usd[$key] ?? null;
    }

    /**
     * Invert a quote price (USD per token) into tokens per 1 USD.
     */
    public static function tokens_per_usd_from_price(string $usd_per_token): ?string
    {
        if (!is_numeric($usd_per_token) || (float) $usd_per_token <= 0) {
            return null;
        }

        $tokens = 1.0 / (float) $usd_per_token;
        if (!is_finite($tokens) || $tokens <= 0) {
            return null;
        }

        $formatted = number_format($tokens, 18, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? null : $formatted;
    }

    private function ensure_loaded(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $cached = $this->read_cache();
        if ($cached !== null) {
            $this->tokens_per_usd = $cached;
            return;
        }

        try {
            $payload = $this->client->get_exchange_rates('usd');
        } catch (Throwable $e) {
            $this->tokens_per_usd = null;
            return;
        }

        $map = self::index_rates($payload);
        $this->tokens_per_usd = $map;
        $this->write_cache($map);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    public static function index_rates(array $payload): array
    {
        $rows = $payload['rates'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $symbol = (string) ($row['symbol'] ?? '');
            $network = (string) ($row['network'] ?? '');
            $price = (string) ($row['rate'] ?? '');
            if ($symbol === '' || $network === '') {
                continue;
            }
            $tokens = self::tokens_per_usd_from_price($price);
            if ($tokens === null) {
                continue;
            }
            $map[self::lookup_key($symbol, $network)] = $tokens;
        }

        return $map;
    }

    private static function lookup_key(string $symbol, string $network): string
    {
        return strtoupper(trim($symbol)) . '|' . trim($network);
    }

    /**
     * @return array<string, string>|null
     */
    private function read_cache(): ?array
    {
        if (!function_exists('get_transient')) {
            return null;
        }
        $cached = get_transient($this->cache_key());
        return is_array($cached) ? $cached : null;
    }

    /**
     * @param array<string, string> $map
     */
    private function write_cache(array $map): void
    {
        if (!function_exists('set_transient')) {
            return;
        }
        set_transient($this->cache_key(), $map, self::CACHE_TTL_SECONDS);
    }

    private function cache_key(): string
    {
        return 'ax402_wc_fx_usd_' . md5($this->client->base_url());
    }
}
