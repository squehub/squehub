<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the aggregate outcome of one scheduler tick. */
class_alias(\App\Scheduler\SchedulerRunResult::class, __NAMESPACE__ . '\\SchedulerRunResult');
