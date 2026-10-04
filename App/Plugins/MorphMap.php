<?php

declare(strict_types=1);

namespace App\Plugins;

/** Application-facing alias for explicit, Application-owned polymorphic types. */
class_alias(\App\Database\Relations\MorphMap::class, __NAMESPACE__ . '\\MorphMap');
