<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use Random\RandomException;
use RuntimeException;

/** Shared CSPRNG encoding for URL-safe account and application tokens. */
final class SecureRandom
{
    public static function token(int $bytes = 32): string
    {
        if ($bytes < 16 || $bytes > 128) {
            throw new InvalidArgumentException('Secure token length must be between 16 and 128 bytes.');
        }
        try {
            return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
        } catch (RandomException $exception) {
            throw new RuntimeException('Secure randomness is unavailable.', 0, $exception);
        }
    }

    public static function applicationKey(): string
    {
        try {
            return 'base64:' . base64_encode(random_bytes(32));
        } catch (RandomException $exception) {
            throw new RuntimeException('Secure randomness is unavailable.', 0, $exception);
        }
    }
}
