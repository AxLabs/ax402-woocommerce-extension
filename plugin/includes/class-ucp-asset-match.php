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
        $token_id = trim((string) ($instrument['token_id'] ?? $instrument['tokenId'] ?? ''));
        if ($token_id !== '') {
            foreach ($options as $option) {
                if ((string) ($option['tokenId'] ?? '') === $token_id) {
                    return $option;
                }
            }
            return null;
        }

        $network = trim((string) ($instrument['network'] ?? ''));
        $asset = trim((string) ($instrument['asset'] ?? ''));
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
}
