<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Bounded operational counts for one logical queue. Failed jobs are counted
 * across the connection because existing failed-job indexes are not per queue.
 * A reserved count describes active leases, not proof that a worker is alive.
 */
final class QueueStatus
{
    public function __construct(
        public readonly int $ready,
        public readonly int $delayed,
        public readonly int $reserved,
        public readonly int $failed
    ) {
        if (min($ready, $delayed, $reserved, $failed) < 0) {
            throw new QueueException('Queue status counts are invalid.');
        }
    }
}
