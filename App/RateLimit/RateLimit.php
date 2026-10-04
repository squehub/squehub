<?php

declare(strict_types=1);

namespace App\RateLimit;

use Closure;

/** Resolves the current Application's limiter for helpers and HTTP middleware. */
final class RateLimit
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }

    public static function manager(): RateLimiter
    {
        if (self::$resolver === null) throw new RateLimitException('Rate limiting is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }
}
