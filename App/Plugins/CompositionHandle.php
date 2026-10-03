<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the Application-owned Queue composition handle. */
class_alias(\App\Queue\Composition\CompositionHandle::class, __NAMESPACE__ . '\\CompositionHandle');
