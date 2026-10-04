<?php

declare(strict_types=1);

namespace App\Redis;

use Closure;

/** Resolve the current Application's RedisManager without opening a socket. */
final class Redis
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }

    public static function manager(): RedisManager
    {
        if (self::$resolver === null) throw new RedisException('Redis is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }
}
