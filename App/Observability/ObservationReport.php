<?php

declare(strict_types=1);

namespace App\Observability;

/** A completed, size-limited execution scope shared by local consumers. */
final readonly class ObservationReport
{
    /**
     * @param list<ObservationSpan> $spans
     * @param array<string, array{count:int,time_ms:float}> $metrics
     */
    public function __construct(
        public string $traceId,
        public ?string $correlationId,
        public string $operation,
        public bool $sampled,
        public array $spans,
        public array $metrics,
        public int $droppedSpans
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'correlation_id' => $this->correlationId,
            'operation' => $this->operation,
            'sampled' => $this->sampled,
            'spans' => array_map(static fn (ObservationSpan $span): array => $span->toArray(), $this->spans),
            'metrics' => $this->metrics,
            'dropped_spans' => $this->droppedSpans,
        ];
    }
}
