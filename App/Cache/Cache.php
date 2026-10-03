<?php

declare(strict_types=1);

namespace App\Cache;

use Closure;
use LogicException;

/** Resolves the current Application's cache store for the global helper. */
final class Cache
{
    private static ?Closure $resolver = null;

    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    public static function store(): CacheStore
    {
        if (self::$resolver === null) throw new LogicException('Cache is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }
}
