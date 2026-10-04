<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Opts one application value into an explicit, durable data projection.
 * Registration supplies its stable external name; neither this contract nor
 * Queue persistence inspects object properties or serializes PHP objects.
 */
interface QueuePayloadData
{
    /** @return array<string, mixed> Only fields deliberately chosen for persistence. */
    public function toQueueData(): array;

    /** @param array<string, mixed> $data */
    public static function fromQueueData(array $data): static;
}
