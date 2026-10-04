<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for controlled circuit configuration or backend failure. */
class_alias(\App\Reliability\CircuitException::class, __NAMESPACE__ . '\\CircuitException');
