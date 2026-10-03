<?php

declare(strict_types=1);

namespace App\Mfa;

use InvalidArgumentException;

/** RFC 6238 SHA-1 TOTP with canonical, unpadded RFC 4648 Base32 secrets. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const STEP = 30;

    /** Generate a secret from raw CSPRNG bytes, then encode it for provisioning. */
    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 16 || $bytes > 64) {
            throw new InvalidArgumentException('TOTP secret length is invalid.');
        }
        return self::encodeSecret(random_bytes($bytes));
    }

    public static function encodeSecret(#[\SensitiveParameter] string $raw): string
    {
        if (strlen($raw) < 16 || strlen($raw) > 64) {
            throw new InvalidArgumentException('TOTP secret length is invalid.');
        }
        $result = '';
        $buffer = 0;
        $bits = 0;
        for ($i = 0, $length = strlen($raw); $i < $length; ++$i) {
            $buffer = ($buffer << 8) | ord($raw[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $result .= self::ALPHABET[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }
        if ($bits > 0) $result .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        return $result;
    }

    /** Return the zero-padded six-digit code for a 30-second moving counter. */
    public static function at(#[\SensitiveParameter] string $secret, int $counter): string
    {
        if ($counter < 0) throw new InvalidArgumentException('TOTP counter is invalid.');
        return self::code(self::decodeSecret($secret), $counter);
    }

    /**
     * Return the matching counter so storage can atomically claim it once.
     * Matching the newest counter first handles an unlikely adjacent-code collision.
     */
    public static function match(#[\SensitiveParameter] string $secret,
        #[\SensitiveParameter] string $submitted, int $timestamp, int $skew = 1): ?int
    {
        if ($skew < 0 || $skew > 2) {
            throw new InvalidArgumentException('TOTP clock skew is invalid.');
        }
        if ($timestamp < 0) throw new InvalidArgumentException('TOTP time is invalid.');
        if (preg_match('/\A[0-9]{6}\z/D', $submitted) !== 1) return null;
        $raw = self::decodeSecret($secret);
        $current = intdiv($timestamp, self::STEP);
        for ($counter = $current + $skew; $counter >= max(0, $current - $skew); --$counter) {
            if (hash_equals(self::code($raw, $counter), $submitted)) return $counter;
        }
        return null;
    }

    private static function code(#[\SensitiveParameter] string $raw, int $counter): string
    {
        $digest = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296),
            $counter % 4294967296), $raw, true);
        $offset = ord($digest[19]) & 15;
        $number = ((ord($digest[$offset]) & 127) << 24)
            | (ord($digest[$offset + 1]) << 16)
            | (ord($digest[$offset + 2]) << 8)
            | ord($digest[$offset + 3]);
        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function decodeSecret(#[\SensitiveParameter] string $secret): string
    {
        if ($secret === '' || strlen($secret) > 103
            || preg_match('/\A[A-Z2-7]+\z/D', $secret) !== 1) {
            throw new InvalidArgumentException('TOTP secret is invalid.');
        }
        $raw = '';
        $buffer = 0;
        $bits = 0;
        for ($i = 0, $length = strlen($secret); $i < $length; ++$i) {
            $digit = strpos(self::ALPHABET, $secret[$i]);
            $buffer = ($buffer << 5) | $digit;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $raw .= chr(($buffer >> $bits) & 255);
                $buffer &= (1 << $bits) - 1;
            }
        }
        if ($bits >= 5 || $buffer !== 0 || strlen($raw) < 16 || strlen($raw) > 64
            || self::encodeSecret($raw) !== $secret) {
            throw new InvalidArgumentException('TOTP secret is invalid.');
        }
        return $raw;
    }
}
