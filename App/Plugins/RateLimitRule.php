<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\RateLimit\RateLimitRule; no wrapper state or conversion. */
class_alias(\App\RateLimit\RateLimitRule::class, __NAMESPACE__ . '\\RateLimitRule');
