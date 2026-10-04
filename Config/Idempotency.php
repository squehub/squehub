<?php

declare(strict_types=1);

/** Opt-in route middleware; default file state coordinates one local server. */
/** @var \App\Foundation\Environment $environment */
$optional = static fn (mixed $value): mixed => $value === '' ? null : $value;
return [
    'driver' => $environment->get('IDEMPOTENCY_DRIVER', 'file'),
    'namespace' => $optional($environment->get('IDEMPOTENCY_NAMESPACE')),
    'path' => $optional($environment->get('IDEMPOTENCY_PATH')),
    'database_connection' => $optional($environment->get('IDEMPOTENCY_DATABASE_CONNECTION')),
    'redis_connection' => $optional($environment->get('IDEMPOTENCY_REDIS_CONNECTION')),
    'require_shared' => $environment->boolean('IDEMPOTENCY_REQUIRE_SHARED', false),
    'lease_seconds' => $environment->get('IDEMPOTENCY_LEASE_SECONDS', 120),
    'retention_seconds' => $environment->get('IDEMPOTENCY_RETENTION_SECONDS', 86400),
    'max_request_bytes' => $environment->get('IDEMPOTENCY_MAX_REQUEST_BYTES', 1048576),
    'max_response_bytes' => $environment->get('IDEMPOTENCY_MAX_RESPONSE_BYTES', 32768),
];
