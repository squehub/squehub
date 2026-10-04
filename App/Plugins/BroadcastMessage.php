<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the validated message supplied to Broadcast adapters. */
class_alias(\App\Broadcasting\BroadcastMessage::class, __NAMESPACE__ . '\\BroadcastMessage');
