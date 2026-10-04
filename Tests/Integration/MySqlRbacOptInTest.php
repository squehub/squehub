<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Contracts\Authenticatable;
use App\Authorization\Rbac\RbacIdentity;
use App\Authorization\Rbac\Repositories\DatabaseRbacRepository;
use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Schema\Schema;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Qualifies RBAC only on a confirmed, initially empty disposable MySQL schema.
 * The shared advisory lock serializes all cooperating SqueHub opt-in suites.
 *
 * @group mysql
 */
final class MySqlRbacOptInTest extends TestCase
{
    public function testMigrationExactLookupsCascadesAndTransactionsOnDisposableMySql(): void
    {
        if (getenv('SQUEHUB_TEST_MYSQL_ENABLED') !== '1') {
            self::markTestSkipped('Set SQUEHUB_TEST_MYSQL_ENABLED=1 and explicit disposable test database settings.');
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_mysql is required for the opt-in MySQL integration test.');
        }

        $host = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_HOST');
        $port = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_PORT');
        $database = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_DATABASE');
        $user = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_USER');
        $password = getenv('SQUEHUB_TEST_MYSQL_PASSWORD');
        $confirmation = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_CONFIRM_DATABASE');
        self::assertNotFalse($password, 'Set SQUEHUB_TEST_MYSQL_PASSWORD explicitly, even when empty.');
        self::assertMatchesRegularExpression('/\Asquehub_test_[A-Za-z0-9_]+\z/D', $database);
        self::assertSame($database, $confirmation, 'Test database confirmation must match exactly.');

        // Never consult the application .env or its default database connection.
        $manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'disposable',
            'connections' => ['disposable' => [
                'driver' => 'mysql', 'host' => $host, 'port' => $port,
                'database' => $database, 'username' => $user,
                'password' => $password, 'charset' => 'utf8mb4',
            ]],
        ]]));
        $connection = $manager->connection();
        $pdo = $connection->pdo();
        self::assertSame($database, $pdo->query('SELECT DATABASE()')->fetchColumn());
        $version = strtolower((string) $pdo->query('SELECT VERSION()')->fetchColumn());
        $comment = strtolower((string) $pdo->query('SELECT @@version_comment')->fetchColumn());
        self::assertStringNotContainsString('mariadb', $version . $comment, 'This test verifies MySQL semantics.');
        self::assertSame('innodb', strtolower((string) $pdo->query('SELECT @@default_storage_engine')->fetchColumn()));

        $lockName = 'squehub_phase6a_' . substr(hash('sha256', $database), 0, 40);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        self::assertSame(1, (int) $lock->fetchColumn(), 'Another SqueHub MySQL test is using this database.');
        try {
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM),
                'The confirmed test database must be empty before this test may create tables.');
            fwrite(STDERR, "Opt-in MySQL RBAC server: {$version} ({$comment}), default engine InnoDB.\n");

            try {
                require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_30_create_rbac_tables.php';
                $migration = new \CreateRbacTables();
                $schema = $connection->schema();
                $migration->up($pdo, $schema);
                $this->assertSchema($pdo, $schema);
                $this->forceCaseInsensitiveTextColumns($pdo);
                $this->assertRepositoryLifecycle($manager, $pdo);
                $migration->down($pdo, $schema);
                self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM));
            } finally {
                // The empty-schema check under this lock establishes ownership
                // even when MySQL DDL succeeds only partway through migration.
                foreach (['rbac_identity_roles', 'rbac_role_permissions',
                    'rbac_permissions', 'rbac_roles'] as $table) {
                    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
                }
            }
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM));
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
                // Closing PDO also releases its lock; preserve any first error.
            }
        }
    }

    private function assertSchema(PDO $pdo, Schema $schema): void
    {
        foreach (['rbac_roles', 'rbac_permissions', 'rbac_role_permissions',
            'rbac_identity_roles'] as $table) {
            self::assertTrue($schema->hasTable($table), $table);
            $engine = $pdo->prepare('SELECT ENGINE AS engine FROM INFORMATION_SCHEMA.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $engine->execute([$table]);
            self::assertSame('innodb', strtolower((string) $engine->fetchColumn()), $table);
        }
        foreach (['rbac_roles', 'rbac_permissions'] as $table) {
            foreach (['id', 'key', 'key_digest'] as $column) {
                self::assertTrue($schema->hasColumn($table, $column), "{$table}.{$column}");
            }
            $this->assertIndex($schema, $table, 'PRIMARY', ['id'], true);
            $this->assertIndex($schema, $table, $table . '_digest', ['key_digest'], true);
        }
        $this->assertIndex($schema, 'rbac_role_permissions', 'PRIMARY',
            ['role_id', 'permission_id'], true);
        $this->assertIndex($schema, 'rbac_identity_roles', 'PRIMARY',
            ['identity_digest', 'role_id'], true);
        $this->assertForeignKey($schema, 'rbac_role_permissions', 'rbac_rp_role_fk',
            'role_id', 'rbac_roles');
        $this->assertForeignKey($schema, 'rbac_role_permissions', 'rbac_rp_permission_fk',
            'permission_id', 'rbac_permissions');
        $this->assertForeignKey($schema, 'rbac_identity_roles', 'rbac_ir_role_fk',
            'role_id', 'rbac_roles');
    }

    /** @param list<string> $columns */
    private function assertIndex(Schema $schema, string $table, string $name,
        array $columns, bool $unique): void
    {
        foreach ($schema->indexes($table) as $index) {
            if ($index['name'] !== $name) continue;
            self::assertSame($columns, $index['columns'], $name);
            self::assertSame($unique, $index['unique'], $name);
            return;
        }
        self::fail("Missing {$table}.{$name} index.");
    }

    private function assertForeignKey(Schema $schema, string $table, string $name,
        string $column, string $parent): void
    {
        foreach ($schema->foreignKeys($table) as $key) {
            if ($key['name'] !== $name) continue;
            self::assertSame([$column], $key['columns'], $name);
            self::assertSame($parent, $key['referenced_table'], $name);
            self::assertSame(['id'], $key['referenced_columns'], $name);
            self::assertSame('CASCADE', $key['on_delete'], $name);
            return;
        }
        self::fail("Missing {$table}.{$name} foreign key.");
    }

    /** Test exact digest lookups even if the disposable database defaults to a binary collation. */
    private function forceCaseInsensitiveTextColumns(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE `rbac_roles` MODIFY `key` VARCHAR(128) '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        $pdo->exec('ALTER TABLE `rbac_permissions` MODIFY `key` VARCHAR(128) '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        $pdo->exec('ALTER TABLE `rbac_identity_roles` MODIFY `identity_identifier` VARCHAR(255) '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
    }

    private function assertRepositoryLifecycle(DatabaseManager $manager, PDO $pdo): void
    {
        $store = new DatabaseRbacRepository($manager, 'disposable');
        $store->createRole('Admin');
        $store->createRole('admin');
        $store->createPermission('reports.view');
        $store->createPermission('Reports.View');
        $store->createRole('Admin'); // Repeated creation is idempotent.
        self::assertSame(2, $this->countWhere($pdo, 'rbac_roles', 'key', 'Admin'),
            'The key column is deliberately case-insensitive; digests preserve both definitions.');
        self::assertSame(2, $this->countWhere($pdo, 'rbac_permissions', 'key', 'reports.view'));

        $store->grantPermission('Admin', 'reports.view');
        $store->grantPermission('admin', 'Reports.View');
        $store->grantPermission('Admin', 'reports.view');
        $int = RbacIdentity::from(new MySqlRbacUser(7));
        $decimal = RbacIdentity::from(new MySqlRbacUser('7'));
        $padded = RbacIdentity::from(new MySqlRbacUser('007'));
        $upper = RbacIdentity::from(new MySqlRbacUser('Alice'));
        $lower = RbacIdentity::from(new MySqlRbacUser('alice'));
        $otherClass = RbacIdentity::from(new MySqlRbacStaff(7));
        self::assertSame($int->digest, $decimal->digest);
        self::assertNotSame($int->digest, $padded->digest);
        self::assertNotSame($int->digest, $otherClass->digest);
        $store->assignRole($int, 'Admin');
        $store->assignRole($padded, 'admin');
        $store->assignRole($upper, 'Admin');
        $store->assignRole($lower, 'admin');
        $store->assignRole($int, 'Admin');
        self::assertSame(2, $this->countRows($pdo, 'rbac_role_permissions'));
        self::assertSame(4, $this->countRows($pdo, 'rbac_identity_roles'));
        self::assertSame(2, $this->countWhere($pdo, 'rbac_identity_roles',
            'identity_identifier', 'Alice'));
        self::assertTrue($store->hasRole($decimal, 'Admin'));
        self::assertFalse($store->hasRole($padded, 'Admin'));
        self::assertFalse($store->hasRole($otherClass, 'Admin'));
        self::assertTrue($store->hasPermission($upper, 'reports.view'));
        self::assertFalse($store->hasPermission($upper, 'Reports.View'));
        self::assertTrue($store->hasPermission($lower, 'Reports.View'));
        self::assertFalse($store->hasPermission($lower, 'reports.view'));

        $store->createRole('transactional');
        $store->createPermission('txn.run');
        try {
            $manager->transaction(static function () use ($store, $int): void {
                $store->grantPermission('transactional', 'txn.run');
                $store->assignRole($int, 'transactional');
                throw new RuntimeException('roll back RBAC mappings');
            }, 'disposable');
            self::fail('The mapping transaction must roll back.');
        } catch (RuntimeException $failure) {
            self::assertSame('roll back RBAC mappings', $failure->getMessage());
        }
        self::assertFalse($store->hasRole($int, 'transactional'));
        self::assertFalse($store->hasPermission($int, 'txn.run'));
        $store->grantPermission('transactional', 'txn.run');
        $store->assignRole($int, 'transactional');
        try {
            $manager->transaction(static function () use ($store): void {
                $store->deleteRole('transactional');
                throw new RuntimeException('roll back RBAC cascade');
            }, 'disposable');
            self::fail('The cascade transaction must roll back.');
        } catch (RuntimeException $failure) {
            self::assertSame('roll back RBAC cascade', $failure->getMessage());
        }
        self::assertTrue($store->hasRole($int, 'transactional'));
        self::assertTrue($store->hasPermission($int, 'txn.run'));
        $store->deleteRole('transactional');
        self::assertFalse($store->hasRole($int, 'transactional'));
        self::assertFalse($store->hasPermission($int, 'txn.run'));
        self::assertSame(2, $this->countRows($pdo, 'rbac_role_permissions'));
        self::assertSame(4, $this->countRows($pdo, 'rbac_identity_roles'));

        $store->deletePermission('reports.view');
        self::assertFalse($store->hasPermission($int, 'reports.view'));
        self::assertTrue($store->hasPermission($padded, 'Reports.View'));
        self::assertSame(1, $this->countRows($pdo, 'rbac_role_permissions'));
        $store->deleteRole('Admin');
        self::assertFalse($store->hasRole($int, 'Admin'));
        self::assertFalse($store->hasRole($upper, 'Admin'));
        self::assertTrue($store->hasRole($lower, 'admin'));
        self::assertSame(2, $this->countRows($pdo, 'rbac_identity_roles'));
        $store->removeIdentity($lower);
        self::assertFalse($store->hasRole($lower, 'admin'));
        self::assertTrue($store->hasRole($padded, 'admin'));
        self::assertSame(1, $this->countRows($pdo, 'rbac_identity_roles'));
    }

    private function countRows(PDO $pdo, string $table): int
    {
        // Only this class's fixed, test-owned table names reach the identifier.
        return (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    private function countWhere(PDO $pdo, string $table, string $column, string $value): int
    {
        // Identifiers are fixed by this test; only the value is caller data.
        $statement = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` = ?');
        $statement->execute([$value]);
        return (int) $statement->fetchColumn();
    }

    private function requiredEnvironment(string $key): string
    {
        $value = getenv($key);
        self::assertIsString($value, "Set {$key} for the disposable MySQL test.");
        self::assertNotSame('', $value, "Set {$key} for the disposable MySQL test.");
        return $value;
    }
}

final readonly class MySqlRbacUser implements Authenticatable
{
    public function __construct(private int|string $identifier) { }
    public function authIdentifier(): int|string { return $this->identifier; }
    public function authPasswordHash(): string { return 'unused'; }
}

final readonly class MySqlRbacStaff implements Authenticatable
{
    public function __construct(private int|string $identifier) { }
    public function authIdentifier(): int|string { return $this->identifier; }
    public function authPasswordHash(): string { return 'unused'; }
}
