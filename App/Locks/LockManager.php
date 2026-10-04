<?php

declare(strict_types=1);

namespace App\Locks;

use App\Database\ModelClock;
use App\Redis\InfrastructureSelection;
use Throwable;

/** Application-owned lock API; every acquisition is a bounded lease. */
final class LockManager
{
    public const MAX_TTL = 86400;
    public const MAX_WAIT = 60.0;

    public function __construct(
        private LockStore $store,
        private string $namespace,
        private ModelClock $clock,
        private string $backend,
        private bool $distributed = false,
        private ?InfrastructureSelection $selection = null
    ) {
    }

    public function backend(): string { return $this->backend; }
    public function distributed(): bool { return $this->distributed; }
    public function infrastructure(): ?InfrastructureSelection { return $this->selection; }

    public function acquire(string $name, int $ttl = 30, float $wait = 0.0): LockHandle
    {
        self::validateName($name);
        if ($ttl < 1 || $ttl > self::MAX_TTL) {
            throw new LockConfigurationException('Lock TTL must be between 1 and 86400 seconds.');
        }
        if (!is_finite($wait) || $wait < 0 || $wait > self::MAX_WAIT) {
            throw new LockConfigurationException('Lock wait must be between 0 and 60 seconds.');
        }
        $hash = hash('sha256', "squehub-lock-v1\0" . $this->namespace . "\0" . $name);
        $deadline = hrtime(true) + (int) ceil($wait * 1_000_000_000);
        do {
            $now = $this->clock->now()->getTimestamp();
            if ($now > PHP_INT_MAX - $ttl) {
                throw new LockConfigurationException('Lock expiry is out of range.');
            }
            try {
                $token = bin2hex(random_bytes(32));
            } catch (Throwable) {
                throw new LockBackendException('Lock owner token could not be generated.');
            }
            $expiry = $now + $ttl;
            if ($this->store->acquire($hash, $token, $now, $expiry)) {
                return new LockHandle($this->store, $this->clock, $hash, $token, $expiry);
            }
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) break;
            // The monotonic deadline is authoritative even if the wall clock
            // changes. A signal can shorten this sleep but cannot extend it.
            usleep((int) min(25_000, max(1, intdiv($remaining, 1000))));
        } while (true);
        return new LockHandle($this->store, $this->clock, $hash, null, null);
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function run(string $name, callable $callback, int $ttl = 30, float $wait = 0.0): mixed
    {
        $handle = $this->acquire($name, $ttl, $wait);
        if (!$handle->acquired()) throw new LockBusyException('Lock is already held.');
        try {
            $result = $callback();
        } catch (Throwable $failure) {
            try {
                $handle->release();
            } catch (Throwable) {
                // A release failure cannot safely turn a failed mutation into
                // a different failure. The bounded lease still expires.
            }
            throw $failure;
        }
        $handle->release();
        return $result;
    }

    private static function validateName(string $name): void
    {
        if (strlen($name) < 1 || strlen($name) > 200
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9:._-]*\z/D', $name) !== 1) {
            throw new LockConfigurationException('Lock name must be 1–200 safe ASCII bytes.');
        }
    }
}
