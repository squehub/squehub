<?php

declare(strict_types=1);

/**
 * Named SqueHub webhook peers are opt-in. Secrets belong in environment
 * configuration; the database receipt table is installed explicitly.
 *
 * @var \App\Foundation\Environment $environment
 */
return [
    'endpoints' => [],
    'sources' => [],
    'receipt_store' => 'database',
    'receipt_connection' => null,
    'delivery_store' => 'database',
    'delivery_connection' => null,
    'receipt_retention_days' => 30,
    'delivery_retention_days' => 30,
    'receipt_lease_seconds' => 300,
    'timestamp_tolerance' => 300,
    'max_body_bytes' => 32768,
];
