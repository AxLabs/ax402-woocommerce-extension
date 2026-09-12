<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * EVM pay-to address parsing and EIP-55 checksums.
 */
final class Ax402_WC_Evm_Address
{
    public const ERROR_EMPTY = '';
    public const ERROR_FORMAT = 'format';
    public const ERROR_CHECKSUM = 'checksum';

    /**
     * @return array{ok:bool,checksummed:string,error:string}
     */
    public static function parse(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['ok' => true, 'checksummed' => '', 'error' => self::ERROR_EMPTY];
        }

        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $value) !== 1) {
            return ['ok' => false, 'checksummed' => '', 'error' => self::ERROR_FORMAT];
        }

        $hex = substr($value, 2);
        $lower = strtolower($hex);
        $upper = strtoupper($hex);
        if ($hex !== $lower && $hex !== $upper) {
            $checksummed = self::checksum('0x' . $lower);
            if (strcmp($checksummed, '0x' . $hex) !== 0) {
                return ['ok' => false, 'checksummed' => '', 'error' => self::ERROR_CHECKSUM];
            }

            return ['ok' => true, 'checksummed' => $checksummed, 'error' => self::ERROR_EMPTY];
        }

        return ['ok' => true, 'checksummed' => self::checksum('0x' . $lower), 'error' => self::ERROR_EMPTY];
    }

    public static function is_valid(string $value): bool
    {
        $parsed = self::parse($value);
        return $parsed['ok'] && $parsed['checksummed'] !== '';
    }

    public static function checksum(string $address): string
    {
        $hex = strtolower(str_starts_with($address, '0x') ? substr($address, 2) : $address);
        $hash = Ax402_WC_Keccak::hash256_hex($hex);
        $out = '0x';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $nibble = hexdec($hash[$i]);
            $out .= $nibble >= 8 ? strtoupper($hex[$i]) : $hex[$i];
        }

        return $out;
    }

    public static function error_message(string $error): string
    {
        return match ($error) {
            self::ERROR_FORMAT => __(
                'EVM pay-to must be a 42-character address starting with 0x (40 hex characters).',
                'ax402-for-woocommerce'
            ),
            self::ERROR_CHECKSUM => __(
                'EVM pay-to checksum does not match. Check you pasted the address correctly.',
                'ax402-for-woocommerce'
            ),
            default => '',
        };
    }
}
