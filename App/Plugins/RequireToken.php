<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the named API token authentication route middleware. */
class_alias(\App\Auth\Middleware\RequireToken::class, __NAMESPACE__ . '\\RequireToken');
