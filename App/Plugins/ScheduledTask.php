<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for fluent application schedule declarations. */
class_alias(\App\Scheduler\ScheduledTask::class, __NAMESPACE__ . '\\ScheduledTask');
