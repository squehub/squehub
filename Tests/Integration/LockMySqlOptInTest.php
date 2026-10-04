<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\SystemModelClock;
use App\Locks\LockManager;
use App\Locks\Stores\DatabaseLockStore;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Throwable;

/** @group mysql */
final class LockMySqlOptInTest extends TestCase
{
    public function testConfirmedEmptyMySqlDatabaseCoordinatesProcessesAndCleansTable(): void
    {
        if (getenv('SQUEHUB_TEST_MYSQL_ENABLED') !== '1') {
            self::markTestSkipped('Set SQUEHUB_TEST_MYSQL_ENABLED=1 and confirmed disposable database settings.');
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_mysql is required.');
        }
        $host = $this->required('SQUEHUB_TEST_MYSQL_HOST');
        $port = $this->required('SQUEHUB_TEST_MYSQL_PORT');
        $databaseName = $this->required('SQUEHUB_TEST_MYSQL_DATABASE');
        $user = $this->required('SQUEHUB_TEST_MYSQL_USER');
        $password = getenv('SQUEHUB_TEST_MYSQL_PASSWORD');
        $confirmation = $this->required('SQUEHUB_TEST_MYSQL_CONFIRM_DATABASE');
        self::assertNotFalse($password, 'Set SQUEHUB_TEST_MYSQL_PASSWORD explicitly, even when empty.');
        self::assertMatchesRegularExpression('/\Asquehub_test_[A-Za-z0-9_]+\z/D', $databaseName);
        self::assertSame($databaseName, $confirmation);

        $settings = ['driver' => 'mysql', 'host' => $host, 'port' => $port,
            'database' => $databaseName, 'username' => $user,
            'password' => $password, 'charset' => 'utf8mb4'];
        $database = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => $settings],
        ]]));
        $connection = $database->connection();
        $pdo = $connection->pdo();
        self::assertSame($databaseName, $pdo->query('SELECT DATABASE()')->fetchColumn());
        $version = strtolower((string) $pdo->query('SELECT VERSION()')->fetchColumn());
        $comment = strtolower((string) $pdo->query('SELECT @@version_comment')->fetchColumn());
        self::assertStringNotContainsString('mariadb', $version . $comment);
        self::assertSame('innodb', strtolower((string)
            $pdo->query('SELECT @@default_storage_engine')->fetchColumn()));
        $guardName = 'squehub_phase24_locks_' . substr(hash('sha256', $databaseName), 0, 32);
        $guard = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $guard->execute([$guardName]);
        self::assertSame(1, (int) $guard->fetchColumn(), 'Another SqueHub test owns this database.');
        $ownsSchema = false;
        try {
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM),
                'The confirmed disposable database must be empty.');
            $ownsSchema = true;
            require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_10_02_create_reliability_locks.php';
            (new \CreateReliabilityLocks())->up($pdo, $connection->schema());
            try {
                $manager = new LockManager(new DatabaseLockStore($connection),
                    'phase24-mysql', new SystemModelClock(), 'database', true);
                $held = $manager->acquire('shared', 30);
                self::assertTrue($held->acquired());
                self::assertSame('busy', $this->childAttempt($settings));
                self::assertTrue($held->release());
                self::assertSame('acquired', $this->childAttempt($settings));
            } finally {
                (new \CreateReliabilityLocks())->down($pdo, $connection->schema());
            }
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM));
        } finally {
            try {
                if ($ownsSchema) $connection->schema()->dropIfExists('reliability_locks');
            } finally {
                try {
                    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([$guardName]);
                } catch (Throwable) {
                    // Closing PDO also releases this test-only advisory guard.
                }
                $database->disconnect();
            }
        }
    }

    /** @param array<string,mixed> $settings */
    private function childAttempt(array $settings): string
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$settings = ['driver' => 'mysql', 'host' => getenv('SQUEHUB_TEST_MYSQL_HOST'),
    'port' => getenv('SQUEHUB_TEST_MYSQL_PORT'),
    'database' => getenv('SQUEHUB_TEST_MYSQL_DATABASE'),
    'username' => getenv('SQUEHUB_TEST_MYSQL_USER'),
    'password' => getenv('SQUEHUB_TEST_MYSQL_PASSWORD'), 'charset' => 'utf8mb4'];
$database = new App\Database\DatabaseManager(new App\Config\Repository(['database' => [
    'default' => 'test', 'connections' => ['test' => $settings],
]]));
$manager = new App\Locks\LockManager(new App\Locks\Stores\DatabaseLockStore($database->connection()),
    'phase24-mysql', new App\Database\SystemModelClock(), 'database', true);
$handle = $manager->acquire('shared', 30);
echo $handle->acquired() ? 'acquired' : 'busy';
if ($handle->acquired()) $handle->release();
$database->disconnect();
PHP;
        $process = new Process([PHP_BINARY, '-r', $code], dirname(__DIR__, 2), [
            'SQUEHUB_TEST_MYSQL_HOST' => $settings['host'],
            'SQUEHUB_TEST_MYSQL_PORT' => (string) $settings['port'],
            'SQUEHUB_TEST_MYSQL_DATABASE' => $settings['database'],
            'SQUEHUB_TEST_MYSQL_USER' => $settings['username'],
            'SQUEHUB_TEST_MYSQL_PASSWORD' => $settings['password'],
        ]);
        $process->run();
        self::assertTrue($process->isSuccessful(), 'Live MySQL lock child failed.');
        return trim($process->getOutput());
    }

    private function required(string $key): string
    {
        $value = getenv($key);
        self::assertIsString($value, "Set {$key} for the disposable MySQL lock test.");
        self::assertNotSame('', $value, "Set {$key} for the disposable MySQL lock test.");
        return $value;
    }
}
