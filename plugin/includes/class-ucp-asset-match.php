<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Match a UCP payment instrument to a prepared settlement option.
 */
final class Ax402_WC_Ucp_Asset_Match
{
    /**
     * @param list<array<string, mixed>> $options order meta settlement options
     * @param array<string, mixed> $instrument UCP payment.instruments[] entry
     * @return array<string, mixed>|null
     */
    public static function match(array $options, array $instrument): ?array
    {
        $identity = '';
        foreach (['id', 'token_id', 'tokenId'] as $key) {
            $value = trim((string) ($instrument[$key] ?? ''));
            if ($value !== '') {
                $identity = $value;
                break;
            }
        }

        $network = trim((string) ($instrument['network'] ?? ''));
        $asset = trim((string) ($instrument['asset'] ?? ''));

        if ($identity !== '') {
            $found = null;
            foreach ($options as $option) {
                if (self::same_token_id((string) ($option['tokenId'] ?? ''), $identity)) {
                    $found = $option;
                    break;
                }
            }
            if ($found === null) {
                return null;
            }
            $found_network = (string) ($found['network'] ?? '');
            $found_asset = (string) ($found['asset'] ?? '');
            if ($network !== '' && strcasecmp($found_network, $network) !== 0) {
                return null;
            }
            if ($asset !== '' && strcasecmp($found_asset, $asset) !== 0) {
                return null;
            }

            return $found;
        }

        if ($network === '' && $asset === '') {
            return $options[0] ?? null;
        }

        foreach ($options as $option) {
            $option_network = (string) ($option['network'] ?? '');
            $option_asset = (string) ($option['asset'] ?? '');
            $network_ok = $network === '' || strcasecmp($option_network, $network) === 0;
            $asset_ok = $asset === '' || strcasecmp($option_asset, $asset) === 0;
            if ($network_ok && $asset_ok) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $options
     * @return list<array{network:string,asset:string,symbol:string}>
     */
    public static function available_pairs(array $options): array
    {
        $pairs = [];
        foreach ($options as $option) {
            $network = (string) ($option['network'] ?? '');
            $asset = (string) ($option['asset'] ?? '');
            if ($network === '' || $asset === '') {
                continue;
            }
            $pairs[] = [
                'network' => $network,
                'asset' => $asset,
                'symbol' => (string) ($option['symbol'] ?? ''),
            ];
        }

        return $pairs;
    }

    /**
     * UCP payment.instruments for a checkout (no gateway URLs).
     *
     * Each Ax402 settlement token is its own x402 resource. Listing them here
     * lets an agent select XGAS vs USDC before complete; the 402 accepts[]
     * still come from only the selected (or default) endpoint.
     *
     * @param list<array<string, mixed>> $options
     * @param array<string, mixed>|null $selected matched settlement option
     * @return list<array<string, mixed>>
     */
    public static function checkout_instruments(array $options, ?array $selected = null): array
    {
        $selected_token = (string) ($selected['tokenId'] ?? '');
        $instruments = [];
        $index = 0;
        foreach ($options as $option) {
            $network = (string) ($option['network'] ?? '');
            $asset = (string) ($option['asset'] ?? '');
            if ($network === '' || $asset === '') {
                continue;
            }
            $token_id = (string) ($option['tokenId'] ?? '');
            $is_selected = $selected_token !== ''
                ? self::same_token_id($token_id, $selected_token)
                : $index === 0;
            $symbol = (string) ($option['symbol'] ?? '');
            $instruments[] = [
                'id' => $token_id !== '' ? $token_id : ('x402_' . $index),
                'handler_id' => 'org.x402.payment',
                'type' => 'x402',
                'selected' => $is_selected,
                'network' => $network,
                'asset' => $asset,
                'display' => [
                    'name' => $symbol !== '' ? $symbol : $asset,
                ],
            ];
            $index++;
        }

        return $instruments;
    }

    /**
     * @param list<mixed> $instruments
     * @return array<string, mixed>
     */
    public static function preferred_instrument(array $instruments): array
    {
        $valid = [];
        foreach ($instruments as $row) {
            if (is_array($row)) {
                $valid[] = $row;
            }
        }
        foreach ($valid as $row) {
            if (!empty($row['selected'])) {
                return $row;
            }
        }

        return count($valid) === 1 ? $valid[0] : [];
    }

    /**
     * @param array<string, mixed> $instrument
     */
    public static function has_preference(array $instrument): bool
    {
        return trim((string) ($instrument['id'] ?? '')) !== ''
            || trim((string) ($instrument['network'] ?? '')) !== ''
            || trim((string) ($instrument['asset'] ?? '')) !== ''
            || trim((string) ($instrument['token_id'] ?? $instrument['tokenId'] ?? '')) !== '';
    }

    public static function same_token_id(string $left, string $right): bool
    {
        return $left !== '' && $right !== '' && strcasecmp($left, $right) === 0;
    }
}
