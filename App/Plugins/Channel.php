<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for a public or private broadcast channel value. */
class_alias(\App\Broadcasting\Channel::class, __NAMESPACE__ . '\\Channel');
