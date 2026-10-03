<?php

declare(strict_types=1);

namespace App\Observability;

use InvalidArgumentException;

/** Bounded in-process exporter for deterministic tests and local inspection. */
final class ArrayObservationExporter implements ObservationExporter
{
    /** @var list<ObservationReport> */
    private array $reports = [];
    /** @var list<array<string, array{count:int,time_ms:float}>> */
    private array $metricBatches = [];

    public function __construct(private int $maxReports = 32)
    {
        if ($maxReports < 1 || $maxReports > 256) {
            throw new InvalidArgumentException('Observation exporter capacity is invalid.');
        }
    }

    public function exportTrace(ObservationReport $report): void
    {
        $this->reports[] = $report;
        if (count($this->reports) > $this->maxReports) array_shift($this->reports);
    }

    public function exportMetrics(array $metrics): void
    {
        $this->metricBatches[] = $metrics;
        if (count($this->metricBatches) > $this->maxReports) array_shift($this->metricBatches);
    }

    /** @return list<ObservationReport> */
    public function reports(): array { return $this->reports; }

    /** @return list<array<string, array{count:int,time_ms:float}>> */
    public function metricBatches(): array { return $this->metricBatches; }
}
