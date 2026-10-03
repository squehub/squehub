<?php

declare(strict_types=1);

namespace App\Queue\Composition;

use App\Queue\QueueException;
use App\Queue\QueueManager;

/**
 * An opaque operation ID and its selected Queue connection. Persist both to
 * inspect a durable composition from another process; no job payload lives here.
 */
final class CompositionHandle
{
    public function __construct(
        public readonly string $id,
        public readonly string $connection,
        private readonly QueueManager $manager
    ) {
    }

    public function status(): CompositionStatus
    {
        return $this->manager->compositionStatus($this->id, $this->connection)
            ?? throw new QueueException('Queue composition is unavailable.');
    }

    public function cancel(): bool
    {
        return $this->manager->cancelComposition($this->id, $this->connection);
    }
}
