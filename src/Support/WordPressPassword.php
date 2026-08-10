<?php

declare(strict_types=1);

namespace App\Support;

final class WordPressPassword
{
    private const ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function verify(string $plainPassword, string $storedHash): bool
    {
        $password = (string) $plainPassword;
        $hash = trim((string) $storedHash);
        if ($hash === '') {
            return false;
        }

        if (password_verify($password, $hash)) {
            return true;
        }

        // WordPress 6.8+ may use "$wp" prefixed bcrypt hashes.
        if (str_starts_with($hash, '$wp')) {
            $innerHash = substr($hash, 3);
            if ($innerHash !== '') {
                $preHashed = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
                if (password_verify($preHashed, $innerHash)) {
                    return true;
                }
            }
        }

        if (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$')) {
            return self::verifyPortablePhpass($password, $hash);
        }

        return false;
    }

    public static function needsUpgradeToBcrypt(string $storedHash): bool
    {
        $hash = trim((string) $storedHash);
        if ($hash === '') {
            return true;
        }

        if (str_starts_with($hash, '$wp')) {
            return true;
        }

        $info = password_get_info($hash);
        if (($info['algo'] ?? null) !== PASSWORD_BCRYPT) {
            return true;
        }

        return password_needs_rehash($hash, PASSWORD_BCRYPT);
    }

    private static function verifyPortablePhpass(string $plainPassword, string $storedHash): bool
    {
        if (strlen($storedHash) < 34) {
            return false;
        }
        $id = substr($storedHash, 0, 3);
        if ($id !== '$P$' && $id !== '$H$') {
            return false;
        }

        $countLog2Char = $storedHash[3] ?? '';
        $countLog2 = strpos(self::ITOA64, $countLog2Char);
        if ($countLog2 === false || $countLog2 < 7 || $countLog2 > 30) {
            return false;
        }

        $salt = substr($storedHash, 4, 8);
        if (strlen($salt) !== 8) {
            return false;
        }

        $count = 1 << $countLog2;
        $hash = md5($salt . $plainPassword, true);
        do {
            $hash = md5($hash . $plainPassword, true);
        } while (--$count > 0);

        $encoded = self::encode64($hash, 16);
        $computed = substr($storedHash, 0, 12) . $encoded;

        return hash_equals(substr($storedHash, 0, 34), substr($computed, 0, 34));
    }

    private static function encode64(string $input, int $count): string
    {
        $output = '';
        $i = 0;
        do {
            $value = ord($input[$i++]);
            $output .= self::ITOA64[$value & 0x3f];

            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }
            $output .= self::ITOA64[($value >> 6) & 0x3f];

            if ($i++ >= $count) {
                break;
            }

            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }
            $output .= self::ITOA64[($value >> 12) & 0x3f];

            if ($i++ >= $count) {
                break;
            }
            $output .= self::ITOA64[($value >> 18) & 0x3f];
        } while ($i < $count);

        return $output;
    }
}

