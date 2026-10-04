<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Authorization\Authorization as AuthorizationGateway;
use App\Authorization\AuthorizationManager;

/** Authorization gateway using the existing Application-owned ability registry. */
final class Gate
{
    public static function manager(): AuthorizationManager { return AuthorizationGateway::manager(); }
    public static function allows(string $ability, object|string|null $subject = null): bool
    {
        return self::manager()->allows($ability, $subject);
    }
    public static function denies(string $ability, object|string|null $subject = null): bool
    {
        return self::manager()->denies($ability, $subject);
    }
    public static function require(string $ability, object|string|null $subject = null): void
    {
        self::manager()->require($ability, $subject);
    }
    public static function define(string $ability, mixed $rule): void { self::manager()->define($ability, $rule); }
    public static function policy(string $subjectClass, string $policyClass): void
    {
        self::manager()->policy($subjectClass, $policyClass);
    }
}
