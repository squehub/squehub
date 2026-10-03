<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Internal opt-in for jobs that must know whether Queue owns another attempt.
 * The context comes from a worker reservation, never from the JSON payload.
 */
interface QueueAttemptContextAware
{
    public function setQueueAttemptContext(int $attempt, int $maxAttempts): void;
}
