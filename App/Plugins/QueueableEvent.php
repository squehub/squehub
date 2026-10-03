<?php

declare(strict_types=1);

namespace App\Plugins;

/** Application-facing alias for the explicit queued-event payload contract. */
class_alias(\App\Events\QueueableEvent::class, __NAMESPACE__ . '\\QueueableEvent');
