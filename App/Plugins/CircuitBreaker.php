<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the Application-owned, opt-in circuit breaker manager. */
class_alias(\App\Reliability\CircuitBreaker::class, __NAMESPACE__ . '\\CircuitBreaker');
