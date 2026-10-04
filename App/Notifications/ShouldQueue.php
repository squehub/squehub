<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Opts a Notification into Queue delivery using explicit JSON-safe state.
 * Payloads are persisted sensitive data, never arbitrary serialized objects.
 */
interface ShouldQueue
{
    /** @return array<string|int, mixed> */
    public function toQueuePayload(): array;

    /** @param array<string|int, mixed> $payload */
    public static function fromQueuePayload(array $payload): static;
}
