<?php

declare(strict_types=1);

/** File cache uses Storage/Cache by default; a prefix explicitly shares a namespace. */
/** @var \App\Foundation\Environment $environment */
return [
    'driver' => $environment->get('CACHE_DRIVER', 'file'),
    'prefix' => $environment->get('CACHE_PREFIX'),
    'path' => $environment->get('CACHE_PATH'),
    'redis_connection' => $environment->get('CACHE_REDIS_CONNECTION'),
    'memcached' => [
        'host' => $environment->get('CACHE_MEMCACHED_HOST', '127.0.0.1'),
        'port' => $environment->get('CACHE_MEMCACHED_PORT', '11211'),
        'timeout_ms' => $environment->get('CACHE_MEMCACHED_TIMEOUT_MS', '1000'),
    ],
];
