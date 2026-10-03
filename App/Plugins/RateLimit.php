<?php

declare(strict_types=1);

namespace App\Plugins;

use App\RateLimit\RateLimit as RateLimitGateway;
use App\RateLimit\RateLimiter;
use App\RateLimit\RateLimitResult;

/** Application-neutral fixed-window limiter using the configured atomic store. */
final class RateLimit
{
    public static function manager(): RateLimiter { return RateLimitGateway::manager(); }
    public static function consume(string $bucket, #[\SensitiveParameter] string $key,
        int $maxAttempts, int $windowSeconds): RateLimitResult
    {
        return self::manager()->consume($bucket, $key, $maxAttempts, $windowSeconds);
    }
    public static function clear(string $bucket, #[\SensitiveParameter] string $key): bool
    {
        return self::manager()->clear($bucket, $key);
    }
    public static function define(string $name, callable|string $resolver): void
    {
        self::manager()->define($name, $resolver);
    }
}
