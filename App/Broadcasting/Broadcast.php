<?php

declare(strict_types=1);

namespace App\Broadcasting;

use Closure;

/** Selects the current Application's BroadcastManager for developer APIs. */
final class Broadcast
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }

    public static function manager(): BroadcastManager
    {
        if (self::$resolver === null) {
            throw new BroadcastException('Broadcasting is unavailable before Application bootstrap.');
        }
        return (self::$resolver)();
    }
}
