<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for safe Broadcast configuration and delivery failures. */
class_alias(\App\Broadcasting\BroadcastException::class, __NAMESPACE__ . '\\BroadcastException');
