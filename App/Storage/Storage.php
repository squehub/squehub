<?php

declare(strict_types=1);

namespace App\Storage;

use Closure;
use LogicException;

/** Resolves the manager belonging to the currently bootstrapped Application. */
final class Storage
{
    private static ?Closure $resolver = null;

    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    public static function manager(): StorageManager
    {
        if (self::$resolver === null) throw new LogicException('Storage is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }
}
