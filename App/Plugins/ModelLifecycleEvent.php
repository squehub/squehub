<?php

declare(strict_types=1);

namespace App\Plugins;

/** Application-facing alias for the immutable canonical Model lifecycle context. */
class_alias(\App\Database\Lifecycle\ModelLifecycleEvent::class, __NAMESPACE__ . '\\ModelLifecycleEvent');
