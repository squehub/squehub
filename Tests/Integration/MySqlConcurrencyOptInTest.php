<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\Connection;
use App\Database\DatabaseManager;
use App\Database\Migrations\MigrationException;
use App\Database\Migrations\Migrator;
use App\Database\TransactionIsolation;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** @group mysql */
final class MySqlConcurrencyOptInTest extends TestCase
{
    public function testIsolationVisibilityAndMigrationAdvisoryGuard(): void
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

        $configuration = [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $user,
            'password' => $password,
            'charset' => 'utf8mb4',
        ];
        $manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'writer',
            'connections' => ['writer' => $configuration, 'reader' => $configuration],
        ]]));
        $writer = $manager->connection('writer');
        $reader = $manager->connection('reader');
        $pdo = $writer->pdo();
        self::assertSame($database, $pdo->query('SELECT DATABASE()')->fetchColumn());
        $version = strtolower((string) $pdo->query('SELECT VERSION()')->fetchColumn());
        $comment = strtolower((string) $pdo->query('SELECT @@version_comment')->fetchColumn());
        self::assertStringNotContainsString('mariadb', $version . $comment, 'This test verifies MySQL semantics.');
        self::assertSame('innodb', strtolower((string) $pdo->query('SELECT @@default_storage_engine')->fetchColumn()));

        $testLock = 'squehub_phase6a_' . substr(hash('sha256', $database), 0, 40);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$testLock]);
        self::assertSame(1, (int) $lock->fetchColumn(), 'Another SqueHub MySQL test is using this database.');
        try {
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM),
                'The confirmed test database must be empty before this test may create tables.');
            $project = new TemporaryProject();
            try {
                $writer->raw('CREATE TABLE phase16e_isolation_probe (id INT PRIMARY KEY) ENGINE=InnoDB');
                $writer->begin();
                $writer->raw('INSERT INTO phase16e_isolation_probe (id) VALUES (1)');
                self::assertSame(1, $reader->transaction(static function (Connection $connection): int {
                    return (int) $connection->raw('SELECT COUNT(*) FROM phase16e_isolation_probe')->fetchColumn();
                }, isolation: TransactionIsolation::READ_UNCOMMITTED));
                self::assertSame(0, $reader->transaction(static function (Connection $connection): int {
                    return (int) $connection->raw('SELECT COUNT(*) FROM phase16e_isolation_probe')->fetchColumn();
                }, isolation: TransactionIsolation::READ_COMMITTED));
                $writer->rollback();
                self::assertSame(0, (int) $reader->raw('SELECT COUNT(*) FROM phase16e_isolation_probe')->fetchColumn());

                $file = '2026_09_29_check_phase16e_migration_advisory.php';
                $project->write('Database/Migrations/' . $file, <<<'PHP'
<?php
class CheckPhase16eMigrationAdvisory {
    public function up(\PDO $pdo): void {}
    public function down(\PDO $pdo): void {}
}
PHP);
                $migrator = new Migrator($manager, $project->path(), 'writer');
                $migrationLock = 'squehub_migrations_' . substr(hash('sha256', $database), 0, 32);
                $guard = $pdo->prepare('SELECT GET_LOCK(?, 0)');
                $guard->execute([$migrationLock]);
                self::assertSame(1, (int) $guard->fetchColumn());
                try {
                    $migrator->run();
                    self::fail('A second migration runner should be rejected.');
                } catch (MigrationException $failure) {
                    self::assertStringContainsString('Another migration runner is active', $failure->getMessage());
                } finally {
                    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([$migrationLock]);
                    self::assertSame(1, (int) $release->fetchColumn());
                }
                self::assertSame([$file], $migrator->run());
                self::assertSame([], $migrator->run());
                self::assertSame([$file], $migrator->rollback());
            } finally {
                if ($writer->inTransaction()) $writer->rollback();
                if ($reader->inTransaction()) $reader->rollback();
                $pdo->exec('DROP TABLE IF EXISTS `phase16e_isolation_probe`');
                $pdo->exec('DROP TABLE IF EXISTS `migrations`');
                $project->remove();
            }
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$testLock]);
            } catch (Throwable) {
                // Closing PDO also releases its advisory lock.
            }
            $manager->disconnect();
        }
    }

    private function requiredEnvironment(string $key): string
    {
        $value = getenv($key);
        self::assertIsString($value, "Set {$key} for the disposable MySQL test.");
        self::assertNotSame('', $value, "Set {$key} for the disposable MySQL test.");
        return $value;
    }
}
