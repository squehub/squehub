<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the canonical event propagation contract. */
class_alias(\App\Events\StoppableEvent::class, __NAMESPACE__ . '\\StoppableEvent');
