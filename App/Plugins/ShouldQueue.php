<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the queueable Notification payload contract. */
class_alias(\App\Notifications\ShouldQueue::class, __NAMESPACE__ . '\\ShouldQueue');
