<?php

declare(strict_types=1);

/** Queue service settings; no database connection is opened at boot. */
/** @var \App\Foundation\Environment $environment */
$databaseConnection = $environment->get('QUEUE_DATABASE_CONNECTION');

return [
    'default' => $environment->get('QUEUE_CONNECTION', 'sync'),
    'composition' => [
        // One definition remains bounded even when every job reaches the
        // existing 60 KB QueueCodec limit. Terminal metadata has finite life.
        'max_jobs' => 100,
        'max_payload_bytes' => 1048576,
        'retention_hours' => 168,
    ],
    'connections' => [
        'sync' => ['driver' => 'sync'],
        'database' => [
            'driver' => 'database',
            // An empty optional setting selects the configured default Database connection.
            'database_connection' => $databaseConnection === '' ? null : $databaseConnection,
            'table' => 'queue_jobs',
            'failed_table' => 'queue_failed_jobs',
            'retry_after' => 60,
        ],
        'redis' => [
            'driver' => 'redis',
            'redis_connection' => $environment->get('QUEUE_REDIS_CONNECTION'),
            'namespace' => $environment->get('QUEUE_REDIS_NAMESPACE'),
            'retry_after' => 60,
        ],
        'auto' => [
            'driver' => 'auto',
            'redis_connection' => $environment->get('QUEUE_REDIS_CONNECTION'),
        ],
    ],
];
