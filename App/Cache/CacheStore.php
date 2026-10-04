<?php

declare(strict_types=1);

namespace App\Cache;

use App\Database\ModelClock;
use App\Diagnostics\Diagnostics;
use App\Redis\InfrastructureSelection;
use InvalidArgumentException;
use Throwable;

/**
 * Application-owned cache API. Keys are opaque to drivers and diagnostics;
 * supported values are copied before storage so callers cannot mutate entries.
 */
final class CacheStore
{
    public function __construct(
        private CacheDriver $driver,
        private string $namespace,
        private ModelClock $clock,
        private ?Diagnostics $diagnostics = null,
        private ?InfrastructureSelection $selection = null
    ) {
    }

    /** Safe, structured backend state for future diagnostics/Doctor tooling. */
    public function infrastructure(): ?InfrastructureSelection
    {
        return $this->selection;
    }

    public function read(string $key, mixed $default = null): mixed
    {
        $hash = $this->hashKey($key);
        $start = hrtime(true);
        try { $entry = $this->driver->fetch($hash, fn (): int => $this->now()); }
        catch (Throwable $failure) { $this->recordFailure('read', $start); throw $failure; }
        $this->record('read', $start, $entry !== null);
        return $entry === null ? $default : $entry->value;
    }

    public function has(string $key): bool
    {
        $hash = $this->hashKey($key);
        $start = hrtime(true);
        try { $entry = $this->driver->fetch($hash, fn (): int => $this->now()); }
        catch (Throwable $failure) { $this->recordFailure('read', $start); throw $failure; }
        $this->record('read', $start, $entry !== null);
        return $entry !== null;
    }

    public function store(string $key, mixed $value, ?int $ttl = null): void
    {
        $hash = $this->hashKey($key);
        $this->validateTtl($ttl);
        $value = CacheValue::copy($value);
        $entry = new CacheEntry($value, $ttl === null ? null : $this->expiry($ttl));
        $start = hrtime(true);
        try { $this->driver->put($hash, $entry); }
        catch (Throwable $failure) { $this->recordFailure('write', $start); throw $failure; }
        $this->record('write', $start);
    }

    /** True only when a live entry was removed; expired entries are misses. */
    public function remove(string $key): bool
    {
        $hash = $this->hashKey($key);
        $start = hrtime(true);
        try { $removed = $this->driver->remove($hash, fn (): int => $this->now()); }
        catch (Throwable $failure) { $this->recordFailure('remove', $start); throw $failure; }
        $this->record('remove', $start, $removed);
        return $removed;
    }

    /** Take is one driver operation; the file driver performs it under a key lock. */
    public function take(string $key, mixed $default = null): mixed
    {
        $hash = $this->hashKey($key);
        $start = hrtime(true);
        try { $entry = $this->driver->take($hash, fn (): int => $this->now()); }
        catch (Throwable $failure) { $this->recordFailure('take', $start); throw $failure; }
        $this->record('take', $start, $entry !== null);
        return $entry === null ? $default : $entry->value;
    }

    /** A successful null resolution is cached and is a hit on later calls. */
    public function remember(string $key, ?int $ttl, callable $resolver): mixed
    {
        $hash = $this->hashKey($key);
        $this->validateTtl($ttl);
        $callbackNs = 0;
        $start = hrtime(true);
        try { $resolution = $this->driver->remember($hash, fn (): int => $this->now(), function () use ($resolver, $ttl, &$callbackNs): CacheEntry {
                $callbackStart = hrtime(true);
                try {
                    $value = $resolver();
                    return new CacheEntry(CacheValue::copy($value), $ttl === null ? null : $this->expiry($ttl));
                } finally {
                    $callbackNs += hrtime(true) - $callbackStart;
                }
            }); }
        catch (Throwable $failure) {
            $this->recordFailure('remember', $start, $callbackNs);
            throw $failure;
        }
        $elapsed = max(0.0, (hrtime(true) - $start - $callbackNs) / 1_000_000);
        $this->recordElapsed('remember', $elapsed, !$resolution->created);
        return $resolution->entry->value;
    }

    public function clear(): void
    {
        $start = hrtime(true);
        try { $this->driver->clear(); }
        catch (Throwable $failure) { $this->recordFailure('clear', $start); throw $failure; }
        $this->record('clear', $start);
    }

    private function hashKey(string $key): string
    {
        if ($key === '' || strlen($key) > 512 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw new InvalidArgumentException('Cache key must be nonempty, at most 512 bytes, and free of controls.');
        }
        // No key text reaches a filename, exception, or diagnostics snapshot.
        return hash('sha256', $this->namespace . "\0" . $key);
    }

    private function validateTtl(?int $ttl): void
    {
        if ($ttl !== null && $ttl <= 0) throw new InvalidArgumentException('Cache TTL must be positive seconds.');
    }

    private function expiry(int $ttl): int
    {
        $now = $this->now();
        if ($ttl > PHP_INT_MAX - $now) throw new InvalidArgumentException('Cache TTL is too large.');
        return $now + $ttl;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function record(string $operation, int $start, ?bool $hit = null): void
    {
        $this->recordElapsed($operation, max(0.0, (hrtime(true) - $start) / 1_000_000), $hit);
    }

    private function recordElapsed(string $operation, float $milliseconds, ?bool $hit = null): void
    {
        if ($this->diagnostics === null) return;
        try {
            $this->diagnostics->cache($operation, $milliseconds, $hit);
        } catch (Throwable) {
            // Observability must never change the result of an application cache call.
        }
    }

    /** Backend failures retain neither keys nor values; resolver time is excluded. */
    private function recordFailure(string $operation, int $start, int $callbackNs = 0): void
    {
        $milliseconds = max(0.0, (hrtime(true) - $start - $callbackNs) / 1_000_000);
        $this->diagnostics?->observe('cache.' . $operation, $milliseconds, true);
    }
}
