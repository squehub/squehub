<?php

declare(strict_types=1);

namespace App\Authorization\Rbac;

use App\Auth\Contracts\Authenticatable;

/** Explicit role and permission mutations for one Application. */
final class RbacManager
{
    public function __construct(private RbacRepository $repository, private bool $enabled)
    {
    }

    public function enabled(): bool { return $this->enabled; }

    public function createRole(string $role): void
    {
        $this->requireEnabled();
        self::name($role);
        $this->repository->createRole($role);
    }

    public function createPermission(string $permission): void
    {
        $this->requireEnabled();
        self::name($permission);
        $this->repository->createPermission($permission);
    }

    public function roleExists(string $role): bool
    {
        self::name($role);
        return $this->enabled && $this->repository->roleExists($role);
    }

    public function permissionExists(string $permission): bool
    {
        self::name($permission);
        return $this->enabled && $this->repository->permissionExists($permission);
    }

    public function grantPermission(string $role, string $permission): void
    {
        $this->requireRole($role);
        $this->requirePermission($permission);
        $this->repository->grantPermission($role, $permission);
    }

    public function revokePermission(string $role, string $permission): void
    {
        $this->requireEnabled();
        self::name($role);
        self::name($permission);
        $this->repository->revokePermission($role, $permission);
    }

    public function assignRole(Authenticatable $identity, string $role): void
    {
        $this->requireRole($role);
        $this->repository->assignRole(RbacIdentity::from($identity), $role);
    }

    public function removeRole(Authenticatable $identity, string $role): void
    {
        $this->requireEnabled();
        self::name($role);
        $this->repository->removeRole(RbacIdentity::from($identity), $role);
    }

    public function hasRole(Authenticatable $identity, string $role): bool
    {
        self::name($role);
        return $this->enabled && $this->repository->hasRole(RbacIdentity::from($identity), $role);
    }

    public function hasPermission(Authenticatable $identity, string $permission): bool
    {
        self::name($permission);
        return $this->enabled && $this->repository->hasPermission(RbacIdentity::from($identity), $permission);
    }

    public function deleteRole(string $role): void
    {
        $this->requireEnabled();
        self::name($role);
        $this->repository->deleteRole($role);
    }

    public function deletePermission(string $permission): void
    {
        $this->requireEnabled();
        self::name($permission);
        $this->repository->deletePermission($permission);
    }

    /** Call in the same application transaction as an identity deletion. */
    public function removeIdentity(Authenticatable $identity): void
    {
        $this->requireEnabled();
        $this->repository->removeIdentity(RbacIdentity::from($identity));
    }

    private function requireRole(string $role): void
    {
        if (!$this->roleExists($role)) throw new RbacException('RBAC role is not registered.');
    }

    private function requirePermission(string $permission): void
    {
        if (!$this->permissionExists($permission)) throw new RbacException('RBAC permission is not registered.');
    }

    private function requireEnabled(): void
    {
        if (!$this->enabled) throw new RbacException('RBAC is not enabled for this Application.');
    }

    /** Match the existing global-ability vocabulary without reserving business roles. */
    public static function name(string $name): void
    {
        if (strlen($name) > 128 || preg_match('/\A[A-Za-z_][A-Za-z0-9._-]*\z/D', $name) !== 1) {
            throw new RbacException('RBAC name must be a bounded identifier.');
        }
    }
}
