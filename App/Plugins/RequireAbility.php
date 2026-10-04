<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the named authorization route middleware. */
class_alias(\App\Authorization\Middleware\RequireAbility::class, __NAMESPACE__ . '\\RequireAbility');
