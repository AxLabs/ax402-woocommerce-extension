<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Last settlements for the Ax402 settings screen.
 */
final class Ax402_WC_Recent_Settlements
{
    public const INCOME_URL = 'https://ax402.io/dashboard/income';
    public const LIMIT = 5;

    /**
     * @return array{
     *   connected:bool,
     *   error:string,
     *   has_more:bool,
     *   rows:list<array{when:string,when_tz:string,amount:string,network:string,tx:string,tx_full:string}>
     * }
     */
    public static function snapshot(int $limit = self::LIMIT): array
    {
        $empty = ['connected' => false, 'error' => '', 'has_more' => false, 'rows' => []];
        if (Ax402_WC_Settings::client() === null || Ax402_WC_Settings::configured_api_ids() === []) {
            return $empty;
        }

        try {
            $raw = Ax402_WC_Settlement_Reconcile::list_for_store();
        } catch (Throwable) {
            return [
                'connected' => true,
                'error' => __('Could not load recent payments from Ax402.', 'ax402-for-woocommerce'),
                'has_more' => false,
                'rows' => [],
            ];
        }

        $sorted = self::sort_newest_first($raw);
        $limited = self::take_latest($sorted, max(1, $limit));
        $platform = Ax402_WC_Platform_Config_Store::platform();

        $rows = [];
        foreach ($limited['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = self::present($row, $platform);
        }

        return [
            'connected' => true,
            'error' => '',
            'has_more' => $limited['has_more'],
            'rows' => $rows,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{rows:list<array<string, mixed>>,has_more:bool}
     */
    public static function take_latest(array $rows, int $limit = self::LIMIT): array
    {
        $limit = max(1, $limit);

        return [
            'rows' => array_slice($rows, 0, $limit),
            'has_more' => count($rows) > $limit,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function sort_newest_first(array $rows): array
    {
        $with_time = false;
        foreach ($rows as $row) {
            if (self::row_time($row) > 0) {
                $with_time = true;
                break;
            }
        }
        if (!$with_time) {
            return $rows;
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => self::row_time($b) <=> self::row_time($a)
        );

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function row_time(array $row): int
    {
        foreach (['created_at', 'timestamp', 'createdAt', 'paid_at', 'created'] as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $value = $row[$key];
            if (is_numeric($value)) {
                $n = (int) $value;
                return $n > 1_000_000_000_000 ? (int) floor($n / 1000) : $n;
            }
            $ts = strtotime((string) $value);
            if ($ts !== false) {
                return $ts;
            }
        }

        return 0;
    }

    public static function format_when(int $time): string
    {
        if ($time <= 0) {
            return '—';
        }

        if (function_exists('wp_date')) {
            $time_format = (string) get_option('time_format', 'g:i a');
            if ($time_format === '') {
                $time_format = 'g:i a';
            }

            return (string) wp_date('M j, Y ' . $time_format, $time);
        }

        return gmdate('M j, Y H:i', $time);
    }

    public static function timezone_label(int $time): string
    {
        if ($time <= 0) {
            return '';
        }

        if (!function_exists('wp_timezone')) {
            return 'UTC';
        }

        $dt = (new DateTimeImmutable('@' . (string) $time))->setTimezone(wp_timezone());
        $abbr = trim($dt->format('T'));
        if ($abbr === '' || $abbr === 'Z' || $abbr === 'UTC' || $abbr === 'GMT+0000' || $abbr === 'GMT-0000') {
            return 'UTC';
        }
        if (preg_match('/^GMT([+-])(\d{2})(\d{2})$/', $abbr, $matches) === 1) {
            $hours = (int) $matches[2];
            $mins = (int) $matches[3];
            if ($hours === 0 && $mins === 0) {
                return 'UTC';
            }
            if ($mins === 0) {
                return 'UTC' . $matches[1] . (string) $hours;
            }

            return 'UTC' . $matches[1] . (string) $hours . ':' . $matches[3];
        }

        return $abbr;
    }

    public static function format_amount(string $amount, string $symbol, int $decimals): string
    {
        $amount = trim($amount);
        if ($amount === '') {
            return $symbol !== '' ? $symbol : '—';
        }

        $human = self::human_amount($amount, $decimals);
        $display = str_replace('.', ',', $human);

        return $symbol !== '' ? $symbol . ' · ' . $display : $display;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $platform
     * @return array{when:string,when_tz:string,amount:string,network:string,tx:string,tx_full:string}
     */
    private static function present(array $row, array $platform): array
    {
        $network = (string) ($row['network'] ?? $row['chain'] ?? '');
        $asset = (string) ($row['asset'] ?? '');
        $symbol = (string) ($row['symbol'] ?? $row['token'] ?? '');
        $amount = (string) ($row['amount'] ?? $row['amount_atomic'] ?? '');
        $token = self::token_from_platform($platform, $network, $asset, $symbol);
        if ($symbol === '') {
            $symbol = $token['symbol'];
        }
        $decimals = (int) ($row['decimals'] ?? $row['token_decimals'] ?? $token['decimals']);

        $tx = trim((string) ($row['tx'] ?? $row['transaction'] ?? $row['tx_hash'] ?? ''));

        $time = self::row_time($row);

        return [
            'when' => self::format_when($time),
            'when_tz' => self::timezone_label($time),
            'amount' => self::format_amount($amount, $symbol, $decimals),
            'network' => $network !== '' ? Ax402_WC_Platform_Tokens::network_label($network) : '—',
            'tx' => self::short_tx($tx),
            'tx_full' => $tx,
        ];
    }

    /**
     * @param array<string, mixed> $platform
     * @return array{symbol:string,decimals:int}
     */
    private static function token_from_platform(
        array $platform,
        string $network,
        string $asset,
        string $symbol
    ): array {
        foreach (Ax402_WC_Platform_Tokens::enabled_tokens($platform) as $token) {
            $token_asset = (string) ($token['asset'] ?? $token['address'] ?? '');
            $token_network = (string) ($token['network'] ?? '');
            $token_symbol = (string) ($token['symbol'] ?? '');
            $network_ok = $network === '' || strcasecmp($token_network, $network) === 0;
            $matched = $asset !== ''
                ? strcasecmp($token_asset, $asset) === 0 && $network_ok
                : ($symbol !== '' && strcasecmp($token_symbol, $symbol) === 0 && $network_ok);
            if (!$matched) {
                continue;
            }
            $decimals = (int) ($token['decimals'] ?? 0);
            if ($decimals < 0 || $decimals > 18) {
                $decimals = 0;
            }

            return [
                'symbol' => $token_symbol !== '' ? $token_symbol : $symbol,
                'decimals' => $decimals,
            ];
        }

        if (strtoupper($symbol) === 'USDC') {
            return ['symbol' => $symbol, 'decimals' => Ax402_WC_Money::USDC_DECIMALS];
        }

        return ['symbol' => $symbol, 'decimals' => 0];
    }

    private static function human_amount(string $amount, int $decimals): string
    {
        if (str_contains($amount, '.')) {
            return $amount;
        }
        if ($decimals > 0 && preg_match('/^\d+$/', $amount) === 1) {
            return Ax402_WC_Money::from_atomic($amount, $decimals);
        }

        return $amount;
    }

    private static function short_tx(string $tx): string
    {
        $tx = trim($tx);
        if ($tx === '') {
            return '—';
        }
        if (strlen($tx) <= 16) {
            return $tx;
        }

        return substr($tx, 0, 10) . '…' . substr($tx, -6);
    }
}
