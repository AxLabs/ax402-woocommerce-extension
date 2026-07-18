<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Encrypt secrets at rest using WordPress salts.
 */
final class Ax402_WC_Crypto
{
    private const PREFIX = 'ax402v1:';

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = self::key();
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('Unable to encrypt secret');
        }

        return self::PREFIX . base64_encode($iv . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        if (!str_starts_with($payload, self::PREFIX)) {
            // Legacy / plaintext fallback for local tests only.
            return $payload;
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 17) {
            throw new RuntimeException('Invalid encrypted payload');
        }

        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new RuntimeException('Unable to decrypt secret');
        }

        return $plain;
    }

    private static function key(): string
    {
        $material = (defined('AUTH_KEY') ? AUTH_KEY : 'ax402') .
            (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : 'wc');
        return hash('sha256', $material, true);
    }
}
