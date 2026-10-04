<?php

declare(strict_types=1);

namespace App\Plugins;

/** Application-facing alias for type-hinting the registered Health service. */
class_alias(\App\Health\HealthManager::class, __NAMESPACE__ . '\\HealthManager');
