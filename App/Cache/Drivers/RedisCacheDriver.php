<?php

declare(strict_types=1);

namespace App\Cache\Drivers;

use App\Cache\CacheDriver;
use App\Cache\CacheEntry;
use App\Cache\CacheException;
use App\Cache\CacheResolution;
use App\Cache\CacheValue;
use App\Database\ModelClock;
use App\Redis\RedisConnection;
use Throwable;

/**
 * Shared Cache storage. CacheStore has already fingerprinted application keys;
 * this driver adds an isolated namespace beneath the Redis connection prefix.
 * Expiration is enforced by Redis, including when no PHP request is running.
 */
final class RedisCacheDriver implements CacheDriver
{
    private const TAKE = "local value = redis.call('GET', KEYS[1]); if value then redis.call('DEL', KEYS[1]); end; return value";
    private const MAX_PAYLOAD_BYTES = 8 * 1024 * 1024;
    private string $prefix;

    public function __construct(private RedisConnection $redis, string $namespace, private ModelClock $clock)
    {
        $this->prefix = 'cache:' . hash('sha256', $namespace) . ':';
    }

    public function fetch(string $hash, callable $now): ?CacheEntry
    {
        try {
            $payload = $this->redis->get($this->key($hash));
            return $payload === null ? null : $this->decode($payload);
        } catch (Throwable $failure) {
            throw $this->failure($failure);
        }
    }

    public function put(string $hash, CacheEntry $entry): void
    {
        try {
            $ttl = $entry->expiresAt === null ? null
                : $entry->expiresAt - $this->clock->now()->getTimestamp();
            // The CacheStore clock can be controlled in tests; an already
            // expired entry must never become persistent when TTL reaches 0.
            if ($ttl !== null && $ttl < 1) {
                $this->redis->delete($this->key($hash));
                return;
            }
            $this->redis->set($this->key($hash), $this->encode($entry), $ttl);
        } catch (Throwable $failure) {
            throw $this->failure($failure);
        }
    }

    public function remove(string $hash, callable $now): bool
    {
        return $this->take($hash, $now) !== null;
    }

    /** GET and DEL share one Redis script so another server cannot take twice. */
    public function take(string $hash, callable $now): ?CacheEntry
    {
        try {
            $payload = $this->redis->script(self::TAKE, [$this->key($hash)]);
            if ($payload === null || $payload === false) return null;
            if (!is_string($payload)) throw new CacheException('Cache record is invalid.');
            return $this->decode($payload);
        } catch (Throwable $failure) {
            throw $this->failure($failure);
        }
    }

    /**
     * A miss may compute concurrently on different servers. The first valid
     * publication wins; use a dedicated lock when exactly-once computation is
     * required, since Cache is not an authoritative coordination service.
     */
    public function remember(string $hash, callable $now, callable $producer): CacheResolution
    {
        $current = $this->fetch($hash, $now);
        if ($current !== null) return new CacheResolution($current, false);
        $entry = $producer();
        $this->put($hash, $entry);
        return new CacheResolution($entry, true);
    }

    /** SCAN limits deletion to this Cache namespace, never the Redis database. */
    public function clear(): void
    {
        try {
            foreach ($this->redis->scanPrefix($this->prefix) as $key) {
                $this->redis->delete($key);
            }
        } catch (Throwable $failure) {
            throw $this->failure($failure);
        }
    }

    private function key(string $hash): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new CacheException('Invalid Cache fingerprint.');
        }
        return $this->prefix . $hash;
    }

    /** Only validated scalar/array values enter this versioned safe codec. */
    private function encode(CacheEntry $entry): string
    {
        CacheValue::validate($entry->value);
        $payload = serialize(['version' => 1, 'value' => serialize($entry->value)]);
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new CacheException('Redis Cache payload exceeds the supported size.');
        }
        return $payload;
    }

    private function decode(string $payload): CacheEntry
    {
        try {
            if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
                throw new CacheException('Redis Cache payload exceeds the supported size.');
            }
            $record = @unserialize($payload, ['allowed_classes' => false]);
            if (!is_array($record) || ($record['version'] ?? null) !== 1
                || !isset($record['value']) || !is_string($record['value'])) {
                throw new CacheException('Cache record is invalid.');
            }
            $value = @unserialize($record['value'], ['allowed_classes' => false]);
            if ($value === false && $record['value'] !== 'b:0;') {
                throw new CacheException('Cache record is invalid.');
            }
            CacheValue::validate($value);
            return new CacheEntry($value, null);
        } catch (Throwable $failure) {
            throw $this->failure($failure);
        }
    }

    private function failure(Throwable $failure): CacheException
    {
        return $failure instanceof CacheException ? $failure
            : new CacheException('Redis Cache operation failed.', 0, $failure);
    }
}
