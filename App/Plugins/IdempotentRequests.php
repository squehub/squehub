<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for opt-in authenticated HTTP idempotency middleware. */
class_alias(\App\Idempotency\Middleware\IdempotentRequests::class,
    __NAMESPACE__ . '\\IdempotentRequests');
