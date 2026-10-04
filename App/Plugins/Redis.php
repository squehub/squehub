<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Redis\Redis as RedisGateway;
use App\Redis\RedisConnection;
use App\Redis\RedisManager;

/** Small application-facing Redis gateway; canonical internals live in App\Redis. */
final class Redis
{
    public static function manager(): RedisManager { return RedisGateway::manager(); }
    public static function connection(?string $name = null): RedisConnection { return self::manager()->connection($name); }
    public static function get(string $key): ?string { return self::connection()->get($key); }
    public static function set(string $key, #[\SensitiveParameter] string $value,
        ?int $ttl = null, bool $onlyIfMissing = false): bool
    {
        return self::connection()->set($key, $value, $ttl, $onlyIfMissing);
    }
    public static function delete(string $key): bool { return self::connection()->delete($key); }
    public static function exists(string $key): bool { return self::connection()->exists($key); }
    public static function expire(string $key, int $seconds): bool { return self::connection()->expire($key, $seconds); }
    public static function ttl(string $key): int { return self::connection()->ttl($key); }
    public static function increment(string $key): int { return self::connection()->increment($key); }
    public static function decrement(string $key): int { return self::connection()->decrement($key); }

    /** A non-probed result does not contact Redis; available() intentionally does. */
    public static function capability(?string $name = null, bool $probe = false): array
    {
        return self::manager()->capability($name, $probe);
    }
    public static function available(?string $name = null): bool { return self::manager()->available($name); }
}
