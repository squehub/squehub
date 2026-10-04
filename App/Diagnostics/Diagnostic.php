<?php

declare(strict_types=1);

namespace App\Diagnostics;

use Closure;
use LogicException;

/** Resolves the current Application collector for the developer helper. */
final class Diagnostic
{
    private static ?Closure $resolver = null;

    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    public static function current(): Diagnostics
    {
        if (self::$resolver === null) throw new LogicException('Diagnostics is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }
}
