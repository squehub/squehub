<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * An application job defines both its work and its durable representation.
 * Payload methods must use JSON-compatible values; reconstruction must not
 * depend on a PHP serialized object or a previous process's memory.
 */
interface QueueJob
{
    public function handle(): void;

    /** @return array<string|int, mixed> */
    public function toQueuePayload(): array;

    /** @param array<string|int, mixed> $payload */
    public static function fromQueuePayload(array $payload): static;
}
