<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Cache\Cache as CacheGateway;
use App\Cache\CacheStore;

/** Convenience calls delegate to the current Application's configured store. */
final class Cache
{
    public static function store(): CacheStore { return CacheGateway::store(); }
    public static function read(string $key, mixed $default = null): mixed { return self::store()->read($key, $default); }
    public static function has(string $key): bool { return self::store()->has($key); }
    public static function write(string $key, mixed $value, ?int $ttl = null): void
    {
        self::store()->store($key, $value, $ttl);
    }
    public static function remove(string $key): bool { return self::store()->remove($key); }
    public static function take(string $key, mixed $default = null): mixed { return self::store()->take($key, $default); }
    public static function remember(string $key, ?int $ttl, callable $resolver): mixed
    {
        return self::store()->remember($key, $ttl, $resolver);
    }
    public static function clear(): void { self::store()->clear(); }
}
