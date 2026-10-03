<?php

declare(strict_types=1);

namespace App\Broadcasting;

/**
 * An application deliberately chooses its external name, recipients, and
 * public data. Internal Events are never broadcast merely because emitted.
 */
interface BroadcastEvent
{
    public function broadcastName(): string;

    /** @return list<Channel> */
    public function broadcastChannels(): array;

    /** @return array<string|int, mixed> */
    public function broadcastPayload(): array;
}
