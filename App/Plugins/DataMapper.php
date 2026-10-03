<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the stateless constructor mapper; no second hydration policy. */
class_alias(\App\Data\DataMapper::class, __NAMESPACE__ . '\\DataMapper');
