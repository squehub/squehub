<?php

declare(strict_types=1);

namespace App\Observability;

/** Optional adapter boundary; no transport or telemetry protocol is implied. */
interface ObservationExporter
{
    public function exportTrace(ObservationReport $report): void;

    /** @param array<string, array{count:int,time_ms:float}> $metrics */
    public function exportMetrics(array $metrics): void;
}
