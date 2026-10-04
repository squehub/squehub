<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Idempotency\ResponseSnapshot;
use App\Idempotency\Stores\DatabaseIdempotencyStore;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Live MySQL uniqueness and conditional ownership on a confirmed empty schema.
 * Shares the existing SqueHub disposable-schema advisory lock.
 *
 * @group mysql
 */
final class IdempotencyMySqlOptInTest extends TestCase
{
    public function testDisposableMySqlClaimReplayExpiryAndExactCleanup(): void
    {
        if (getenv('SQUEHUB_TEST_MYSQL_ENABLED') !== '1') {
            self::markTestSkipped('Set SQUEHUB_TEST_MYSQL_ENABLED=1 and explicit disposable MySQL settings.');
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_mysql is required for live MySQL idempotency qualification.');
        }
        $host = $this->required('SQUEHUB_TEST_MYSQL_HOST');
        $port = $this->required('SQUEHUB_TEST_MYSQL_PORT');
        $name = $this->required('SQUEHUB_TEST_MYSQL_DATABASE');
        $user = $this->required('SQUEHUB_TEST_MYSQL_USER');
        $confirm = $this->required('SQUEHUB_TEST_MYSQL_CONFIRM_DATABASE');
        $password = getenv('SQUEHUB_TEST_MYSQL_PASSWORD');
        self::assertNotFalse($password, 'Set SQUEHUB_TEST_MYSQL_PASSWORD explicitly, even when empty.');
        self::assertMatchesRegularExpression('/\Asquehub_test_[A-Za-z0-9_]+\z/D', $name);
        self::assertSame($name, $confirm);
        $database = new DatabaseManager(new Repository(['database' => [
            'default' => 'disposable', 'connections' => ['disposable' => [
                'driver' => 'mysql', 'host' => $host, 'port' => $port,
                'database' => $name, 'username' => $user, 'password' => $password,
                'charset' => 'utf8mb4',
            ]],
        ]]));
        $connection = $database->connection();
        $pdo = $connection->pdo();
        self::assertSame($name, $pdo->query('SELECT DATABASE()')->fetchColumn());
        $version = strtolower((string) $pdo->query('SELECT VERSION()')->fetchColumn());
        $comment = strtolower((string) $pdo->query('SELECT @@version_comment')->fetchColumn());
        self::assertStringNotContainsString('mariadb', $version . $comment);
        self::assertSame('innodb', strtolower((string) $pdo->query('SELECT @@default_storage_engine')->fetchColumn()));
        $lockName = 'squehub_phase6a_' . substr(hash('sha256', $name), 0, 40);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        self::assertSame(1, (int) $lock->fetchColumn());
        try {
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM));
            try {
                require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_10_02_create_idempotency_records.php';
                (new \CreateIdempotencyRecords())->up($pdo, $connection->schema());
                $store = new DatabaseIdempotencyStore($connection);
                $scope = hash('sha256', 'mysql-scope');
                $body = hash('sha256', 'body');
                $different = hash('sha256', 'different');
                $owner = str_repeat('a', 64);
                $other = str_repeat('b', 64);
                self::assertSame('claimed', $store->claim($scope, $body, $owner, 1000, 10, 60)->state);
                self::assertSame('in_progress', $store->claim($scope, $body, $other, 1001, 10, 60)->state);
                self::assertSame('conflict', $store->claim($scope, $different, $other, 1001, 10, 60)->state);
                self::assertFalse($store->complete($scope, $other, 1002, null));
                self::assertTrue($store->complete($scope, $owner, 1002,
                    new ResponseSnapshot(201, 'done', 'text/plain')));
                self::assertSame('replay', $store->claim($scope, $body, $other, 1003, 10, 60)->state);
                self::assertSame('claimed', $store->claim($scope, $different, $other, 1060, 10, 60)->state);
                self::assertTrue($store->abandon($scope, $other));
                self::assertSame(0, $store->prune(1061));
                fwrite(STDERR, "Opt-in MySQL idempotency server: {$version} ({$comment}).\n");
            } finally {
                // Empty schema under our advisory lock establishes ownership.
                $pdo->exec('DROP TABLE IF EXISTS `idempotency_records`');
            }
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM));
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (\Throwable) {
                // Closing PDO releases the advisory lock if cleanup failed.
            }
        }
    }

    private function required(string $name): string
    {
        $value = getenv($name);
        self::assertIsString($value, "Set {$name} explicitly.");
        self::assertNotSame('', $value, "Set {$name} explicitly.");
        return $value;
    }
}
