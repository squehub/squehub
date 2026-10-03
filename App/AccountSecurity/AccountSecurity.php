<?php

declare(strict_types=1);

namespace App\AccountSecurity;

use Closure;

/** Resolves account security from the currently booted Application. */
final class AccountSecurity
{
    private static ?Closure $resolver = null;

    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    public static function manager(): AccountSecurityManager
    {
        if (self::$resolver === null) {
            throw new AccountSecurityConfigurationException('Account security is unavailable before Application bootstrap.');
        }
        return (self::$resolver)();
    }
}
