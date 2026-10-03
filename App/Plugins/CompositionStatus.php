<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the payload-free Queue composition status. */
class_alias(\App\Queue\Composition\CompositionStatus::class, __NAMESPACE__ . '\\CompositionStatus');
