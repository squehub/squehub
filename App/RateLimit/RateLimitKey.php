<?php

declare(strict_types=1);

namespace App\RateLimit;

/** Validates identities before deriving a digest; plaintext never reaches a store. */
final class RateLimitKey
{
    public static function digest(#[\SensitiveParameter] string $key): string
    {
        if ($key === '' || strlen($key) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw new RateLimitException('Rate-limit key must be a non-empty bounded string without controls.');
        }
        return hash('sha256', $key);
    }

    public static function bucket(string $bucket): void
    {
        if (strlen($bucket) > 128 || preg_match('/\A[A-Za-z0-9_][A-Za-z0-9._-]*\z/D', $bucket) !== 1) {
            throw new RateLimitException('Rate-limit bucket must be a bounded identifier.');
        }
    }

    public static function prefix(string $prefix): void
    {
        if ($prefix === '' || strlen($prefix) > 128 || str_contains($prefix, '..')
            || preg_match('/\A[A-Za-z0-9_][A-Za-z0-9._-]*\z/D', $prefix) !== 1) {
            throw new RateLimitException('Rate-limit prefix must be a bounded identifier.');
        }
    }
}
