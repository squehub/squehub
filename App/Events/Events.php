<?php

declare(strict_types=1);

namespace App\Events;

use Closure;
use LogicException;

/** Resolves the current Application's dispatcher for the small global helper. */
final class Events
{
    private static ?Closure $resolver = null;

    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    public static function dispatcher(): EventDispatcher
    {
        if (self::$resolver === null) {
            throw new LogicException('Events are unavailable before Application bootstrap.');
        }
        return (self::$resolver)();
    }
}
