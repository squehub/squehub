<?php

declare(strict_types=1);

namespace App\Webhooks\Receipts;

/**
 * Atomically owns one incoming event while application processing runs.
 * A null claim is a duplicate; callers must acknowledge or fail a claimed
 * event with the opaque token returned by claim().
 */
interface ReceiptStore
{
    public function claim(string $source, string $eventId, int $now, int $leaseSeconds): ?string;

    public function processed(string $source, string $eventId, string $claimToken, int $now): void;

    public function failed(string $source, string $eventId, string $claimToken, int $now): void;

    /** Remove only terminal receipts last changed before the cutoff. */
    public function prune(int $before): int;
}
