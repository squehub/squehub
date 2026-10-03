<?php

declare(strict_types=1);

namespace App\Mfa;

use Closure;

/** Resolve the MFA service from the current Application. */
final class Mfa
{
    private static ?Closure $resolver = null;

    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    public static function manager(): MfaManager
    {
        if (self::$resolver === null) throw new MfaConfigurationException('MFA is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }
}
