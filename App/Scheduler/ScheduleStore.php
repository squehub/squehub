<?php

declare(strict_types=1);

namespace App\Scheduler;

use DateTimeImmutable;

/**
 * Atomically records occurrences and, separately, guards synchronous overlap.
 * A claimed occurrence is never reclaimed, even after a process crash; an
 * overlap lock expires so a later occurrence can recover from a dead runner.
 */
interface ScheduleStore
{
    public function claim(string $taskKey, string $occurrence, DateTimeImmutable $now): bool;

    public function finish(string $taskKey, string $occurrence, string $status, DateTimeImmutable $now): void;

    public function acquireOverlap(string $taskKey, DateTimeImmutable $now, int $seconds): ?string;

    public function releaseOverlap(string $taskKey, string $token): void;
}
