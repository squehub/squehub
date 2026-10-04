<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for a View runtime diagnostic with its original cause. */
class_alias(\App\View\ViewRenderException::class, __NAMESPACE__ . '\\ViewRenderException');
