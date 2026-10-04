<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the explicit circuit failure and timing policy. */
class_alias(\App\Reliability\CircuitPolicy::class, __NAMESPACE__ . '\\CircuitPolicy');
