<?php

declare(strict_types=1);

namespace App\Idempotency\Stores;

use App\Idempotency\ClaimResult;
use App\Idempotency\IdempotencyStore;
use App\Idempotency\Record;
use App\Idempotency\ResponseSnapshot;

/** One-process test store. No state survives process exit or crosses workers. */
final class ArrayIdempotencyStore implements IdempotencyStore
{
    private array $records = [];

    public function claim(string $scope, string $fingerprint, string $owner, int $now,
        int $leaseSeconds, int $retentionSeconds): ClaimResult
    {
        Record::scope($scope);
        $fresh = Record::fresh($fingerprint, $owner, $now, $leaseSeconds, $retentionSeconds);
        $old = $this->records[$scope] ?? null;
        if ($old === null || $old['expires_at'] <= $now
            || ($old['state'] === 'processing' && $old['lease_until'] <= $now
                && hash_equals($old['fingerprint'], $fingerprint))) {
            $this->records[$scope] = $fresh;
            return new ClaimResult('claimed');
        }
        return ClaimResult::fromRecord($old, $fingerprint);
    }

    public function complete(string $scope, string $owner, int $now, ?ResponseSnapshot $snapshot): bool
    {
        Record::scope($scope);
        $old = $this->records[$scope] ?? null;
        if ($old === null || $old['state'] !== 'processing' || $old['owner'] !== $owner
            || $old['lease_until'] <= $now || $old['expires_at'] <= $now) return false;
        $old['state'] = 'complete';
        $old['owner'] = null;
        $old['lease_until'] = 0;
        $old['snapshot'] = $snapshot?->toArray();
        $this->records[$scope] = $old;
        return true;
    }

    public function abandon(string $scope, string $owner): bool
    {
        Record::scope($scope);
        $old = $this->records[$scope] ?? null;
        if ($old === null || $old['state'] !== 'processing' || $old['owner'] !== $owner) return false;
        unset($this->records[$scope]);
        return true;
    }

    public function prune(int $now, int $limit = 100): int
    {
        $removed = 0;
        foreach ($this->records as $scope => $record) {
            if ($removed >= $limit) break;
            if ($record['expires_at'] <= $now) {
                unset($this->records[$scope]);
                ++$removed;
            }
        }
        return $removed;
    }
}
