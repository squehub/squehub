<?php

declare(strict_types=1);

namespace App\Session\Drivers;

use App\Redis\RedisConnection;
use App\Session\SessionException;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use Throwable;

/**
 * Persists PHP's existing session payload unchanged under a fingerprinted ID.
 * Redis TTL is renewed on writes and native lazy-write touches. A bounded
 * per-session lease preserves native request serialization for ordinary work;
 * there is no general lock facade or automatic merge of divergent data.
 */
final class RedisSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private const UNLOCK = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end";
    private string $prefix;
    private ?string $heldId = null;
    private ?string $token = null;

    public function __construct(private RedisConnection $redis, string $namespace, private int $lifetime,
        private int $lockWaitMilliseconds = 5000, private int $lockLeaseSeconds = 300)
    {
        if ($lifetime < 1 || $lockWaitMilliseconds < 1 || $lockLeaseSeconds < 1) {
            throw new SessionException('Invalid Redis session lifetime or lock policy.');
        }
        $this->prefix = 'session:' . hash('sha256', $namespace) . ':';
    }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool
    {
        $this->release();
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $this->acquire($id);
            return $this->redis->get($this->key($id)) ?? '';
        } catch (Throwable $failure) {
            try { $this->release(); } catch (Throwable) {}
            throw new SessionException('Redis session could not be read.', 0, $failure);
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            return $this->redis->set($this->key($id), $data, $this->lifetime);
        } catch (Throwable $failure) {
            throw new SessionException('Redis session could not be saved.', 0, $failure);
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->redis->delete($this->key($id));
            if ($this->heldId === $id) $this->release();
            return true;
        } catch (Throwable $failure) {
            throw new SessionException('Redis session could not be destroyed.', 0, $failure);
        }
    }

    /** Redis expires records itself; PHP garbage collection has no scan work. */
    public function gc(int $max_lifetime): int|false { return 0; }

    public function validateId(string $id): bool
    {
        try {
            return $this->redis->exists($this->key($id));
        } catch (Throwable $failure) {
            throw new SessionException('Redis session identity could not be validated.', 0, $failure);
        }
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        // PHP invokes this when session.lazy_write suppresses an unchanged
        // write. Refreshing TTL preserves native activity-based lifetime.
        return $this->write($id, $data);
    }

    /**
     * SET NX EX is atomic across cooperating Redis clients. A lease bounds
     * stale-lock lifetime after a crashed worker. Requests exceeding the lease
     * can overlap, so this is not an unbounded exactly-once guarantee.
     */
    private function acquire(string $id): void
    {
        if ($this->heldId === $id) return;
        if ($this->heldId !== null) $this->release();
        $token = bin2hex(random_bytes(16));
        $until = hrtime(true) + $this->lockWaitMilliseconds * 1_000_000;
        do {
            if ($this->redis->set($this->lockKey($id), $token,
                $this->lockLeaseSeconds, onlyIfMissing: true)) {
                $this->heldId = $id;
                $this->token = $token;
                return;
            }
            if (hrtime(true) >= $until) break;
            usleep(10_000);
        } while (true);
        throw new SessionException('Redis session lock could not be acquired.');
    }

    /** The compare-and-delete script cannot release a newer owner's lease. */
    private function release(): void
    {
        if ($this->heldId === null || $this->token === null) return;
        $id = $this->heldId;
        $token = $this->token;
        $this->heldId = null;
        $this->token = null;
        try {
            $reply = $this->redis->script(self::UNLOCK, [$this->lockKey($id)], [$token]);
            if ($reply !== 0 && $reply !== 1) {
                throw new SessionException('Redis session lock release failed.');
            }
        } catch (Throwable $failure) {
            throw $failure instanceof SessionException ? $failure
                : new SessionException('Redis session lock release failed.', 0, $failure);
        }
    }

    private function lockKey(string $id): string
    {
        return 'session_lock:' . substr($this->key($id), strlen('session:'));
    }

    private function key(string $id): string
    {
        if ($id === '' || strlen($id) > 256 || preg_match('/[^A-Za-z0-9,-]/', $id)) {
            throw new SessionException('Invalid session identity.');
        }
        return $this->prefix . hash('sha256', $id);
    }
}
