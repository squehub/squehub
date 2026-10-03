<?php

declare(strict_types=1);

namespace App\Locks;

/** @internal Physical keys are already hashed by LockManager. */
interface LockStore
{
    public function acquire(string $hash, string $token, int $now, int $expiresAt): bool;

    /** True only if this owner still held a live lease and removed it. */
    public function release(string $hash, string $token, int $now): bool;
}
