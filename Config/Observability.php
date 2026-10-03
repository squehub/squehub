<?php

declare(strict_types=1);

/** Optional in-process observation; no collector or telemetry dependency is required. */
return [
    'enabled' => false,
    'sampling' => 'off', // off, all, or ratio
    'ratio' => 0.0,
    'max_spans' => 256,
    'max_metrics' => 64,
    'exporter' => 'none', // none or bounded in-process array
];
