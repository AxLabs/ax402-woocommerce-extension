<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Keccak-256 (Ethereum / EIP-55). This is not NIST SHA3-256.
 *
 * Adapted from kornrunner/keccak (MIT License).
 * https://github.com/kornrunner/php-keccak
 */
final class Ax402_WC_Keccak
{
    /** @var list<int> */
    private const RC_LO = [
        0x00000001, 0x00008082, 0x0000808A, 0x80008000,
        0x0000808B, 0x80000001, 0x80008081, 0x00008009,
        0x0000008A, 0x00000088, 0x80008009, 0x8000000A,
        0x8000808B, 0x0000008B, 0x00008089, 0x00008003,
        0x00008002, 0x00000080, 0x0000800A, 0x8000000A,
        0x80008081, 0x00008080, 0x80000001, 0x80008008,
    ];

    /** @var list<int> */
    private const RC_HI = [
        0x00000000, 0x00000000, 0x80000000, 0x80000000,
        0x00000000, 0x00000000, 0x80000000, 0x80000000,
        0x00000000, 0x00000000, 0x00000000, 0x00000000,
        0x00000000, 0x80000000, 0x80000000, 0x80000000,
        0x80000000, 0x80000000, 0x00000000, 0x80000000,
        0x80000000, 0x80000000, 0x00000000, 0x80000000,
    ];

    /** @var list<int> */
    private const RHO = [
        0, 1, 62, 28, 27,
        36, 44, 6, 55, 20,
        3, 10, 43, 25, 39,
        41, 45, 15, 21, 8,
        18, 2, 61, 56, 14,
    ];

    /**
     * 32-byte Keccak-256 digest of $message.
     */
    public static function hash256(string $message): string
    {
        $rate = 136;
        $state = array_fill(0, 25, [0, 0]);

        $len = strlen($message);
        $offset = 0;
        while ($offset + $rate <= $len) {
            self::absorb_block($state, substr($message, $offset, $rate));
            self::permute($state);
            $offset += $rate;
        }

        $block = substr($message, $offset);
        $block .= "\x01";
        $block = str_pad($block, $rate, "\x00");
        $block[$rate - 1] = chr(ord($block[$rate - 1]) | 0x80);
        self::absorb_block($state, $block);
        self::permute($state);

        $out = '';
        for ($i = 0; $i < 4; $i++) {
            $out .= pack('V2', $state[$i][0], $state[$i][1]);
        }

        return $out;
    }

    public static function hash256_hex(string $message): string
    {
        return bin2hex(self::hash256($message));
    }

    /**
     * @param list<array{0:int,1:int}> $state
     */
    private static function absorb_block(array &$state, string $block): void
    {
        for ($i = 0; $i < 17; $i++) {
            $pair = unpack('V2', substr($block, $i * 8, 8));
            $state[$i][0] = ($state[$i][0] ^ (int) $pair[1]) & 0xFFFFFFFF;
            $state[$i][1] = ($state[$i][1] ^ (int) $pair[2]) & 0xFFFFFFFF;
        }
    }

    /**
     * @param list<array{0:int,1:int}> $state
     */
    private static function permute(array &$state): void
    {
        for ($round = 0; $round < 24; $round++) {
            $c = [];
            for ($x = 0; $x < 5; $x++) {
                $lo = $state[$x][0] ^ $state[$x + 5][0] ^ $state[$x + 10][0] ^ $state[$x + 15][0] ^ $state[$x + 20][0];
                $hi = $state[$x][1] ^ $state[$x + 5][1] ^ $state[$x + 10][1] ^ $state[$x + 15][1] ^ $state[$x + 20][1];
                $c[$x] = [$lo & 0xFFFFFFFF, $hi & 0xFFFFFFFF];
            }

            $d = [];
            for ($x = 0; $x < 5; $x++) {
                $rot = self::rotl($c[($x + 1) % 5][0], $c[($x + 1) % 5][1], 1);
                $d[$x] = [
                    ($c[($x + 4) % 5][0] ^ $rot[0]) & 0xFFFFFFFF,
                    ($c[($x + 4) % 5][1] ^ $rot[1]) & 0xFFFFFFFF,
                ];
            }

            for ($i = 0; $i < 25; $i++) {
                $state[$i][0] = ($state[$i][0] ^ $d[$i % 5][0]) & 0xFFFFFFFF;
                $state[$i][1] = ($state[$i][1] ^ $d[$i % 5][1]) & 0xFFFFFFFF;
            }

            $b = array_fill(0, 25, [0, 0]);
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $src = $x + 5 * $y;
                    $rotated = self::rotl($state[$src][0], $state[$src][1], self::RHO[$src]);
                    $dest = $y + 5 * ((2 * $x + 3 * $y) % 5);
                    $b[$dest] = $rotated;
                }
            }

            for ($y = 0; $y < 5; $y++) {
                for ($x = 0; $x < 5; $x++) {
                    $i = $x + 5 * $y;
                    $x1 = (($x + 1) % 5) + 5 * $y;
                    $x2 = (($x + 2) % 5) + 5 * $y;
                    $not_lo = (~$b[$x1][0]) & 0xFFFFFFFF;
                    $not_hi = (~$b[$x1][1]) & 0xFFFFFFFF;
                    $state[$i][0] = ($b[$i][0] ^ ($not_lo & $b[$x2][0])) & 0xFFFFFFFF;
                    $state[$i][1] = ($b[$i][1] ^ ($not_hi & $b[$x2][1])) & 0xFFFFFFFF;
                }
            }

            $state[0][0] = ($state[0][0] ^ self::RC_LO[$round]) & 0xFFFFFFFF;
            $state[0][1] = ($state[0][1] ^ self::RC_HI[$round]) & 0xFFFFFFFF;
        }
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function rotl(int $lo, int $hi, int $n): array
    {
        $lo &= 0xFFFFFFFF;
        $hi &= 0xFFFFFFFF;
        $n %= 64;
        if ($n === 0) {
            return [$lo, $hi];
        }
        if ($n >= 32) {
            $n -= 32;
            $tmp = $lo;
            $lo = $hi;
            $hi = $tmp;
        }
        if ($n === 0) {
            return [$lo, $hi];
        }

        return [
            (($lo << $n) | ($hi >> (32 - $n))) & 0xFFFFFFFF,
            (($hi << $n) | ($lo >> (32 - $n))) & 0xFFFFFFFF,
        ];
    }
}
