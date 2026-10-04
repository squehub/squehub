<?php

declare(strict_types=1);

namespace App\Plugins;

/** Exact alias for the canonical Queue infrastructure exception. */
class_alias(\App\Queue\QueueException::class, __NAMESPACE__ . '\\QueueException');
