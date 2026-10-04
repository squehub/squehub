<?php

declare(strict_types=1);

namespace App\Authorization\Rbac;

use Closure;

/** Resolve the manager owned by the current Application. */
final class Rbac
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }

    public static function manager(): RbacManager
    {
        if (self::$resolver === null) throw new RbacException('RBAC is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }
}
