<?php

declare(strict_types=1);

namespace App\Session;

use Closure;

/** Developer helper bridge to the Application-owned manager. */
final class Session
{
    private static ?Closure $resolver = null;

    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    public static function manager(): SessionManager
    {
        if (self::$resolver === null) throw new SessionException('Session is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }

    /** Views rendered outside a bootstrapped Application have an empty error bag. */
    public static function isAvailable(): bool
    {
        return self::$resolver !== null;
    }
}
