<?php

declare(strict_types=1);

namespace App\Authorization\Rbac;

/** Explicit role/permission definitions and class-scoped identity assignments. */
interface RbacRepository
{
    public function createRole(string $key): void;
    public function createPermission(string $key): void;
    public function roleExists(string $key): bool;
    public function permissionExists(string $key): bool;
    public function grantPermission(string $role, string $permission): void;
    public function revokePermission(string $role, string $permission): void;
    public function assignRole(RbacIdentity $identity, string $role): void;
    public function removeRole(RbacIdentity $identity, string $role): void;
    public function hasRole(RbacIdentity $identity, string $role): bool;
    public function hasPermission(RbacIdentity $identity, string $permission): bool;
    public function deleteRole(string $role): void;
    public function deletePermission(string $permission): void;
    public function removeIdentity(RbacIdentity $identity): void;
}
