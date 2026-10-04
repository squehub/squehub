<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the named rate-limit route middleware. */
class_alias(\App\RateLimit\Middleware\RateLimitRequests::class, __NAMESPACE__ . '\\RateLimitRequests');
