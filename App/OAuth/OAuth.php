<?php

declare(strict_types=1);

namespace App\OAuth;

use Closure;

/** Current Application gateway; mutable protocol state remains in its manager. */
final class OAuth
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }

    public static function manager(): OAuthManager
    {
        if (self::$resolver === null) throw new OAuthException('OIDC is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }

    public static function provider(string $name): OAuthProvider { return self::manager()->provider($name); }
}
