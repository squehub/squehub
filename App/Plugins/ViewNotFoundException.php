<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for a missing root View diagnostic. */
class_alias(\App\View\ViewNotFoundException::class, __NAMESPACE__ . '\\ViewNotFoundException');
