<?php

declare(strict_types=1);

namespace App\Plugins;

/** Application-facing alias for one polymorphic child relationship. */
class_alias(\App\Database\Relations\MorphOne::class, __NAMESPACE__ . '\\MorphOne');
