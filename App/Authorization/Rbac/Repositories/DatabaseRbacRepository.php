<?php

declare(strict_types=1);

namespace App\Authorization\Rbac\Repositories;

use App\Authorization\Rbac\RbacIdentity;
use App\Authorization\Rbac\RbacRepository;
use App\Database\DatabaseManager;
use App\Database\Exception\QueryException;
use InvalidArgumentException;
use LogicException;
use PDOException;

/** Bound queries use SHA-256 lookup columns to preserve exact keys on MySQL CI collations. */
final class DatabaseRbacRepository implements RbacRepository
{
    public function __construct(private DatabaseManager $database, private ?string $connection = null)
    {
    }

    public function createRole(string $key): void
    {
        $this->create('rbac_roles', $key);
    }

    public function createPermission(string $key): void
    {
        $this->create('rbac_permissions', $key);
    }

    public function roleExists(string $key): bool
    {
        return $this->findId('rbac_roles', $key) !== null;
    }

    public function permissionExists(string $key): bool
    {
        return $this->findId('rbac_permissions', $key) !== null;
    }

    public function grantPermission(string $role, string $permission): void
    {
        $roleId = $this->requireId('rbac_roles', $role);
        $permissionId = $this->requireId('rbac_permissions', $permission);
        if ($this->permissionGrantExists($roleId, $permissionId)) return;
        try {
            $this->database->table('rbac_role_permissions', $this->connection)->insert([
                'role_id' => $roleId, 'permission_id' => $permissionId,
            ]);
        } catch (QueryException $failure) {
            // A competing grant may have inserted the same unique pair.
            if (!self::duplicateKey($failure, 'rbac_role_permissions', ['role_id', 'permission_id'])) {
                throw $failure;
            }
            $this->confirmConcurrentGrant($roleId, $permissionId, $failure);
        }
    }

    public function revokePermission(string $role, string $permission): void
    {
        $roleId = $this->findId('rbac_roles', $role);
        $permissionId = $this->findId('rbac_permissions', $permission);
        if ($roleId === null || $permissionId === null) return;
        $this->database->table('rbac_role_permissions', $this->connection)
            ->filter('role_id', $roleId)->filter('permission_id', $permissionId)->delete();
    }

    public function assignRole(RbacIdentity $identity, string $role): void
    {
        $roleId = $this->requireId('rbac_roles', $role);
        $this->assertIdentityRows($identity);
        if ($this->identityRoleExists($identity, $roleId)) return;
        try {
            $this->database->table('rbac_identity_roles', $this->connection)->insert([
                'identity_class' => $identity->class,
                'identity_kind' => $identity->kind,
                'identity_identifier' => $identity->identifier,
                'identity_digest' => $identity->digest,
                'role_id' => $roleId,
            ]);
        } catch (QueryException $failure) {
            if (!self::duplicateKey($failure, 'rbac_identity_roles', ['identity_digest', 'role_id'])) {
                throw $failure;
            }
            $this->confirmConcurrentAssignment($identity, $roleId, $failure);
        }
    }

    public function removeRole(RbacIdentity $identity, string $role): void
    {
        $roleId = $this->findId('rbac_roles', $role);
        if ($roleId === null) return;
        $this->assertIdentityRows($identity);
        $this->database->table('rbac_identity_roles', $this->connection)
            ->filter('identity_digest', $identity->digest)->filter('role_id', $roleId)->delete();
    }

    public function hasRole(RbacIdentity $identity, string $role): bool
    {
        $roleId = $this->findId('rbac_roles', $role);
        if ($roleId === null) return false;
        return $this->identityRoleExists($identity, $roleId);
    }

    public function hasPermission(RbacIdentity $identity, string $permission): bool
    {
        $permissionId = $this->findId('rbac_permissions', $permission);
        if ($permissionId === null) return false;
        $roles = $this->assertIdentityRows($identity);
        if ($roles === []) return false;
        return $this->database->table('rbac_role_permissions', $this->connection)
            ->filter('permission_id', $permissionId)->filterIn('role_id', $roles)->first() !== null;
    }

    public function deleteRole(string $role): void
    {
        $id = $this->findId('rbac_roles', $role);
        if ($id === null) return;
        // Both mapping tables have ON DELETE CASCADE foreign keys.
        $this->database->table('rbac_roles', $this->connection)->filter('id', $id)->delete();
    }

    public function deletePermission(string $permission): void
    {
        $id = $this->findId('rbac_permissions', $permission);
        if ($id === null) return;
        $this->database->table('rbac_permissions', $this->connection)->filter('id', $id)->delete();
    }

    public function removeIdentity(RbacIdentity $identity): void
    {
        $this->assertIdentityRows($identity);
        $this->database->table('rbac_identity_roles', $this->connection)
            ->filter('identity_digest', $identity->digest)->delete();
    }

    private function create(string $table, string $key): void
    {
        $digest = self::keyDigest($key);
        if ($this->findId($table, $key) !== null) return;
        try {
            $this->database->table($table, $this->connection)->insert([
                'key' => $key, 'key_digest' => $digest,
            ]);
        } catch (QueryException $failure) {
            if (!self::duplicateKey($failure, $table, ['key_digest'])) throw $failure;
            if ($this->findId($table, $key) === null) throw $failure;
        }
    }

    /** An existing row cannot justify swallowing a deadlock or a failed transaction. */
    private static function duplicateKey(QueryException $failure, string $table, array $columns): bool
    {
        $cause = $failure->getPrevious();
        if (!$cause instanceof PDOException || !is_array($cause->errorInfo)
            || ($cause->errorInfo[0] ?? null) !== '23000') return false;

        $native = $cause->errorInfo[1] ?? null;
        // MySQL 1062 is specific to duplicate keys. SQLite's generic 19 also
        // covers foreign-key and NOT NULL failures, so check the exact index.
        if ($native === 1062 || $native === '1062') return true;
        if (!in_array($native, [19, '19', 1555, '1555', 2067, '2067'], true)) return false;
        $qualified = array_map(static fn (string $column): string => $table . '.' . $column, $columns);
        return ($cause->errorInfo[2] ?? null) === 'UNIQUE constraint failed: ' . implode(', ', $qualified);
    }

    /** @return int|string|null */
    private function findId(string $table, string $key): int|string|null
    {
        $row = $this->database->table($table, $this->connection)
            ->filter('key_digest', self::keyDigest($key))->first();
        if ($row === null) return null;
        if (!is_string($row['key'] ?? null) || $row['key'] !== $key
            || !is_string($row['key_digest'] ?? null)
            || $row['key_digest'] !== self::keyDigest($key)) {
            throw new LogicException('Stored RBAC key digest is inconsistent.');
        }
        return self::rowId($row, 'id');
    }

    /** @return int|string */
    private function requireId(string $table, string $key): int|string
    {
        $id = $this->findId($table, $key);
        if ($id === null) throw new InvalidArgumentException('RBAC role or permission is not defined.');
        return $id;
    }

    /** @param int|string $roleId @param int|string $permissionId */
    private function permissionGrantExists(int|string $roleId, int|string $permissionId): bool
    {
        return $this->database->table('rbac_role_permissions', $this->connection)
            ->filter('role_id', $roleId)->filter('permission_id', $permissionId)->first() !== null;
    }

    /** Recheck after a unique-key race without hiding unrelated write errors. */
    private function confirmConcurrentGrant(int|string $roleId, int|string $permissionId, QueryException $failure): void
    {
        if (!$this->permissionGrantExists($roleId, $permissionId)) throw $failure;
    }

    private function confirmConcurrentAssignment(RbacIdentity $identity, int|string $roleId,
        QueryException $failure): void
    {
        if (!$this->identityRoleExists($identity, $roleId)) throw $failure;
    }

    /** @param int|string $roleId */
    private function identityRoleExists(RbacIdentity $identity, int|string $roleId): bool
    {
        $row = $this->database->table('rbac_identity_roles', $this->connection)
            ->filter('identity_digest', $identity->digest)->filter('role_id', $roleId)->first();
        if ($row === null) return false;
        self::assertExactIdentity($row, $identity);
        return true;
    }

    /** @return list<int|string> */
    private function assertIdentityRows(RbacIdentity $identity): array
    {
        $rows = $this->database->table('rbac_identity_roles', $this->connection)
            ->filter('identity_digest', $identity->digest)->all();
        $roles = [];
        foreach ($rows as $row) {
            self::assertExactIdentity($row, $identity);
            $roles[] = self::rowId($row, 'role_id');
        }
        return $roles;
    }

    /** @param array<string, mixed> $row */
    private static function assertExactIdentity(array $row, RbacIdentity $identity): void
    {
        if (($row['identity_digest'] ?? null) !== $identity->digest
            || ($row['identity_class'] ?? null) !== $identity->class
            || ($row['identity_kind'] ?? null) !== $identity->kind
            || ($row['identity_identifier'] ?? null) !== $identity->identifier) {
            throw new LogicException('Stored RBAC identity digest is inconsistent.');
        }
    }

    /** @param array<string, mixed> $row @return int|string */
    private static function rowId(array $row, string $column): int|string
    {
        $value = $row[$column] ?? null;
        if (is_int($value) && $value >= 0) return $value;
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1) {
            return $value;
        }
        throw new LogicException('Stored RBAC identifier is invalid.');
    }

    private static function keyDigest(string $key): string
    {
        if ($key === '' || strlen($key) > 128
            || preg_match('/\A[A-Za-z_][A-Za-z0-9._-]*\z/D', $key) !== 1) {
            throw new InvalidArgumentException('RBAC key must be a bounded identifier.');
        }
        return hash('sha256', $key);
    }
}
