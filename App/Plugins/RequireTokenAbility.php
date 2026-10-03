<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the token ability route middleware. */
class_alias(\App\Auth\Middleware\RequireTokenAbility::class, __NAMESPACE__ . '\\RequireTokenAbility');
