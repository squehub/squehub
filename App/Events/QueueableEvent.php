<?php

declare(strict_types=1);

namespace App\Events;

/**
 * An event explicitly defines the JSON-safe snapshot sent to queued listeners.
 * Persist scalar identities rather than entire Models or request objects; the
 * worker reconstructs this event in a different Application process.
 */
interface QueueableEvent
{
    /** @return array<string|int, mixed> */
    public function toQueuePayload(): array;

    /** @param array<string|int, mixed> $payload */
    public static function fromQueuePayload(array $payload): static;
}
