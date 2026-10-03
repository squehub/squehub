<?php

declare(strict_types=1);

namespace App\Queue\Composition;

use App\Queue\QueueException;

/** Immutable, payload-free snapshot of one chain or batch. */
final class CompositionStatus
{
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $state,
        public readonly int $total,
        public readonly int $succeeded,
        public readonly int $failed,
        public readonly int $cancelled
    ) {
        if (!in_array($kind, ['chain', 'batch'], true)
            || !in_array($state, ['active', 'cancelling', 'completed',
                'completed_with_failures', 'failed', 'cancelled'], true)
            || $total < 1 || min($succeeded, $failed, $cancelled) < 0
            || $succeeded + $failed + $cancelled > $total) {
            throw new QueueException('Queue composition status is invalid.');
        }
    }

    /** Includes queued and reserved work; lease state is not a reliable running count. */
    public function pending(): int
    {
        return $this->total - $this->succeeded - $this->failed - $this->cancelled;
    }

    public function finished(): bool
    {
        return in_array($this->state,
            ['completed', 'completed_with_failures', 'failed', 'cancelled'], true);
    }

    /** Terminal Queue outcomes measure progress, not business-operation success. */
    public function progress(): int
    {
        return intdiv(100 * ($this->total - $this->pending()), $this->total);
    }
}
