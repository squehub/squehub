<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for a denied call whose operation was not invoked. */
class_alias(\App\Reliability\CircuitOpenException::class, __NAMESPACE__ . '\\CircuitOpenException');
