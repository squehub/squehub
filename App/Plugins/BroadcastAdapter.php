<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the optional external provider adapter contract. */
class_alias(\App\Broadcasting\BroadcastAdapter::class, __NAMESPACE__ . '\\BroadcastAdapter');
