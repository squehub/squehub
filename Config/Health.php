<?php

declare(strict_types=1);

/** Health routes are absent unless explicitly enabled; readiness policy is configurable. */
/** @var \App\Foundation\Environment $environment */
return [
    'endpoints_enabled' => $environment->boolean('HEALTH_ENDPOINTS_ENABLED', false),
    'middleware' => [],
    'require_database' => true,
    'require_storage' => true,
    'require_queue' => false,
    'require_scheduler' => false,
    'require_crypt' => false,
    'require_http_client' => false,
];
