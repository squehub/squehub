<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the canonical application identity contract. */
class_alias(\App\Auth\Contracts\Authenticatable::class, __NAMESPACE__ . '\\Authenticatable');
