<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Schema\Schema;
use App\Queue\Drivers\DatabaseQueueDriver;
use App\Queue\QueueException;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
use App\Queue\Worker;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** An explicit JSON job whose optional file records process-boundary execution. */
final class MySqlCompositionJob implements QueueJob
{
    public function __construct(private string $label, private ?string $output = null) {}

    public function handle(): void
    {
        if ($this->output !== null) {
            if (file_put_contents($this->output, $this->label . "\n", FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('The composition test output is unavailable.');
            }
        }
    }

    public function toQueuePayload(): array
    {
        return ['label' => $this->label, 'output' => $this->output];
    }

    public static function fromQueuePayload(array $payload): static
    {
        return new static((string) $payload['label'],
            is_string($payload['output'] ?? null) ? $payload['output'] : null);
    }
}

/**
 * Qualifies the additive Queue composition migration and guarded settlement
 * only on a confirmed, initially empty disposable MySQL/InnoDB database.
 * This test never reads the normal application database configuration.
 *
 * @group mysql
 */
final class MySqlQueueCompositionOptInTest extends TestCase
{
    public function testMigrationAndAtomicCompositionOnConfirmedDisposableMySql(): void
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

        $configuration = ['driver' => 'mysql', 'host' => $host, 'port' => $port,
            'database' => $database, 'username' => $user, 'password' => $password,
            'charset' => 'utf8mb4'];
        $producerDatabase = $this->databaseManager($configuration);
        $producer = $producerDatabase->connection();
        $pdo = $producer->pdo();
        self::assertSame($database, $pdo->query('SELECT DATABASE()')->fetchColumn());
        $version = strtolower((string) $pdo->query('SELECT VERSION()')->fetchColumn());
        $comment = strtolower((string) $pdo->query('SELECT @@version_comment')->fetchColumn());
        self::assertStringNotContainsString('mariadb', $version . $comment);
        self::assertSame('innodb', strtolower((string) $pdo->query('SELECT @@default_storage_engine')->fetchColumn()));

        $lockName = 'squehub_phase6a_' . substr(hash('sha256', $database), 0, 40);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        self::assertSame(1, (int) $lock->fetchColumn(), 'Another SqueHub MySQL test is using this database.');
        $consumerDatabase = null;
        try {
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM),
                'The confirmed test database must be empty before this test may create tables.');
            require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
            require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_25_add_failed_queue_payload.php';
            require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_30_create_queue_compositions.php';
            $queueMigration = new \CreateQueueTables();
            $failedPayloadMigration = new \AddFailedQueuePayload();
            $compositionMigration = new \CreateQueueCompositions();
            try {
                $schema = $producer->schema();
                $queueMigration->up($pdo, $schema);
                $failedPayloadMigration->up($pdo, $schema);
                $compositionMigration->up($pdo, $schema);
                $this->assertSchema($pdo, $schema);

                // A second manager uses its own PDO and proves that composition
                // state survives the dispatching Application's process state.
                $consumerDatabase = $this->databaseManager($configuration);
                $producerQueue = $this->queueManager($producerDatabase);
                $consumerQueue = $this->queueManager($consumerDatabase);
                $chain = $producerQueue->chain([
                    new MySqlCompositionJob('A'), new MySqlCompositionJob('B'),
                    new MySqlCompositionJob('C'),
                ]);
                self::assertSame(1, $this->countJobs($pdo));
                $worker = new Worker($consumerQueue);
                foreach ([1, 2, 3] as $completed) {
                    self::assertTrue($worker->workOnce());
                    self::assertSame($completed, $producerQueue->compositionStatus($chain->id)?->succeeded);
                    self::assertSame($completed === 3 ? 0 : 1, $this->countJobs($pdo));
                }
                self::assertSame('completed', $chain->status()->state);
                self::assertFalse($worker->workOnce());

                $this->assertReservationFenceAndCancellation($producerQueue, $consumerQueue);
                $this->assertCoordinatedWorkerClaim($producerQueue, $configuration);
                $this->assertTransactionRollback($producerDatabase, $producerQueue, $pdo);

                $compositionMigration->down($pdo, $schema);
                $failedPayloadMigration->down($pdo, $schema);
                $queueMigration->down($pdo, $schema);
                self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM));
            } finally {
                // Only the initially empty, explicitly confirmed database is
                // owned here. Cleanup handles partially completed MySQL DDL.
                foreach (['queue_composition_items', 'queue_compositions',
                    'queue_failed_jobs', 'queue_jobs'] as $table) {
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
            $consumerDatabase?->disconnect();
            $producerDatabase->disconnect();
        }
    }

    private function assertSchema(PDO $pdo, Schema $schema): void
    {
        foreach (['queue_jobs', 'queue_failed_jobs',
            'queue_compositions', 'queue_composition_items'] as $table) {
            self::assertTrue($schema->hasTable($table), $table);
            $engine = $pdo->prepare('SELECT ENGINE AS engine FROM INFORMATION_SCHEMA.TABLES'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $engine->execute([$table]);
            self::assertSame('innodb', strtolower((string) $engine->fetchColumn()), $table);
        }
        self::assertTrue($schema->hasColumn('queue_jobs', 'composition_id'));
        self::assertTrue($schema->hasColumn('queue_jobs', 'composition_position'));
        foreach ([['queue_jobs', 'queue_jobs_composition_unique'],
            ['queue_compositions', 'queue_compositions_finished'],
            ['queue_composition_items', 'queue_composition_job_unique']] as [$table, $indexName]) {
            self::assertTrue($schema->hasIndex($table, $indexName), "{$table}.{$indexName}");
        }
    }

    private function assertReservationFenceAndCancellation(QueueManager $producer,
        QueueManager $consumer): void
    {
        $batch = $producer->batch([new MySqlCompositionJob('fenced')]);
        $first = $producer->driver('database');
        $second = $consumer->driver('database');
        self::assertInstanceOf(DatabaseQueueDriver::class, $first);
        self::assertInstanceOf(DatabaseQueueDriver::class, $second);
        $reserved = $first->reserve('default');
        self::assertNotNull($reserved);
        self::assertNull($second->reserve('default'));
        $first->acknowledge($reserved);
        self::assertSame(1, $batch->status()->succeeded);
        try {
            $first->acknowledge($reserved);
            self::fail('A replayed acknowledgement must not settle twice.');
        } catch (QueueException) {
            self::assertSame(1, $batch->status()->succeeded);
        }

        $chain = $producer->chain([new MySqlCompositionJob('cancelled-first'),
            new MySqlCompositionJob('cancelled-second')]);
        $reserved = $second->reserve('default');
        self::assertNotNull($reserved);
        self::assertTrue($chain->cancel());
        self::assertFalse($second->shouldRunComposition($reserved));
        $second->skipComposition($reserved);
        self::assertSame('cancelled', $chain->status()->state);
        self::assertSame(2, $chain->status()->cancelled);
    }

    /** Two ready workers race after a barrier; one durable job can settle once. */
    private function assertCoordinatedWorkerClaim(QueueManager $producer, array $configuration): void
    {
        $output = tempnam(sys_get_temp_dir(), 'squehub-mysql-composition-');
        self::assertIsString($output);
        $readyA = $output . '.ready-a';
        $readyB = $output . '.ready-b';
        $go = $output . '.go';
        $first = null;
        $second = null;
        $code = <<<'PHP'
require 'vendor/autoload.php';
require 'Tests/Integration/MySqlQueueCompositionOptInTest.php';
$settings = ['driver' => 'mysql', 'host' => getenv('SQUEHUB_TEST_MYSQL_HOST'),
    'port' => getenv('SQUEHUB_TEST_MYSQL_PORT'),
    'database' => getenv('SQUEHUB_TEST_MYSQL_DATABASE'),
    'username' => getenv('SQUEHUB_TEST_MYSQL_USER'),
    'password' => getenv('SQUEHUB_TEST_MYSQL_PASSWORD'), 'charset' => 'utf8mb4'];
$database = new App\Database\DatabaseManager(new App\Config\Repository(['database' => [
    'default' => 'disposable', 'connections' => ['disposable' => $settings],
]]));
$queue = new App\Queue\QueueManager(['default' => 'database', 'connections' => [
    'database' => ['driver' => 'database', 'database_connection' => 'disposable', 'retry_after' => 5],
]], static fn (?string $name) => $database->connection($name));
file_put_contents(getenv('SQUEHUB_TEST_READY'), 'ready');
$deadline = microtime(true) + 10;
while (!is_file(getenv('SQUEHUB_TEST_GO'))) {
    if (microtime(true) >= $deadline) exit(2);
    usleep(10000);
}
echo (new App\Queue\Worker($queue))->workOnce() ? '1' : '0';
PHP;
        try {
            $batch = $producer->batch([new MySqlCompositionJob('once', $output)]);
            $environment = ['SQUEHUB_TEST_MYSQL_HOST' => (string) $configuration['host'],
                'SQUEHUB_TEST_MYSQL_PORT' => (string) $configuration['port'],
                'SQUEHUB_TEST_MYSQL_DATABASE' => (string) $configuration['database'],
                'SQUEHUB_TEST_MYSQL_USER' => (string) $configuration['username'],
                'SQUEHUB_TEST_MYSQL_PASSWORD' => (string) $configuration['password'],
                'SQUEHUB_TEST_GO' => $go];
            $first = new Process([PHP_BINARY, '-r', $code], dirname(__DIR__, 2),
                $environment + ['SQUEHUB_TEST_READY' => $readyA]);
            $second = new Process([PHP_BINARY, '-r', $code], dirname(__DIR__, 2),
                $environment + ['SQUEHUB_TEST_READY' => $readyB]);
            $first->start();
            $second->start();
            $deadline = microtime(true) + 10;
            while (!is_file($readyA) || !is_file($readyB)) {
                if (microtime(true) >= $deadline || !$first->isRunning() || !$second->isRunning()) {
                    self::fail('MySQL composition workers did not reach the synchronization gate.');
                }
                usleep(10000);
            }
            file_put_contents($go, 'go');
            $first->wait();
            $second->wait();
            self::assertTrue($first->isSuccessful() && $second->isSuccessful(),
                'Coordinated MySQL workers must exit successfully.');
            $outcomes = [trim($first->getOutput()), trim($second->getOutput())];
            sort($outcomes);
            self::assertSame(['0', '1'], $outcomes);
            self::assertSame("once\n", file_get_contents($output));
            self::assertSame('completed', $batch->status()->state);
            self::assertSame(1, $batch->status()->succeeded);
        } finally {
            if ($first?->isRunning()) $first->stop();
            if ($second?->isRunning()) $second->stop();
            foreach ([$readyA, $readyB, $go, $output] as $path) {
                if (is_file($path)) unlink($path);
            }
        }
    }

    private function assertTransactionRollback(DatabaseManager $database, QueueManager $queue,
        PDO $pdo): void
    {
        $id = null;
        try {
            $database->connection()->transaction(function () use ($queue, &$id): void {
                $id = $queue->chain([new MySqlCompositionJob('rolled-back')])->id;
                throw new RuntimeException('Roll back the test-owned composition.');
            });
            self::fail('The caller-owned transaction must roll back.');
        } catch (RuntimeException $exception) {
            self::assertSame('Roll back the test-owned composition.', $exception->getMessage());
        }
        self::assertIsString($id);
        self::assertNull($queue->compositionStatus($id));
        self::assertSame(0, $this->countJobs($pdo));
    }

    private function databaseManager(array $configuration): DatabaseManager
    {
        return new DatabaseManager(new Repository(['database' => [
            'default' => 'disposable', 'connections' => ['disposable' => $configuration],
        ]]));
    }

    private function queueManager(DatabaseManager $database): QueueManager
    {
        return new QueueManager(['default' => 'database', 'connections' => [
            'database' => ['driver' => 'database', 'database_connection' => 'disposable',
                'retry_after' => 5],
        ]], static fn (?string $name) => $database->connection($name));
    }

    private function countJobs(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn();
    }

    private function requiredEnvironment(string $key): string
    {
        $value = getenv($key);
        self::assertIsString($value, "Set {$key} for the disposable MySQL test.");
        self::assertNotSame('', $value, "Set {$key} for the disposable MySQL test.");
        return $value;
    }
}
