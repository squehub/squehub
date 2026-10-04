<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for schedule configuration and infrastructure errors. */
class_alias(\App\Scheduler\SchedulerException::class, __NAMESPACE__ . '\\SchedulerException');
