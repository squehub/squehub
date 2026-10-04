<?php

declare(strict_types=1);

namespace App\Logging;

use Closure;
use LogicException;

/** Resolves the current Application's logger for the small global helper. */
final class Log
{
    private static ?Closure $resolver = null;

    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    public static function logger(): Logger
    {
        if (self::$resolver === null) throw new LogicException('Logger is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }
}
