<?php

declare(strict_types=1);

namespace App\Events;

/** Immutable registration; class listeners remain unresolved until emission. */
final readonly class EventSubscription
{
    /** @param class-string|callable $listener */
    public function __construct(
        public string $eventType,
        public mixed $listener,
        public int $priority,
        public int $order,
        public bool $queued = false,
        public bool $afterCommit = true,
        public string $queue = 'default',
        public ?string $connection = null,
        public int $delay = 0,
        public ?string $transactionConnection = null
    ) {
    }
}
