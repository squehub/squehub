<?php

declare(strict_types=1);

namespace App\Plugins;

/** Application-facing alias of the immutable report snapshot. */
class_alias(\App\Health\HealthReport::class, __NAMESPACE__ . '\\HealthReport');
