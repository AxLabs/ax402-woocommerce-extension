<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Live Ax402 control-plane exchange rates (`GET /exchange-rates?quote=usd&date=YYYY-MM-DD`).
 *
 * API `rate` values are quote-currency prices per 1 token (USD per token from CoinGecko).
 * This provider converts them to tokens-per-1-USD for {@see Ax402_WC_Money::usd_to_token_amount()}.
 *
 * Fetch strategy: try "today", then calendar "yesterday"; if both are empty or error,
 * fall back to the closest previous business day (Mon–Fri).
 */
final class Ax402_WC_Control_Plane_Exchange_Rates implements Ax402_WC_Exchange_Rate_Provider
{
    private const CACHE_TTL_SECONDS = 300;

    private Ax402_WC_Control_Plane_Client $client;

    /** @var array<string, string>|null symbol|network => tokens-per-USD */
    private ?array $tokens_per_usd = null;

    private bool $loaded = false;

    private bool $force_refresh;

    /** @var \DateTimeImmutable|null Clock override for tests (UTC). */
    private ?\DateTimeImmutable $now;

    /** YYYY-MM-DD of the control-plane payload that produced the current map. */
    private string $resolved_date = '';

    public function __construct(
        Ax402_WC_Control_Plane_Client $client,
        bool $force_refresh = false,
        ?\DateTimeImmutable $now = null
    ) {
        $this->client = $client;
        $this->force_refresh = $force_refresh;
        $this->now = $now;
    }

    /**
     * Drop the WordPress transient so the next lookup hits /exchange-rates.
     */
    public static function clear_cache_for_base_url(string $base_url): void
    {
        if (!function_exists('delete_transient')) {
            return;
        }
        delete_transient(self::cache_key_for_base_url($base_url));
    }

    public function rate_usd_to_token(string $symbol, string $network): ?string
    {
        $this->ensure_loaded();
        if ($this->tokens_per_usd === null) {
            return null;
        }

        $key = self::lookup_key($symbol, $network);
        return $this->tokens_per_usd[$key] ?? null;
    }

    /**
     * Control-plane rate date used for the current map (empty if unknown).
     */
    public function last_rate_date(): string
    {
        $this->ensure_loaded();
        return $this->resolved_date;
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

    /**
     * Dates to try, newest first: today → yesterday → closest previous business day.
     *
     * @return list<string> YYYY-MM-DD in UTC
     */
    public static function candidate_rate_dates(\DateTimeImmutable $now): array
    {
        $today = $now->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0, 0);
        $yesterday = $today->modify('-1 day');
        $business = self::closest_previous_business_day($today);

        $dates = [];
        foreach ([$today, $yesterday, $business] as $day) {
            $formatted = $day->format('Y-m-d');
            if (!in_array($formatted, $dates, true)) {
                $dates[] = $formatted;
            }
        }

        return $dates;
    }

    /**
     * Nearest Mon–Fri strictly before `$day` (UTC calendar date).
     *
     * Sat/Sun/Mon → preceding Friday; Tue–Fri → yesterday.
     */
    public static function closest_previous_business_day(\DateTimeImmutable $day): \DateTimeImmutable
    {
        $day = $day->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0, 0);
        // 1=Mon … 7=Sun (ISO).
        $iso = (int) $day->format('N');
        $subtract = match ($iso) {
            1 => 3, // Monday → Friday
            7 => 2, // Sunday → Friday
            6 => 1, // Saturday → Friday
            default => 1, // Tue–Fri → yesterday
        };

        return $day->modify('-' . $subtract . ' day');
    }

    /**
     * True when the payload has at least one usable rate row.
     *
     * @param array<string, mixed> $payload
     */
    public static function payload_has_rates(array $payload): bool
    {
        return self::index_rates($payload) !== [];
    }

    private function ensure_loaded(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        if (!$this->force_refresh) {
            $cached = $this->read_cache();
            // Empty maps are treated as a miss: production previously returned
            // rates:[] and we must not keep that snapshot for the full TTL.
            if ($cached !== null && $cached !== []) {
                $this->tokens_per_usd = $cached;
                return;
            }
        } else {
            self::clear_cache_for_base_url($this->client->base_url());
        }

        $map = $this->fetch_with_date_fallback();
        $this->tokens_per_usd = $map;
        if ($map !== null && $map !== []) {
            $this->write_cache($map);
        }
    }

    /**
     * @return array<string, string>|null
     */
    private function fetch_with_date_fallback(): ?array
    {
        $now = $this->now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach (self::candidate_rate_dates($now) as $date) {
            try {
                $payload = $this->client->get_exchange_rates('usd', $date);
            } catch (Throwable $e) {
                continue;
            }
            if (!self::payload_has_rates($payload)) {
                continue;
            }

            $this->resolved_date = $date;
            return self::index_rates($payload);
        }

        return null;
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
        $cached = get_transient(self::cache_key_for_base_url($this->client->base_url()));
        if (!is_array($cached) || $cached === []) {
            return null;
        }
        if (($cached['v'] ?? 0) === 2 && isset($cached['map']) && is_array($cached['map'])) {
            $this->resolved_date = (string) ($cached['date'] ?? '');
            return $cached['map'];
        }
        if (!isset($cached['v'])) {
            return $cached;
        }

        return null;
    }

    /**
     * @param array<string, string> $map
     */
    private function write_cache(array $map): void
    {
        if (!function_exists('set_transient')) {
            return;
        }
        set_transient(
            self::cache_key_for_base_url($this->client->base_url()),
            [
                'v' => 2,
                'date' => $this->resolved_date,
                'map' => $map,
            ],
            self::CACHE_TTL_SECONDS
        );
    }

    private static function cache_key_for_base_url(string $base_url): string
    {
        return 'ax402_wc_fx_usd_' . md5(rtrim($base_url, '/'));
    }
}
