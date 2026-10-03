<?php

declare(strict_types=1);

namespace App\Cache;

/** Driver operations use opaque hashes; only CacheStore handles application keys. */
interface CacheDriver
{
    /** The clock callback is invoked after any driver lock is acquired. */
    public function fetch(string $hash, callable $now): ?CacheEntry;
    public function put(string $hash, CacheEntry $entry): void;
    public function remove(string $hash, callable $now): bool;
    public function take(string $hash, callable $now): ?CacheEntry;

    /** Producer runs inside the driver's per-key critical section where available. */
    public function remember(string $hash, callable $now, callable $producer): CacheResolution;
    public function clear(): void;
}
