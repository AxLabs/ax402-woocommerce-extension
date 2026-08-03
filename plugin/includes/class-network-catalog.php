<?php
declare(strict_types=1);

defined('ABSPATH') || exit;


/**
 * Dynamic network catalog derived from Ax402 platform tokens (+ optional /supported-networks).
 */
final class Ax402_WC_Network_Catalog
{
    /** @var array<string, array{label:string,rpc_url:string,explorer_url:string}> */
    private array $by_network = [];

    /**
     * @param array<string, mixed> $platform
     * @param array<string, mixed>|null $supported_networks Raw /supported-networks payload
     */
    public static function from_platform(array $platform, ?array $supported_networks = null): self
    {
        $self = new self();

        foreach (Ax402_WC_Platform_Tokens::enabled_tokens($platform) as $token) {
            $network = (string) ($token['network'] ?? '');
            if ($network === '') {
                continue;
            }
            $meta = Ax402_WC_Chain_Metadata::for_token($token);
            // First token on a network wins label/rpc; later tokens may fill blanks.
            if (!isset($self->by_network[$network])) {
                $self->by_network[$network] = $meta;
                continue;
            }
            foreach (['label', 'rpc_url', 'explorer_url'] as $key) {
                if ($self->by_network[$network][$key] === '' && $meta[$key] !== '') {
                    $self->by_network[$network][$key] = $meta[$key];
                }
            }
        }

        if (is_array($supported_networks)) {
            foreach (self::networks_from_supported_payload($supported_networks) as $network) {
                if (isset($self->by_network[$network])) {
                    continue;
                }
                $self->by_network[$network] = Ax402_WC_Chain_Metadata::from_caip2($network);
                if ($self->by_network[$network]['label'] === '') {
                    $self->by_network[$network]['label'] = $network;
                }
            }
        }

        return $self;
    }

    /**
     * Build from cached store when available.
     */
    public static function from_store(): self
    {
        if (!class_exists('Ax402_WC_Platform_Config_Store', false)
            && !class_exists('Ax402_WC_Platform_Config_Store')
        ) {
            return new self();
        }

        $cache = Ax402_WC_Platform_Config_Store::get();
        return self::from_platform(
            $cache['platform'],
            $cache['supported_networks'] ?? null
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public static function networks_from_supported_payload(array $payload): array
    {
        $kinds = $payload['kinds'] ?? [];
        if (!is_array($kinds)) {
            return [];
        }

        $out = [];
        foreach ($kinds as $kind) {
            if (!is_array($kind)) {
                continue;
            }
            $network = (string) ($kind['network'] ?? '');
            if ($network !== '') {
                $out[$network] = $network;
            }
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    public function networks(): array
    {
        return array_keys($this->by_network);
    }

    public function label(string $network): string
    {
        if (isset($this->by_network[$network]['label'])
            && $this->by_network[$network]['label'] !== ''
        ) {
            return $this->by_network[$network]['label'];
        }

        $meta = Ax402_WC_Chain_Metadata::from_caip2($network);
        return $meta['label'] !== '' ? $meta['label'] : $network;
    }

    public function rpc_url(string $network): string
    {
        if (isset($this->by_network[$network]['rpc_url'])) {
            return $this->by_network[$network]['rpc_url'];
        }

        return Ax402_WC_Chain_Metadata::from_caip2($network)['rpc_url'];
    }

    public function explorer_url(string $network): string
    {
        if (isset($this->by_network[$network]['explorer_url'])) {
            return $this->by_network[$network]['explorer_url'];
        }

        return Ax402_WC_Chain_Metadata::from_caip2($network)['explorer_url'];
    }

    /**
     * @return array<string, string>
     */
    public function rpc_map(): array
    {
        $out = [];
        foreach ($this->by_network as $network => $meta) {
            if ($meta['rpc_url'] !== '') {
                $out[$network] = $meta['rpc_url'];
            }
        }

        return $out;
    }
}
