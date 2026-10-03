<?php

declare(strict_types=1);

namespace App\Plugins;

/** Application-facing alias for a polymorphic child collection. */
class_alias(\App\Database\Relations\MorphMany::class, __NAMESPACE__ . '\\MorphMany');
