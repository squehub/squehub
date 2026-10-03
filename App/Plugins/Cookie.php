<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact application-facing alias for the canonical HTTP cookie value. */
class_alias(\App\Http\Cookie::class, __NAMESPACE__ . '\\Cookie');
