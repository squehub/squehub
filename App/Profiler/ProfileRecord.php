<?php

declare(strict_types=1);

namespace App\Profiler;

use App\Observability\ObservationReport;
use App\Observability\ObservationSpan;
use JsonException;

/**
 * Versioned, read-only projection of one completed observation. Re-reading a
 * local file validates every field before Studio or another inspector can see
 * it; no request, SQL, Queue, Mail, or Notification payload is represented.
 */
final readonly class ProfileRecord
{
    private const ATTRIBUTE_KEYS = [
        'method', 'route_pattern', 'route_name', 'status', 'status_class',
        'controller', 'middleware', 'connection', 'driver', 'operation',
        'queue', 'attempt', 'event_type', 'listener_type', 'channel_category',
        'transport', 'host', 'result', 'mode', 'task', 'store', 'job_class',
    ];

    /**
     * @param list<array<string, mixed>> $events
     * @param array<string, array{count:int,time_ms:float}> $metrics
     */
    public function __construct(
        public string $id,
        public int $recordedAt,
        public string $operation,
        public string $traceId,
        public ?string $correlationId,
        public float $durationMs,
        public array $events,
        public array $metrics,
        public int $droppedEvents,
        public int $droppedMetrics
    ) {
        self::identifier($id);
        if ($recordedAt < 1 || preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $operation) !== 1
            || preg_match('/\A[a-f0-9]{32}\z/D', $traceId) !== 1
            || ($correlationId !== null && preg_match('/\A[a-f0-9]{32}\z/D', $correlationId) !== 1)
            || !is_finite($durationMs) || $durationMs < 0
            || $droppedEvents < 0 || $droppedMetrics < 0) {
            throw new ProfilerException('Profile metadata is invalid.');
        }
        foreach ($events as $event) {
            if (!is_array($event)) throw new ProfilerException('Profile event is invalid.');
            self::event($event);
        }
        foreach ($metrics as $name => $metric) {
            if (!is_string($name) || preg_match('/\A[a-z][a-z0-9_.-]{0,70}\.(ok|error)\z/D', $name) !== 1
                || !is_array($metric)
                || !is_int($metric['count']) || $metric['count'] < 0
                || !is_float($metric['time_ms']) || !is_finite($metric['time_ms'])
                || $metric['time_ms'] < 0) {
                throw new ProfilerException('Profile metric is invalid.');
            }
        }
    }

    /**
     * Local capture receives the same bounded event stream as the observation
     * exporter, even when external trace sampling is off. The root is retained
     * while less important events and metrics are trimmed to the byte budget.
     */
    public static function capture(ObservationReport $report, int $now,
        ProfilerSettings $settings): self
    {
        $events = array_map(static fn (ObservationSpan $span): array => $span->toArray(),
            array_slice($report->spans, 0, $settings->maxEvents));
        $droppedEvents = $report->droppedSpans + max(0, count($report->spans) - count($events));
        $metrics = $report->metrics;
        $droppedMetrics = 0;
        $duration = $report->spans[0]->durationMs ?? 0.0;
        $id = bin2hex(random_bytes(16));

        while (true) {
            $profile = new self($id, $now, $report->operation, $report->traceId,
                $report->correlationId, $duration, $events, $metrics,
                $droppedEvents, $droppedMetrics);
            if ($profile->bytes() <= $settings->maxProfileBytes) return $profile;
            if (count($events) > 1) {
                array_pop($events);
                ++$droppedEvents;
                continue;
            }
            if ($metrics !== []) {
                array_pop($metrics);
                ++$droppedMetrics;
                continue;
            }
            throw new ProfilerException('Profile exceeds the configured byte limit.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => 1, 'id' => $this->id, 'recorded_at' => $this->recordedAt,
            'operation' => $this->operation, 'trace_id' => $this->traceId,
            'correlation_id' => $this->correlationId, 'duration_ms' => $this->durationMs,
            'events' => $this->events, 'metrics' => $this->metrics,
            'dropped_events' => $this->droppedEvents,
            'dropped_metrics' => $this->droppedMetrics,
        ];
    }

    /** Reject unknown fields so a mutable runtime record cannot expand the inspection surface. */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data) || array_keys($data) !== [
            'version', 'id', 'recorded_at', 'operation', 'trace_id',
            'correlation_id', 'duration_ms', 'events', 'metrics',
            'dropped_events', 'dropped_metrics',
        ] || $data['version'] !== 1 || !is_string($data['id'])
            || !is_int($data['recorded_at']) || !is_string($data['operation'])
            || !is_string($data['trace_id'])
            || ($data['correlation_id'] !== null && !is_string($data['correlation_id']))
            || !is_float($data['duration_ms']) || !is_array($data['events'])
            || !array_is_list($data['events']) || !is_array($data['metrics'])
            || !is_int($data['dropped_events']) || !is_int($data['dropped_metrics'])) {
            throw new ProfilerException('Profile record is corrupt.');
        }
        foreach ($data['metrics'] as $metric) {
            if (!is_array($metric) || array_keys($metric) !== ['count', 'time_ms']) {
                throw new ProfilerException('Profile record is corrupt.');
            }
        }
        return new self($data['id'], $data['recorded_at'], $data['operation'],
            $data['trace_id'], $data['correlation_id'], $data['duration_ms'],
            $data['events'], $data['metrics'], $data['dropped_events'],
            $data['dropped_metrics']);
    }

    public function bytes(): int
    {
        try { return strlen(json_encode($this->toArray(),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)); }
        catch (JsonException $failure) {
            throw new ProfilerException('Profile cannot be encoded.', 0, $failure);
        }
    }

    public static function identifier(string $id): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1) {
            throw new ProfilerException('Profile identifier is invalid.');
        }
    }

    /** @param array<string, mixed> $event */
    private static function event(array $event): void
    {
        if (array_keys($event) !== ['id', 'parent_id', 'operation', 'offset_ms',
            'duration_ms', 'failed', 'attributes']
            || !is_string($event['id'])
            || preg_match('/\A[a-f0-9]{16}\z/D', $event['id']) !== 1
            || ($event['parent_id'] !== null && (!is_string($event['parent_id'])
                || preg_match('/\A[a-f0-9]{16}\z/D', $event['parent_id']) !== 1))
            || !is_string($event['operation'])
            || preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $event['operation']) !== 1
            || !is_float($event['offset_ms']) || !is_finite($event['offset_ms'])
            || $event['offset_ms'] < 0 || !is_float($event['duration_ms'])
            || !is_finite($event['duration_ms']) || $event['duration_ms'] < 0
            || !is_bool($event['failed']) || !is_array($event['attributes'])
            || count($event['attributes']) > 12) {
            throw new ProfilerException('Profile event is invalid.');
        }
        foreach ($event['attributes'] as $name => $value) {
            if (!is_string($name) || !in_array($name, self::ATTRIBUTE_KEYS, true)
                || (!is_string($value) && !is_int($value) && !is_bool($value))
                || (is_string($value) && (strlen($value) > 128
                    || preg_match('/[\x00-\x1f\x7f]/', $value)))) {
                throw new ProfilerException('Profile event attribute is invalid.');
            }
        }
    }
}
