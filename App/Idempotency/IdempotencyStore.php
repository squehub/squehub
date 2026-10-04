<?php

declare(strict_types=1);

namespace App\Idempotency;

/** Atomic claim and owner-conditional terminal transitions in one backend. */
interface IdempotencyStore
{
    public function claim(string $scope, string $fingerprint, string $owner, int $now,
        int $leaseSeconds, int $retentionSeconds): ClaimResult;

    public function complete(string $scope, string $owner, int $now, ?ResponseSnapshot $snapshot): bool;

    public function abandon(string $scope, string $owner): bool;

    /** Reclaim at most $limit expired records without touching live claims. */
    public function prune(int $now, int $limit = 100): int;
}
