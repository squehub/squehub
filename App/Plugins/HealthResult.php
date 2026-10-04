<?php

declare(strict_types=1);

namespace App\Plugins;

/** Application-facing alias of the immutable health outcome. */
class_alias(\App\Health\HealthResult::class, __NAMESPACE__ . '\\HealthResult');
