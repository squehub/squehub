<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the disposable testing Application; no second fixture state. */
class_alias(\App\Testing\TestApplication::class, __NAMESPACE__ . '\\TestApplication');
