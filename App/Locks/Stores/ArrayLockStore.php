<?php

declare(strict_types=1);

namespace App\Locks\Stores;

use App\Locks\LockOwnershipException;
use App\Locks\LockStore;

/** Per-Application memory only; useful for tests and local coordination. */
final class ArrayLockStore implements LockStore
{
    /** @var array<string,array{token:string,expires:int}> */
    private array $leases = [];

    public function acquire(string $hash, string $token, int $now, int $expiresAt): bool
    {
        $current = $this->leases[$hash] ?? null;
        if ($current !== null && $current['expires'] > $now) return false;
        $this->leases[$hash] = ['token' => $token, 'expires' => $expiresAt];
        return true;
    }

    public function release(string $hash, string $token, int $now): bool
    {
        $current = $this->leases[$hash] ?? null;
        if ($current === null) return false;
        if ($current['expires'] <= $now) {
            unset($this->leases[$hash]);
            return false;
        }
        if (!hash_equals($current['token'], $token)) {
            throw new LockOwnershipException('Lock belongs to another owner.');
        }
        unset($this->leases[$hash]);
        return true;
    }
}
