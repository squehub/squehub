<?php

declare(strict_types=1);

namespace App\Security\Csrf;

use Closure;
use LogicException;

/** Resolves the current Application's manager for the legacy/global helper. */
final class Csrf
{
    private static ?Closure $resolver = null;

    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    public static function manager(): CsrfTokenManager
    {
        if (self::$resolver === null) throw new LogicException('CSRF is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }
}
