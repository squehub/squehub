<?php

declare(strict_types=1);

namespace App\Observability;

use App\Support\SensitiveKey;
use InvalidArgumentException;
use Throwable;

/**
 * Application-owned observation state. Framework hooks submit only safe,
 * bounded metadata; an exporter never receives SQL bindings or request data.
 * Metrics are independent of trace sampling and have no dynamic labels.
 */
final class ObservabilityManager
{
    private const ATTRIBUTE_KEYS = [
        'method', 'route_pattern', 'route_name', 'status', 'status_class',
        'controller', 'middleware', 'connection', 'driver', 'operation',
        'queue', 'attempt', 'event_type', 'listener_type', 'channel_category',
        'transport', 'host', 'result', 'mode', 'task', 'store', 'job_class',
    ];

    private bool $enabled;
    private bool $exportEnabled;
    private string $sampling;
    private float $ratio;
    private int $maxSpans;
    private int $maxMetrics;
    private ?ObservationExporter $exporter;
    /** @var list<ObservationConsumer> */
    private array $consumers = [];
    /** @var list<array{token:int,id:?string,parent:?string,operation:string,start:int,attributes:array<string,string|int|bool>}> */
    private array $frames = [];
    /** @var list<ObservationSpan> */
    private array $spans = [];
    /** @var array<string, array{count:int,time_ms:float}> */
    private array $metrics = [];
    /** @var array<string, array{count:int,time_ms:float}> */
    private array $scopeMetrics = [];
    private ?string $traceId = null;
    private ?string $correlationId = null;
    private ?int $scopeStarted = null;
    private bool $sampled = false;
    private bool $capture = false;
    private int $nextToken = 0;
    private int $droppedSpans = 0;
    private int $droppedMetrics = 0;
    private int $exportFailures = 0;
    private int $consumerFailures = 0;
    private ?string $lastFailureCategory = null;
    private bool $delivering = false;

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings = [], ?ObservationExporter $exporter = null)
    {
        if (array_diff(array_keys($settings),
            ['enabled', 'sampling', 'ratio', 'max_spans', 'max_metrics', 'exporter']) !== []) {
            throw new InvalidArgumentException('Observability configuration has an unknown setting.');
        }
        $settings += ['enabled' => false, 'sampling' => 'off', 'ratio' => 0.0,
            'max_spans' => 256, 'max_metrics' => 64, 'exporter' => 'none'];
        if (!is_bool($settings['enabled']) || !is_string($settings['sampling'])
            || !in_array($settings['sampling'], ['off', 'all', 'ratio'], true)
            || (!is_float($settings['ratio']) && !is_int($settings['ratio']))
            || !is_finite((float) $settings['ratio']) || $settings['ratio'] < 0 || $settings['ratio'] > 1
            || !is_int($settings['max_spans']) || $settings['max_spans'] < 8 || $settings['max_spans'] > 2048
            || !is_int($settings['max_metrics']) || $settings['max_metrics'] < 1 || $settings['max_metrics'] > 128
            || !in_array($settings['exporter'], ['none', 'array'], true)) {
            throw new InvalidArgumentException('Observability configuration is invalid.');
        }
        $this->enabled = $settings['enabled'];
        $this->exportEnabled = $settings['enabled'];
        $this->sampling = $settings['sampling'];
        $this->ratio = (float) $settings['ratio'];
        $this->maxSpans = $settings['max_spans'];
        $this->maxMetrics = $settings['max_metrics'];
        $this->exporter = $exporter ?? ($settings['exporter'] === 'array' ? new ArrayObservationExporter() : null);
    }

    public function enabled(): bool { return $this->enabled; }

    /** Deliberate inspection hook for an in-process test exporter. */
    public function exporter(): ?ObservationExporter { return $this->exporter; }

    /** Add only registered route metadata to the root, never matched values. */
    public function annotateRoot(array $attributes): void
    {
        if (!$this->enabled || $this->frames === []) return;
        $this->frames[0]['attributes'] = [
            ...$this->frames[0]['attributes'], ...self::safeAttributes($attributes),
        ];
    }

    /** Local consumers, such as the later profiler, bypass external trace sampling. */
    public function subscribe(ObservationConsumer $consumer): void
    {
        $this->consumers[] = $consumer;
    }

    /**
     * A development-only local consumer may capture the existing observation
     * stream even when external telemetry is disabled. The caller owns the
     * environment policy; activation is allowed only before a root begins.
     *
     * @internal
     */
    public function activateLocalConsumer(ObservationConsumer $consumer): void
    {
        if ($this->frames !== []) {
            throw new \LogicException('Local observation capture must be activated before execution.');
        }
        $this->consumers[] = $consumer;
        $this->enabled = true;
    }

    /** @param array<string, mixed> $attributes */
    public function begin(string $operation, array $attributes = [], ?string $correlationId = null): ?ObservationScope
    {
        if (!$this->enabled) return null;
        self::operation($operation);
        if ($this->frames === []) {
            if ($correlationId !== null && preg_match('/\A[a-f0-9]{32}\z/D', $correlationId) !== 1) {
                throw new InvalidArgumentException('Observation correlation ID is invalid.');
            }
            // Correlation belongs to the logical operation. Trace IDs have a
            // separate sampling and parent/span lifecycle.
            $this->correlationId = $correlationId;
            $this->traceId = bin2hex(random_bytes(16));
            $this->scopeStarted = hrtime(true);
            $this->scopeMetrics = [];
            $this->spans = [];
            $this->droppedSpans = 0;
            $this->sampled = $this->sample($this->traceId);
            $this->capture = $this->sampled || $this->consumers !== [];
        }
        $parent = $this->frames === [] ? null : $this->frames[array_key_last($this->frames)]['id'];
        $id = $this->capture ? bin2hex(random_bytes(8)) : null;
        $token = ++$this->nextToken;
        $this->frames[] = ['token' => $token, 'id' => $id, 'parent' => $parent,
            'operation' => $operation, 'start' => hrtime(true),
            'attributes' => self::safeAttributes($attributes)];
        return new ObservationScope($this, $token);
    }

    /** Preserve the callback's result or exception; observation never replaces it. */
    public function span(string $operation, callable $callback, array $attributes = []): mixed
    {
        if (!$this->enabled) return $callback();
        try { $scope = $this->begin($operation, $attributes); }
        catch (Throwable) { $scope = null; }
        try {
            $result = $callback();
            try { $scope?->finish(); } catch (Throwable) {}
            return $result;
        } catch (Throwable $failure) {
            try { $scope?->finish(failed: true); } catch (Throwable) {}
            throw $failure;
        }
    }

    /**
     * Existing subsystem timing boundaries call this after an operation.
     * No body, SQL, key, header, payload, or arbitrary object is accepted.
     *
     * @param array<string, mixed> $attributes
     */
    public function record(string $operation, float $milliseconds, bool $failed = false,
        array $attributes = []): void
    {
        if (!$this->enabled) return;
        self::operation($operation);
        $duration = is_finite($milliseconds) ? max(0.0, $milliseconds) : 0.0;
        $this->count($operation, $duration, $failed);
        if (!$this->capture || $this->scopeStarted === null) return;
        $now = hrtime(true);
        $parent = $this->frames === [] ? null : $this->frames[array_key_last($this->frames)]['id'];
        $offset = max(0.0, ($now - $this->scopeStarted) / 1_000_000 - $duration);
        $this->append(new ObservationSpan(bin2hex(random_bytes(8)), $parent,
            $operation, $offset, $duration, $failed, self::safeAttributes($attributes)));
    }

    /** @internal Finish only the current frame; a mismatched caller is discarded safely. */
    public function finish(int $token, array $attributes = [], bool $failed = false): void
    {
        if (!$this->enabled || $this->frames === []) return;
        $frame = $this->frames[array_key_last($this->frames)];
        if ($frame['token'] !== $token) {
            $this->clearScope();
            return;
        }
        array_pop($this->frames);
        $now = hrtime(true);
        $duration = max(0.0, ($now - $frame['start']) / 1_000_000);
        $this->count($frame['operation'], $duration, $failed);
        if ($this->capture && $frame['id'] !== null && $this->scopeStarted !== null) {
            $offset = max(0.0, ($frame['start'] - $this->scopeStarted) / 1_000_000);
            $this->append(new ObservationSpan($frame['id'], $frame['parent'], $frame['operation'],
                $offset, $duration, $failed,
                [...$frame['attributes'], ...self::safeAttributes($attributes)]), $this->frames === []);
        }
        if ($this->frames !== []) return;
        $completedMetrics = $this->scopeMetrics;
        $sampled = $this->sampled;
        $report = new ObservationReport((string) $this->traceId, $this->correlationId,
            $frame['operation'], $sampled,
            $this->orderedSpans(), $completedMetrics, $this->droppedSpans);
        // Completed state must be detached before consumer/exporter callbacks.
        // A local profiler may itself use instrumented storage or start a new
        // scope; those operations cannot mutate this immutable report.
        $this->clearScope();
        if ($this->delivering) return;
        $this->delivering = true;
        try {
            foreach ($this->consumers as $consumer) {
                try { $consumer->accept($report); }
                catch (Throwable $failure) { $this->failure($failure, false); }
            }
            if ($this->exportEnabled && $this->exporter !== null) {
                // Metrics are exported even when this root trace was not sampled.
                try { $this->exporter->exportMetrics($completedMetrics); }
                catch (Throwable $failure) { $this->failure($failure, true); }
                if ($sampled) {
                    try { $this->exporter->exportTrace($report); }
                    catch (Throwable $failure) { $this->failure($failure, true); }
                }
            }
        } finally {
            $this->delivering = false;
        }
    }

    /** @return array<string, array{count:int,time_ms:float}> */
    public function metrics(): array { return $this->metrics; }

    /** Operational status contains no exporter credentials or raw failure messages. */
    public function status(): array
    {
        return ['configured' => true, 'enabled' => $this->enabled,
            'export_enabled' => $this->exportEnabled,
            'exporter' => $this->exporter === null ? 'none' : $this->exporter::class,
            'last_failure_category' => $this->lastFailureCategory,
            'export_failures' => $this->exportFailures,
            'consumer_failures' => $this->consumerFailures,
            'dropped_metrics' => $this->droppedMetrics];
    }

    /** @return list<ObservationSpan> */
    private function orderedSpans(): array
    {
        $children = [];
        foreach ($this->spans as $span) {
            $children[$span->parentId ?? ''][] = $span;
        }
        foreach ($children as &$siblings) {
            usort($siblings, static fn (ObservationSpan $left, ObservationSpan $right): int =>
                $left->offsetMs <=> $right->offsetMs);
        }
        unset($siblings);
        $ordered = [];
        $seen = [];
        $append = static function (string $parent) use (&$append, &$ordered, &$seen, $children): void {
            foreach ($children[$parent] ?? [] as $span) {
                if (isset($seen[$span->id])) continue;
                $seen[$span->id] = true;
                $ordered[] = $span;
                $append($span->id);
            }
        };
        $append('');
        // If a parent was dropped at the configured cap, retain the surviving
        // child's safe data rather than manufacturing a false ancestry.
        foreach ($this->spans as $span) {
            if (!isset($seen[$span->id])) {
                $ordered[] = $span;
            }
        }
        return $ordered;
    }

    private function append(ObservationSpan $span, bool $root = false): void
    {
        // Reserve the final slot for the root so truncation cannot hide the
        // operation that owns the trace.
        if (count($this->spans) >= $this->maxSpans - ($root ? 0 : 1)) {
            ++$this->droppedSpans;
            return;
        }
        $this->spans[] = $span;
    }

    private function count(string $operation, float $duration, bool $failed): void
    {
        $key = $operation . '.' . ($failed ? 'error' : 'ok');
        if (!isset($this->metrics[$key]) && count($this->metrics) >= $this->maxMetrics) {
            ++$this->droppedMetrics;
            return;
        }
        $this->metrics[$key] ??= ['count' => 0, 'time_ms' => 0.0];
        ++$this->metrics[$key]['count'];
        $this->metrics[$key]['time_ms'] += $duration;
        if ($this->scopeStarted !== null) {
            $this->scopeMetrics[$key] ??= ['count' => 0, 'time_ms' => 0.0];
            ++$this->scopeMetrics[$key]['count'];
            $this->scopeMetrics[$key]['time_ms'] += $duration;
        }
    }

    private function sample(string $traceId): bool
    {
        if ($this->sampling === 'all') return true;
        if ($this->sampling === 'off') return false;
        $number = hexdec(substr($traceId, 0, 8)) / 4294967296;
        return $number < $this->ratio;
    }

    private static function operation(string $operation): void
    {
        if (strlen($operation) > 64
            || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $operation) !== 1) {
            throw new InvalidArgumentException('Observation operation is invalid.');
        }
    }

    /** @param array<string, mixed> $attributes @return array<string, string|int|bool> */
    private static function safeAttributes(array $attributes): array
    {
        $safe = [];
        foreach ($attributes as $key => $value) {
            if (count($safe) >= 12) break;
            if (!is_string($key) || !in_array($key, self::ATTRIBUTE_KEYS, true)
                || SensitiveKey::matches($key)) continue;
            if (is_int($value) || is_bool($value)) { $safe[$key] = $value; continue; }
            if (!is_string($value) || $value === '' || strlen($value) > 128
                || preg_match('/[\x00-\x1f\x7f]/', $value)) continue;
            $safe[$key] = $value;
        }
        return $safe;
    }

    private function failure(Throwable $failure, bool $export): void
    {
        if ($export) ++$this->exportFailures;
        else ++$this->consumerFailures;
        $this->lastFailureCategory = $failure::class;
    }

    private function clearScope(): void
    {
        $this->frames = [];
        $this->spans = [];
        $this->scopeMetrics = [];
        $this->scopeStarted = null;
        $this->traceId = null;
        $this->correlationId = null;
        $this->sampled = false;
        $this->capture = false;
        $this->droppedSpans = 0;
    }
}
