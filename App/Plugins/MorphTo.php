<?php

declare(strict_types=1);

namespace App\Plugins;

/** Application-facing alias for a polymorphic parent relationship. */
class_alias(\App\Database\Relations\MorphTo::class, __NAMESPACE__ . '\\MorphTo');
