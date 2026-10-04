<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Auth\Contracts\Authenticatable;
use App\Authorization\Rbac\Rbac as RbacGateway;
use App\Authorization\Rbac\RbacManager;

/** Application-facing role and permission gateway. */
final class Rbac
{
    public static function manager(): RbacManager { return RbacGateway::manager(); }
    public static function createRole(string $role): void { self::manager()->createRole($role); }
    public static function createPermission(string $permission): void { self::manager()->createPermission($permission); }
    public static function roleExists(string $role): bool { return self::manager()->roleExists($role); }
    public static function permissionExists(string $permission): bool { return self::manager()->permissionExists($permission); }
    public static function grantPermission(string $role, string $permission): void
    {
        self::manager()->grantPermission($role, $permission);
    }
    public static function revokePermission(string $role, string $permission): void
    {
        self::manager()->revokePermission($role, $permission);
    }
    public static function assignRole(Authenticatable $identity, string $role): void
    {
        self::manager()->assignRole($identity, $role);
    }
    public static function removeRole(Authenticatable $identity, string $role): void
    {
        self::manager()->removeRole($identity, $role);
    }
    public static function hasRole(Authenticatable $identity, string $role): bool
    {
        return self::manager()->hasRole($identity, $role);
    }
    public static function hasPermission(Authenticatable $identity, string $permission): bool
    {
        return self::manager()->hasPermission($identity, $permission);
    }
    public static function deleteRole(string $role): void { self::manager()->deleteRole($role); }
    public static function deletePermission(string $permission): void { self::manager()->deletePermission($permission); }
    public static function removeIdentity(Authenticatable $identity): void
    {
        self::manager()->removeIdentity($identity);
    }
}
