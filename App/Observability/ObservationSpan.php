<?php

declare(strict_types=1);

namespace App\Observability;

/** One bounded completed operation; attributes have passed the framework allowlist. */
final readonly class ObservationSpan
{
    /** @param array<string, string|int|bool> $attributes */
    public function __construct(
        public string $id,
        public ?string $parentId,
        public string $operation,
        public float $offsetMs,
        public float $durationMs,
        public bool $failed,
        public array $attributes
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parentId,
            'operation' => $this->operation,
            'offset_ms' => $this->offsetMs,
            'duration_ms' => $this->durationMs,
            'failed' => $this->failed,
            'attributes' => $this->attributes,
        ];
    }
}
