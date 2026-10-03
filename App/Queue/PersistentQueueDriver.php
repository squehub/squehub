<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * The persistent worker contract separates claiming from settling a job.
 * Claims must be atomic, and every settlement must verify the reservation
 * token so a timed-out worker cannot alter a job another worker reclaimed.
 */
interface PersistentQueueDriver extends QueueDriver
{
    public function reserve(string $queue): ?ReservedJob;
    public function acknowledge(ReservedJob $job): void;
    public function release(ReservedJob $job, int $backoff): void;
    public function fail(ReservedJob $job, string $jobClass, string $type, string $reason): void;
}
