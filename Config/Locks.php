<?php

declare(strict_types=1);

/** File is the local default; choose Redis/MySQL explicitly for distributed leases. */
/** @var \App\Foundation\Environment $environment */
return [
    'driver' => $environment->get('LOCK_DRIVER', 'file'),
    'prefix' => $environment->get('LOCK_PREFIX'),
    'path' => $environment->get('LOCK_PATH'),
    'redis_connection' => $environment->get('LOCK_REDIS_CONNECTION'),
    'database_connection' => $environment->get('LOCK_DATABASE_CONNECTION'),
    'require_distributed' => $environment->boolean('LOCK_REQUIRE_DISTRIBUTED', false),
];
