<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact type alias for the canonical \App\Validation\ErrorBag; no wrapper state or conversion. */
class_alias(\App\Validation\ErrorBag::class, __NAMESPACE__ . '\\ErrorBag');
