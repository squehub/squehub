<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Auth\Auth as AuthGateway;
use App\Auth\AuthManager;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Contracts\AuthGuard;
use App\Auth\Tokens\TokenManager;
use App\Auth\Tokens\TokenMetadata;

/** Current Application authentication, with no second guard or session state. */
final class Auth
{
    public static function manager(): AuthManager { return AuthGateway::manager(); }
    public static function guard(?string $name = null): AuthGuard { return self::manager()->guard($name); }
    public static function user(): ?Authenticatable { return self::manager()->user(); }
    public static function id(): int|string|null { return self::manager()->id(); }
    public static function check(): bool { return self::manager()->check(); }
    public static function guest(): bool { return self::manager()->guest(); }
    public static function tokens(?string $guard = null): TokenManager { return self::manager()->tokens($guard); }
    public static function token(): ?TokenMetadata { return self::manager()->token(); }
    public static function tokenAllows(string $ability): bool { return self::manager()->tokenAllows($ability); }
    public static function attempt(#[\SensitiveParameter] array $credentials, bool $remember = false): bool
    {
        return self::manager()->attempt($credentials, $remember);
    }
    public static function login(Authenticatable $identity, bool $remember = false): void
    {
        self::manager()->login($identity, $remember);
    }
    public static function logout(): void { self::manager()->logout(); }
    public static function forgetRemembered(?string $guard = null): void
    {
        self::manager()->forgetRemembered($guard);
    }
    public static function revokeRemembered(Authenticatable $identity, ?string $guard = null): void
    {
        self::manager()->revokeRemembered($identity, $guard);
    }
}
