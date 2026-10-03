<?php

declare(strict_types=1);

namespace App\Auth;

use Closure;

/** Resolves authentication from the currently booted Application. */
final class Auth
{
    private static ?Closure $resolver = null;

    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    public static function manager(): AuthManager
    {
        if (self::$resolver === null) throw new AuthException('Authentication is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }
}
