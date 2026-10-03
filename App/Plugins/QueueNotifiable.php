<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the deferred recipient identity contract. */
class_alias(\App\Notifications\QueueNotifiable::class, __NAMESPACE__ . '\\QueueNotifiable');
