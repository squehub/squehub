<?php

declare(strict_types=1);

namespace App\Plugins;

/** Stable application import for the canonical bounded retry policy. */
class_alias(\App\Reliability\RetryPolicy::class, __NAMESPACE__ . '\\RetryPolicy');
