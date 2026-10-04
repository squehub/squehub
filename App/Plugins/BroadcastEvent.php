<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the explicit JSON-safe public broadcast contract. */
class_alias(\App\Broadcasting\BroadcastEvent::class, __NAMESPACE__ . '\\BroadcastEvent');
