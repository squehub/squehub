<?php

declare(strict_types=1);

namespace App\Cache\Drivers;

use App\Cache\CacheDriver;
use App\Cache\CacheEntry;
use App\Cache\CacheResolution;
use App\Cache\CacheValue;

/** Request-process cache with per-instance state and the same expiry contract as files. */
final class ArrayCacheDriver implements CacheDriver
{
    /** @var array<string, CacheEntry> */
    private array $entries = [];

    public function fetch(string $hash, callable $now): ?CacheEntry
    {
        $entry = $this->entries[$hash] ?? null;
        if ($entry !== null && $entry->expired($now())) {
            unset($this->entries[$hash]);
            return null;
        }
        return $entry === null ? null : new CacheEntry(CacheValue::copy($entry->value), $entry->expiresAt);
    }

    public function put(string $hash, CacheEntry $entry): void
    {
        $this->entries[$hash] = new CacheEntry(CacheValue::copy($entry->value), $entry->expiresAt);
    }

    public function remove(string $hash, callable $now): bool
    {
        $entry = $this->fetch($hash, $now);
        unset($this->entries[$hash]);
        return $entry !== null;
    }

    public function take(string $hash, callable $now): ?CacheEntry
    {
        $entry = $this->fetch($hash, $now);
        unset($this->entries[$hash]);
        return $entry;
    }

    public function remember(string $hash, callable $now, callable $producer): CacheResolution
    {
        $entry = $this->fetch($hash, $now);
        if ($entry !== null) return new CacheResolution($entry, false);
        $entry = $producer();
        $this->put($hash, $entry);
        return new CacheResolution($this->fetch($hash, $now) ?? $entry, true);
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
