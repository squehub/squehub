<?php

declare(strict_types=1);

namespace App\Events;

/** Opt-in contract that lets an event stop later synchronous listeners. */
interface StoppableEvent
{
    public function propagationStopped(): bool;
}
