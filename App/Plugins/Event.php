<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Events\EventDispatcher;
use App\Events\Events;

/** Event access through the current Application dispatcher. */
final class Event
{
    public static function dispatcher(): EventDispatcher { return Events::dispatcher(); }
    public static function emit(object $event): void { self::dispatcher()->emit($event); }
    public static function listen(string $eventType, string|callable $listener, int $priority = 0): void
    {
        self::dispatcher()->listen($eventType, $listener, $priority);
    }

    /** Explicit Queue delivery; ordinary listen() remains synchronous. */
    public static function listenQueued(string $eventType, string|callable $listener, int $priority = 0,
        bool $afterCommit = true, string $queue = 'default', ?string $connection = null,
        int $delay = 0, ?string $transactionConnection = null): void
    {
        self::dispatcher()->listenQueued($eventType, $listener, $priority, $afterCommit,
            $queue, $connection, $delay, $transactionConnection);
    }
}
