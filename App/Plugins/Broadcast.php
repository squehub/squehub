<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Broadcasting\Broadcast as BroadcastGateway;
use App\Broadcasting\BroadcastAdapter;
use App\Broadcasting\BroadcastEvent;
use App\Broadcasting\BroadcastManager;
use Closure;

/** Stable application-facing Broadcast API; adapters remain Application-owned. */
final class Broadcast
{
    public static function manager(): BroadcastManager { return BroadcastGateway::manager(); }

    public static function send(BroadcastEvent $event): void { self::manager()->send($event); }

    public static function queue(BroadcastEvent $event, string $queue = 'default', int $delay = 0,
        ?string $connection = null, bool $afterCommit = false,
        ?string $transactionConnection = null): void
    {
        self::manager()->queue($event, $queue, $delay, $connection, $afterCommit,
            $transactionConnection);
    }

    public static function privateChannel(string $pattern, callable|string $authorizer): void
    {
        self::manager()->privateChannel($pattern, $authorizer);
    }

    public static function authorizePrivate(string $channel): bool
    {
        return self::manager()->authorizePrivate($channel);
    }

    public static function registerAdapter(string $name, Closure|BroadcastAdapter $adapter): void
    {
        self::manager()->registerAdapter($name, $adapter);
    }
}
