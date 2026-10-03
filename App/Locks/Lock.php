<?php

declare(strict_types=1);

namespace App\Locks;

use Closure;

/** Bridge to the lock manager owned by the selected Application. */
final class Lock
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }

    public static function manager(): LockManager
    {
        if (self::$resolver === null) {
            throw new LockBackendException('Locks are unavailable before Application bootstrap.');
        }
        return (self::$resolver)();
    }
}
