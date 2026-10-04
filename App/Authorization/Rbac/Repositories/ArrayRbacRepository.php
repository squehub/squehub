<?php

declare(strict_types=1);

namespace App\Authorization\Rbac\Repositories;

use App\Authorization\Rbac\RbacIdentity;
use App\Authorization\Rbac\RbacRepository;
use InvalidArgumentException;
use LogicException;

/** One Application instance owns this mutable, deterministic repository. */
final class ArrayRbacRepository implements RbacRepository
{
    /** @var array<string, string> */
    private array $roles = [];
    /** @var array<string, string> */
    private array $permissions = [];
    /** @var array<string, array<string, true>> */
    private array $rolePermissions = [];
    /** @var array<string, array{identity: RbacIdentity, roles: array<string, true>}> */
    private array $identityRoles = [];

    public function createRole(string $key): void
    {
        $digest = self::digest($key);
        self::assertExact($this->roles[$digest] ?? null, $key);
        $this->roles[$digest] = $key;
    }

    public function createPermission(string $key): void
    {
        $digest = self::digest($key);
        self::assertExact($this->permissions[$digest] ?? null, $key);
        $this->permissions[$digest] = $key;
    }

    public function roleExists(string $key): bool
    {
        $digest = self::digest($key);
        self::assertExact($this->roles[$digest] ?? null, $key);
        return isset($this->roles[$digest]);
    }

    public function permissionExists(string $key): bool
    {
        $digest = self::digest($key);
        self::assertExact($this->permissions[$digest] ?? null, $key);
        return isset($this->permissions[$digest]);
    }

    public function grantPermission(string $role, string $permission): void
    {
        if (!$this->roleExists($role) || !$this->permissionExists($permission)) {
            throw new InvalidArgumentException('RBAC role or permission is not defined.');
        }
        $this->rolePermissions[self::digest($role)][self::digest($permission)] = true;
    }

    public function revokePermission(string $role, string $permission): void
    {
        $roleDigest = self::digest($role);
        $permissionDigest = self::digest($permission);
        self::assertExact($this->roles[$roleDigest] ?? null, $role);
        self::assertExact($this->permissions[$permissionDigest] ?? null, $permission);
        unset($this->rolePermissions[$roleDigest][$permissionDigest]);
    }

    public function assignRole(RbacIdentity $identity, string $role): void
    {
        if (!$this->roleExists($role)) {
            throw new InvalidArgumentException('RBAC role is not defined.');
        }
        $this->assertIdentity($identity);
        $this->identityRoles[$identity->digest] ??= ['identity' => $identity, 'roles' => []];
        $this->identityRoles[$identity->digest]['roles'][self::digest($role)] = true;
    }

    public function removeRole(RbacIdentity $identity, string $role): void
    {
        $roleDigest = self::digest($role);
        self::assertExact($this->roles[$roleDigest] ?? null, $role);
        $this->assertIdentity($identity);
        unset($this->identityRoles[$identity->digest]['roles'][$roleDigest]);
        if (($this->identityRoles[$identity->digest]['roles'] ?? null) === []) {
            unset($this->identityRoles[$identity->digest]);
        }
    }

    public function hasRole(RbacIdentity $identity, string $role): bool
    {
        $roleDigest = self::digest($role);
        self::assertExact($this->roles[$roleDigest] ?? null, $role);
        $this->assertIdentity($identity);
        return isset($this->roles[$roleDigest], $this->identityRoles[$identity->digest]['roles'][$roleDigest]);
    }

    public function hasPermission(RbacIdentity $identity, string $permission): bool
    {
        $permissionDigest = self::digest($permission);
        self::assertExact($this->permissions[$permissionDigest] ?? null, $permission);
        $this->assertIdentity($identity);
        if (!isset($this->permissions[$permissionDigest])) return false;
        foreach ($this->identityRoles[$identity->digest]['roles'] ?? [] as $roleDigest => $_) {
            if (isset($this->roles[$roleDigest], $this->rolePermissions[$roleDigest][$permissionDigest])) {
                return true;
            }
        }
        return false;
    }

    public function deleteRole(string $role): void
    {
        $digest = self::digest($role);
        self::assertExact($this->roles[$digest] ?? null, $role);
        unset($this->roles[$digest], $this->rolePermissions[$digest]);
        foreach ($this->identityRoles as $identityDigest => &$assignment) {
            unset($assignment['roles'][$digest]);
            if ($assignment['roles'] === []) unset($this->identityRoles[$identityDigest]);
        }
        unset($assignment);
    }

    public function deletePermission(string $permission): void
    {
        $digest = self::digest($permission);
        self::assertExact($this->permissions[$digest] ?? null, $permission);
        unset($this->permissions[$digest]);
        foreach ($this->rolePermissions as &$permissions) unset($permissions[$digest]);
        unset($permissions);
    }

    public function removeIdentity(RbacIdentity $identity): void
    {
        $this->assertIdentity($identity);
        unset($this->identityRoles[$identity->digest]);
    }

    private function assertIdentity(RbacIdentity $identity): void
    {
        $stored = $this->identityRoles[$identity->digest]['identity'] ?? null;
        if ($stored !== null && !$stored->sameAs($identity)) {
            throw new LogicException('RBAC identity digest collision.');
        }
    }

    private static function digest(string $key): string
    {
        if ($key === '' || strlen($key) > 128
            || preg_match('/\A[A-Za-z_][A-Za-z0-9._-]*\z/D', $key) !== 1) {
            throw new InvalidArgumentException('RBAC key must be a bounded identifier.');
        }
        return hash('sha256', $key);
    }

    private static function assertExact(?string $stored, string $requested): void
    {
        if ($stored !== null && $stored !== $requested) {
            throw new LogicException('RBAC key digest collision.');
        }
    }
}
