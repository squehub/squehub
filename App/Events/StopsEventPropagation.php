<?php

declare(strict_types=1);

namespace App\Events;

/** Provides explicit stop state without imposing a framework event superclass. */
trait StopsEventPropagation
{
    private bool $eventPropagationStopped = false;

    public function stopPropagation(): void
    {
        $this->eventPropagationStopped = true;
    }

    public function propagationStopped(): bool
    {
        return $this->eventPropagationStopped;
    }
}
