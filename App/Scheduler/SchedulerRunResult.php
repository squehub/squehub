<?php

declare(strict_types=1);

namespace App\Scheduler;

/** Aggregate outcome of one tick; no task identity or exception data is retained. */
final class SchedulerRunResult
{
    public function __construct(
        public readonly int $evaluated,
        public readonly int $due,
        public readonly int $executed,
        public readonly int $queued,
        public readonly int $skipped,
        public readonly int $failed
    ) {
    }

    public function successful(): bool { return $this->failed === 0; }
}
