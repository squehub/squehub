<?php

declare(strict_types=1);

namespace App\Cache\Drivers;

use App\Cache\CacheDriver;
use App\Cache\CacheEntry;
use App\Cache\CacheException;
use App\Cache\CacheResolution;
use App\Cache\CacheValue;
use App\Database\ModelClock;
use Throwable;

/** Shared cache with namespace generations instead of a server-wide flush. */
final class MemcachedCacheDriver implements CacheDriver
{
    private const MAX_PAYLOAD_BYTES = 8 * 1024 * 1024;
    private const RELATIVE_EXPIRATION_LIMIT = 30 * 24 * 60 * 60;
    private const MAX_ABSOLUTE_EXPIRATION = 4294967295;
    private const RETRIES = 8;
    private const TOMBSTONE = '!';

    private string $prefix;
    private string $generationKey;

    public function __construct(
        private MemcachedClient $client,
        string $namespace,
        private ModelClock $clock
    ) {
        $this->prefix = 'cache:' . hash('sha256', $namespace) . ':';
        $this->generationKey = $this->prefix . 'generation';
    }

    public function fetch(string $hash, callable $now): ?CacheEntry
    {
        $record = $this->client->read($this->key($hash));
        return $record === null ? null : $this->decode($record->value, $now());
    }

    public function put(string $hash, CacheEntry $entry): void
    {
        $key = $this->key($hash);
        $expiration = $this->expiration($entry);
        if ($entry->expiresAt !== null && $expiration === 0) {
            $this->client->set($key, self::TOMBSTONE, 1);
            return;
        }
        $this->client->set($key, $this->encode($entry), $expiration);
    }

    public function remove(string $hash, callable $now): bool
    {
        return $this->take($hash, $now) !== null;
    }

    /** CAS makes one concurrent taker win without deleting a newer value. */
    public function take(string $hash, callable $now): ?CacheEntry
    {
        $key = $this->key($hash);
        for ($attempt = 0; $attempt < self::RETRIES; $attempt++) {
            $record = $this->client->read($key);
            if ($record === null) return null;
            $entry = $this->decode($record->value, $now());
            if ($entry === null) return null;
            if ($this->client->cas($record->token, $key, self::TOMBSTONE, 1)) return $entry;
        }
        throw new CacheException('Memcached Cache entry changed too often.');
    }

    /** One producer may run per contender; the first successful publication wins. */
    public function remember(string $hash, callable $now, callable $producer): CacheResolution
    {
        $key = $this->key($hash);
        $record = $this->client->read($key);
        $current = $record === null ? null : $this->decode($record->value, $now());
        if ($current !== null) return new CacheResolution($current, false);
        $entry = $producer();
        if (!$entry instanceof CacheEntry) throw new CacheException('Cache producer returned an invalid entry.');
        $payload = $this->encode($entry);
        for ($attempt = 0; $attempt < self::RETRIES; $attempt++) {
            $expiration = $this->expiration($entry);
            if ($entry->expiresAt !== null && $expiration === 0) {
                return new CacheResolution($entry, true);
            }
            $published = $record === null
                ? $this->client->add($key, $payload, $expiration)
                : $this->client->cas($record->token, $key, $payload, $expiration);
            if ($published) return new CacheResolution($entry, true);
            $record = $this->client->read($key);
            $current = $record === null ? null : $this->decode($record->value, $now());
            if ($current !== null) return new CacheResolution($current, false);
        }
        throw new CacheException('Memcached Cache entry changed too often.');
    }

    /** Increment only this namespace's generation; other data stays intact. */
    public function clear(): void
    {
        for ($attempt = 0; $attempt < self::RETRIES; $attempt++) {
            $this->generation();
            if ($this->client->increment($this->generationKey) !== null) return;
        }
        throw new CacheException('Memcached Cache namespace could not be reset.');
    }

    private function key(string $hash): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new CacheException('Invalid Cache fingerprint.');
        }
        return $this->prefix . $this->generation() . ':' . $hash;
    }

    private function generation(): int
    {
        for ($attempt = 0; $attempt < self::RETRIES; $attempt++) {
            $record = $this->client->read($this->generationKey);
            if ($record !== null) {
                $value = $record->value;
                if ((is_int($value) && $value > 0)
                    || (is_string($value) && ctype_digit($value) && $value !== '0'
                        && filter_var($value, FILTER_VALIDATE_INT) !== false)) {
                    return (int) $value;
                }
                throw new CacheException('Memcached Cache namespace is invalid.');
            }
            // A random starting point keeps old keys inaccessible if Memcached
            // evicts this metadata while retaining entries from an older era.
            $initial = random_int(1, 1_000_000_000_000_000);
            if ($this->client->add($this->generationKey, $initial, 0)) return $initial;
        }
        throw new CacheException('Memcached Cache namespace could not be initialized.');
    }

    private function expiration(CacheEntry $entry): int
    {
        if ($entry->expiresAt === null) return 0;
        $remaining = $entry->expiresAt - $this->clock->now()->getTimestamp();
        if ($remaining < 1) return 0;
        if ($remaining <= self::RELATIVE_EXPIRATION_LIMIT) return $remaining;
        if ($entry->expiresAt > self::MAX_ABSOLUTE_EXPIRATION) {
            throw new CacheException('Memcached Cache expiry exceeds the supported range.');
        }
        return $entry->expiresAt;
    }

    private function encode(CacheEntry $entry): string
    {
        CacheValue::validate($entry->value);
        $payload = serialize(['version' => 1, 'expires_at' => $entry->expiresAt,
            'value' => serialize($entry->value)]);
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new CacheException('Memcached Cache payload exceeds the supported size.');
        }
        return $payload;
    }

    /** Invalid or foreign records are misses and cannot instantiate PHP objects. */
    private function decode(string|int $payload, int $now): ?CacheEntry
    {
        if (!is_string($payload) || $payload === self::TOMBSTONE
            || strlen($payload) > self::MAX_PAYLOAD_BYTES) return null;
        try {
            $record = @unserialize($payload, ['allowed_classes' => false]);
            if (!is_array($record) || ($record['version'] ?? null) !== 1
                || !array_key_exists('expires_at', $record)
                || !is_null($record['expires_at']) && !is_int($record['expires_at'])
                || !isset($record['value']) || !is_string($record['value'])) return null;
            $value = @unserialize($record['value'], ['allowed_classes' => false]);
            if ($value === false && $record['value'] !== 'b:0;') return null;
            CacheValue::validate($value);
            $entry = new CacheEntry($value, $record['expires_at']);
            return $entry->expired($now) ? null : $entry;
        } catch (Throwable) {
            return null;
        }
    }
}
