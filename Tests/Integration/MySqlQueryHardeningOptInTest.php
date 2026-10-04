<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Guarded real-MySQL qualification for 16C/16E query behavior. It reads only
 * explicit SQUEHUB_TEST_MYSQL_* settings and requires an empty, confirmed
 * squehub_test_* database before creating its own disposable table.
 *
 * @group mysql
 */
final class MySqlQueryHardeningOptInTest extends TestCase
{
    public function testUpsertCursorAndManagedRowLocksOnConfirmedDisposableMySql(): void
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
        self::assertStringNotContainsString('mariadb', $version . $comment);
        self::assertSame('innodb', strtolower((string) $pdo->query('SELECT @@default_storage_engine')->fetchColumn()));

        // Share the existing disposable-DB advisory lock with the other MySQL
        // qualification tests so parallel test processes cannot both pass the
        // empty-database guard before either creates its tables.
        $lockName = 'squehub_phase6a_' . substr(hash('sha256', $database), 0, 40);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        self::assertSame(1, (int) $lock->fetchColumn(), 'Another Phase 16 MySQL test is using this database.');
        try {
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM),
                'The confirmed test database must be empty before this test may create tables.');
            $pdo->exec('CREATE TABLE phase16_mysql_query (
                id BIGINT PRIMARY KEY, email VARCHAR(120) NOT NULL UNIQUE,
                name VARCHAR(80) NOT NULL, rank_value INT NOT NULL
            ) ENGINE=InnoDB');
            try {
                $table = $manager->table('phase16_mysql_query');
                self::assertSame(3, $table->insertMany([
                    ['id' => 1, 'email' => 'a@example.test', 'name' => 'Ada', 'rank_value' => 1],
                    ['id' => 2, 'email' => 'b@example.test', 'name' => 'Bea', 'rank_value' => 1],
                    ['id' => 3, 'email' => 'c@example.test', 'name' => 'Cy', 'rank_value' => 2],
                ]));
                $query = $manager->table('phase16_mysql_query')->sort('rank_value');
                $first = $query->cursorPage(2);
                self::assertSame([1, 2], array_map('intval', array_column($first->items(), 'id')));
                self::assertTrue($first->hasMore());
                self::assertSame([3], array_map('intval', array_column(
                    $query->cursorPage(2, $first->nextCursor())->items(), 'id'
                )));
                self::assertGreaterThan(0, $manager->table('phase16_mysql_query')->upsert(
                    ['id' => 99, 'email' => 'a@example.test', 'name' => 'Changed', 'rank_value' => 1],
                    ['email'], ['name']
                ));
                self::assertSame('Changed', $manager->table('phase16_mysql_query')->filter('id', 1)->first()['name']);

                $connection->begin();
                try {
                    $forUpdate = $manager->table('phase16_mysql_query')->filter('id', 1)->lockForUpdate();
                    self::assertStringEndsWith('FOR UPDATE', $forUpdate->toSql());
                    self::assertSame(1, (int) $forUpdate->first()['id']);
                    $shared = $manager->table('phase16_mysql_query')->filter('id', 2)->lockShared();
                    self::assertStringEndsWith('FOR SHARE', $shared->toSql());
                    self::assertSame(2, (int) $shared->first()['id']);
                } finally {
                    $connection->rollback();
                }
            } finally {
                $pdo->exec('DROP TABLE IF EXISTS phase16_mysql_query');
            }
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (\Throwable $failure) {
                // Closing this PDO also releases its advisory lock.
            }
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
