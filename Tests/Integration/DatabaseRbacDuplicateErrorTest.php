<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Contracts\Authenticatable;
use App\Authorization\Rbac\RbacIdentity;
use App\Authorization\Rbac\Repositories\DatabaseRbacRepository;
use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Exception\QueryException;
use App\Database\Schema\Table;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Only a confirmed duplicate of the intended RBAC key may be treated as a race. */
final class DatabaseRbacDuplicateErrorTest extends TestCase
{
    public function testDriverErrorsAreClassifiedNarrowly(): void
    {
        $method = new ReflectionMethod(DatabaseRbacRepository::class, 'duplicateKey');
        $check = static function (array $errorInfo) use ($method): bool {
            $cause = new PDOException('Private driver diagnostic');
            $cause->errorInfo = $errorInfo;
            return $method->invoke(null, new QueryException('Database query failed.', 0, $cause),
                'rbac_roles', ['key_digest']);
        };

        self::assertTrue($check(['23000', 1062, 'Duplicate entry for key rbac_roles_digest']));
        self::assertTrue($check(['23000', 19, 'UNIQUE constraint failed: rbac_roles.key_digest']));
        self::assertFalse($check(['23000', 19, 'FOREIGN KEY constraint failed']));
        self::assertFalse($check(['23000', 19, 'UNIQUE constraint failed: another_table.key_digest']));
        self::assertFalse($check(['40001', 1213, 'Deadlock found when trying to get lock']));
        self::assertFalse($method->invoke(null, new QueryException('Database query failed.'),
            'rbac_roles', ['key_digest']));
    }

    public function testUnrelatedRoleInsertFailureIsRethrownEvenWhenRowAppears(): void
    {
        [$database, $pdo, $repository] = $this->database();
        $pdo->exec(<<<'SQL'
CREATE TRIGGER rbac_role_failure BEFORE INSERT ON rbac_roles BEGIN
    INSERT INTO rbac_roles (key, key_digest) VALUES (NEW.key, NEW.key_digest);
    SELECT RAISE(FAIL, 'simulated nonduplicate failure');
END
SQL);

        $this->assertQueryFailure(static fn () => $repository->createRole('editor'));
        self::assertTrue($repository->roleExists('editor'),
            'The row must exist to exercise the former exception-swallowing path.');
    }

    public function testUnrelatedMappingInsertFailuresAreRethrownEvenWhenRowsAppear(): void
    {
        [$database, $pdo, $repository] = $this->database();
        $repository->createRole('editor');
        $repository->createPermission('reports.view');

        $pdo->exec(<<<'SQL'
CREATE TRIGGER rbac_grant_failure BEFORE INSERT ON rbac_role_permissions BEGIN
    INSERT INTO rbac_role_permissions (role_id, permission_id)
        VALUES (NEW.role_id, NEW.permission_id);
    SELECT RAISE(FAIL, 'simulated nonduplicate failure');
END
SQL);
        $this->assertQueryFailure(static fn () => $repository->grantPermission('editor', 'reports.view'));
        self::assertSame(1, $database->table('rbac_role_permissions')->count());

        $identity = RbacIdentity::from(new DatabaseRbacDuplicateIdentity(7));
        $pdo->exec(<<<'SQL'
CREATE TRIGGER rbac_assignment_failure BEFORE INSERT ON rbac_identity_roles BEGIN
    INSERT INTO rbac_identity_roles
        (identity_class, identity_kind, identity_identifier, identity_digest, role_id)
        VALUES (NEW.identity_class, NEW.identity_kind, NEW.identity_identifier, NEW.identity_digest, NEW.role_id);
    SELECT RAISE(FAIL, 'simulated nonduplicate failure');
END
SQL);
        $this->assertQueryFailure(static fn () => $repository->assignRole($identity, 'editor'));
        self::assertSame(1, $database->table('rbac_identity_roles')->count());
    }

    /** @return array{DatabaseManager, PDO, DatabaseRbacRepository} */
    private function database(): array
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for RBAC database tests.');
        }
        $database = new DatabaseManager(new Repository(['database' => [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        $pdo = $database->connection()->pdo();
        $schema = $database->schema();
        $schema->create('rbac_roles', static function (Table $table): void {
            $table->id();
            $table->string('key', 128);
            $table->string('key_digest', 64);
            $table->unique('key_digest');
        });
        $schema->create('rbac_permissions', static function (Table $table): void {
            $table->id();
            $table->string('key', 128);
            $table->string('key_digest', 64);
            $table->unique('key_digest');
        });
        $schema->create('rbac_role_permissions', static function (Table $table): void {
            $table->foreignId('role_id');
            $table->foreignId('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });
        $schema->create('rbac_identity_roles', static function (Table $table): void {
            $table->string('identity_class', 255);
            $table->string('identity_kind', 1);
            $table->string('identity_identifier', 255);
            $table->string('identity_digest', 64);
            $table->foreignId('role_id');
            $table->primary(['identity_digest', 'role_id']);
        });
        return [$database, $pdo, new DatabaseRbacRepository($database)];
    }

    private function assertQueryFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('An unrelated insert failure must be rethrown.');
        } catch (QueryException $failure) {
            self::assertInstanceOf(PDOException::class, $failure->getPrevious());
        }
    }
}

/** Minimal typed identity for the RBAC assignment error test. */
final readonly class DatabaseRbacDuplicateIdentity implements Authenticatable
{
    public function __construct(private int $id) {}
    public function authIdentifier(): int { return $this->id; }
    public function authPasswordHash(): string { return 'unused'; }
}
