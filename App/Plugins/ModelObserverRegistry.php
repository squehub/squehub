<?php

declare(strict_types=1);

namespace App\Plugins;

/** Application-facing alias for one Application's Model observer registry. */
class_alias(\App\Database\Lifecycle\ModelObserverRegistry::class, __NAMESPACE__ . '\\ModelObserverRegistry');
