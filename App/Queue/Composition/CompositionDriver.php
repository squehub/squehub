<?php

declare(strict_types=1);

namespace App\Queue\Composition;

use App\Queue\PersistentQueueDriver;
use App\Queue\ReservedJob;

/**
 * Driver-owned composition state shares the queue's settlement boundary.
 * A backend must not advance a chain after merely executing a job: the
 * reservation-token settlement is what makes that transition idempotent.
 */
interface CompositionDriver extends PersistentQueueDriver
{
    /** @param list<string> $encodedJobs QueueCodec envelopes */
    public function createComposition(string $id, string $kind, array $encodedJobs,
        string $queue, int $retentionHours): void;

    public function compositionStatus(string $id): ?CompositionStatus;

    public function cancelComposition(string $id): bool;

    /** Remove terminal metadata only; active work must never be pruned. */
    public function pruneCompositions(int $hours): int;

    /** A cancelled reservation is settled without executing its payload. */
    public function shouldRunComposition(ReservedJob $job): bool;

    /** Count a skipped, still-owned reservation as cancelled exactly once. */
    public function skipComposition(ReservedJob $job): void;
}
