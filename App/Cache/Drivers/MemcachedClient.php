<?php

declare(strict_types=1);

namespace App\Cache\Drivers;

/** Narrow protocol shared by ext-memcached and deterministic tests. */
interface MemcachedClient
{
    public function read(string $key): ?MemcachedRecord;
    public function set(string $key, string|int $value, int $expiration): void;
    public function add(string $key, string|int $value, int $expiration): bool;
    public function cas(string|int|float $token, string $key, string|int $value, int $expiration): bool;
    public function increment(string $key): ?int;
}
