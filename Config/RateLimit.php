<?php

declare(strict_types=1);

/** Local file counters persist between PHP requests; tests may select array. */
/** @var \App\Foundation\Environment $environment */
return [
    // DRIVER is canonical; STORE remains accepted for existing applications.
    'driver' => $environment->get('RATE_LIMIT_DRIVER',
        $environment->get('RATE_LIMIT_STORE', 'file')),
    'prefix' => $environment->get('RATE_LIMIT_PREFIX', 'squehub'),
    'path' => $environment->get('RATE_LIMIT_PATH'),
    'redis_connection' => $environment->get('RATE_LIMIT_REDIS_CONNECTION'),
];
