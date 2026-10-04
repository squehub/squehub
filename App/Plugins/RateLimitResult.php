<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\RateLimit\RateLimitResult; no wrapper state or conversion. */
class_alias(\App\RateLimit\RateLimitResult::class, __NAMESPACE__ . '\\RateLimitResult');
