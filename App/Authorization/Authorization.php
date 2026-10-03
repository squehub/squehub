<?php

declare(strict_types=1);

namespace App\Authorization;

use Closure;

/** Resolves the authorization manager owned by the current Application. */
final class Authorization
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function manager(): AuthorizationManager
    {
        if (self::$resolver === null) {
            throw new AuthorizationConfigurationException('Authorization is unavailable before Application bootstrap.');
        }
        return (self::$resolver)();
    }
}
