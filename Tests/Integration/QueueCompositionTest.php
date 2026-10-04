<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Diagnostics\Diagnostics;
use App\Http\Request;
use App\Plugins;
use App\Queue\Composition\CompositionStatus;
use App\Queue\Drivers\DatabaseQueueDriver;
use App\Queue\QueueCodec;
use App\Queue\QueueException;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
use App\Queue\Worker;
use PDO;
use PHPUnit\Framework\TestCase;

/** Serializable probe with process-local observation, never stored as a closure. */
final class CompositionTestJob implements QueueJob
{
    /** @var list<string> */
    public static array $handled = [];
    /** @var array<string,int> */
    public static array $failures = [];
    public static ?\Closure $onHandle = null;

    public function __construct(private string $label) {}

    public function handle(): void
    {
        self::$handled[] = $this->label;
        self::$onHandle?->__invoke($this->label);
        if ((self::$failures[$this->label] ?? 0) > 0) {
            --self::$failures[$this->label];
            throw new \RuntimeException('SQUEHUB_COMPOSITION_SECRET_DO_NOT_LEAK');
        }
    }

    public function toQueuePayload(): array { return ['label' => $this->label]; }

    public static function fromQueuePayload(array $payload): static
    {
        return new static((string) $payload['label']);
    }
}

/** Real SQLite lifecycle coverage for persisted chains and batches. */
final class QueueCompositionTest extends TestCase
{
    private DatabaseManager $databases;
    private QueueManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Queue composition.');
        }
        $this->databases = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        $connection = $this->databases->connection();
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_25_add_failed_queue_payload.php';
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_30_create_queue_compositions.php';
        (new \CreateQueueTables())->up($connection->pdo(), $connection->schema());
        (new \AddFailedQueuePayload())->up($connection->pdo(), $connection->schema());
        (new \CreateQueueCompositions())->up($connection->pdo(), $connection->schema());
        $this->manager = new QueueManager(['default' => 'database', 'connections' => [
            'database' => ['driver' => 'database', 'database_connection' => 'test', 'retry_after' => 1],
            'sync' => ['driver' => 'sync'],
            'auto' => ['driver' => 'auto'],
        ]], fn (?string $name) => $this->databases->connection($name));
        CompositionTestJob::$handled = [];
        CompositionTestJob::$failures = [];
        CompositionTestJob::$onHandle = null;
    }

    protected function tearDown(): void
    {
        CompositionTestJob::$onHandle = null;
        if (isset($this->databases)) $this->databases->disconnect();
    }

    /** @return non-empty-list<CompositionTestJob> */
    private function jobs(): array
    {
        return [new CompositionTestJob('A'), new CompositionTestJob('B'), new CompositionTestJob('C')];
    }

    private function driver(): DatabaseQueueDriver
    {
        $driver = $this->manager->driver('database');
        self::assertInstanceOf(DatabaseQueueDriver::class, $driver);
        return $driver;
    }

    public function testThreeJobChainAdvancesOnlyAfterEachAcknowledgement(): void
    {
        $handle = $this->manager->chain($this->jobs());
        self::assertSame('database', $handle->connection);
        self::assertSame(1, $this->jobCount());
        $worker = new Worker($this->manager);
        foreach ([1, 2, 3] as $number) {
            self::assertTrue($worker->workOnce());
            self::assertSame($number, $handle->status()->succeeded);
            self::assertSame(intdiv(100 * $number, 3), $handle->status()->progress());
            self::assertSame($number === 3 ? 0 : 1, $this->jobCount());
        }
        self::assertSame(['A', 'B', 'C'], CompositionTestJob::$handled);
        self::assertSame('completed', $handle->status()->state);
        self::assertFalse($worker->workOnce());
    }

    public function testRetryDoesNotAdvanceOrCountTwice(): void
    {
        CompositionTestJob::$failures['A'] = 1;
        $handle = $this->manager->chain($this->jobs());
        $worker = new Worker($this->manager);
        self::assertTrue($worker->workOnce(tries: 2, backoff: 0));
        self::assertSame(0, $handle->status()->succeeded);
        self::assertSame(1, $this->jobCount());
        self::assertTrue($worker->workOnce(tries: 2, backoff: 0));
        self::assertSame(1, $handle->status()->succeeded);
        self::assertSame(1, $this->jobCount());
        self::assertTrue($worker->workOnce());
        self::assertTrue($worker->workOnce());
        self::assertSame(['A', 'A', 'B', 'C'], CompositionTestJob::$handled);
        self::assertSame('completed', $handle->status()->state);
    }

    public function testPermanentFailureStopsChainAndOperationalRetryIsStandalone(): void
    {
        CompositionTestJob::$failures['A'] = 1;
        $handle = $this->manager->chain($this->jobs());
        self::assertTrue((new Worker($this->manager))->workOnce(tries: 1));
        $status = $handle->status();
        self::assertSame('failed', $status->state);
        self::assertSame(1, $status->failed);
        self::assertSame(2, $status->cancelled);
        self::assertSame(100, $status->progress());
        self::assertSame(0, $this->jobCount());
        $failed = $this->driver()->failed();
        self::assertCount(1, $failed);
        self::assertStringNotContainsString('SQUEHUB_COMPOSITION_SECRET_DO_NOT_LEAK',
            json_encode($failed, JSON_THROW_ON_ERROR));
        self::assertTrue($this->driver()->retry($failed[0]['id']));
        $reserved = $this->driver()->reserve('default');
        self::assertNotNull($reserved);
        self::assertNull($reserved->compositionId);
        self::assertInstanceOf(CompositionTestJob::class, QueueCodec::decode($reserved->payload));
        $this->driver()->acknowledge($reserved);
        self::assertSame('failed', $handle->status()->state);
    }

    public function testBatchContinuesAfterFailureAndProgressIncludesFailure(): void
    {
        CompositionTestJob::$failures['B'] = 1;
        $handle = $this->manager->batch($this->jobs());
        self::assertSame(3, $this->jobCount());
        $worker = new Worker($this->manager);
        self::assertTrue($worker->workOnce(tries: 1));
        self::assertTrue($worker->workOnce(tries: 1));
        self::assertTrue($worker->workOnce(tries: 1));
        $status = $handle->status();
        self::assertSame('completed_with_failures', $status->state);
        self::assertSame(2, $status->succeeded);
        self::assertSame(1, $status->failed);
        self::assertSame(0, $status->pending());
        self::assertSame(100, $status->progress());
    }

    public function testBatchRetryCountsOnlyItsTerminalOutcome(): void
    {
        CompositionTestJob::$failures['B'] = 1;
        $handle = $this->manager->batch($this->jobs());
        $worker = new Worker($this->manager);
        self::assertTrue($worker->workOnce(tries: 2, backoff: 0));
        self::assertTrue($worker->workOnce(tries: 2, backoff: 0));
        self::assertSame(1, $handle->status()->succeeded);
        self::assertSame(0, $handle->status()->failed);
        self::assertSame(2, $handle->status()->pending());
        self::assertTrue($worker->workOnce(tries: 2, backoff: 0));
        self::assertTrue($worker->workOnce(tries: 2, backoff: 0));
        self::assertSame('completed', $handle->status()->state);
        self::assertSame(3, $handle->status()->succeeded);
        self::assertSame(0, $handle->status()->failed);
    }

    public function testCrashBeforeAcknowledgementCanRepeatSideEffectsButCannotDuplicateAdvance(): void
    {
        $handle = $this->manager->chain($this->jobs());
        $first = $this->driver()->reserve('default');
        self::assertNotNull($first);
        QueueCodec::decode($first->payload)->handle();
        // Simulate a dead worker's expired lease after its handler side effect.
        $this->databases->connection()->raw('UPDATE `queue_jobs`'
            . ' SET `reserved_at` = ? WHERE `id` = ?', ['2000-01-01 00:00:00', $first->id]);
        self::assertTrue((new Worker($this->manager))->workOnce());
        self::assertSame(['A', 'A'], CompositionTestJob::$handled);
        self::assertSame(1, $handle->status()->succeeded);
        self::assertSame(1, $this->jobCount());
        $this->expectException(QueueException::class);
        $this->driver()->acknowledge($first);
    }

    public function testCancellationRemovesUnreservedJobsAndStopsChainAdvancement(): void
    {
        $batch = $this->manager->batch($this->jobs());
        self::assertTrue($batch->cancel());
        self::assertSame('cancelled', $batch->status()->state);
        self::assertSame(3, $batch->status()->cancelled);
        self::assertSame(0, $this->jobCount());
        self::assertFalse((new Worker($this->manager))->workOnce());

        $chain = $this->manager->chain($this->jobs());
        $reserved = $this->driver()->reserve('default');
        self::assertNotNull($reserved);
        self::assertTrue($chain->cancel());
        self::assertSame('cancelling', $chain->status()->state);
        self::assertFalse($this->driver()->shouldRunComposition($reserved));
        $this->driver()->skipComposition($reserved);
        self::assertSame('cancelled', $chain->status()->state);
        self::assertSame(3, $chain->status()->cancelled);
        self::assertFalse($chain->cancel());
    }

    public function testCancellationDuringHandlerAllowsOwnedSuccessWithoutNextStep(): void
    {
        $chain = $this->manager->chain($this->jobs());
        CompositionTestJob::$onHandle = static function (string $label) use ($chain): void {
            if ($label === 'A') $chain->cancel();
        };
        self::assertTrue((new Worker($this->manager))->workOnce());
        self::assertSame('cancelled', $chain->status()->state);
        self::assertSame(1, $chain->status()->succeeded);
        self::assertSame(2, $chain->status()->cancelled);
        self::assertSame(0, $this->jobCount());
    }

    public function testSyncResultsAndApplicationIsolation(): void
    {
        $success = $this->manager->chain($this->jobs(), connection: 'sync');
        self::assertSame('completed', $success->status()->state);
        self::assertSame(['A', 'B', 'C'], CompositionTestJob::$handled);
        CompositionTestJob::$failures['B'] = 1;
        $failure = $this->manager->batch($this->jobs(), connection: 'sync');
        self::assertSame('completed_with_failures', $failure->status()->state);
        self::assertSame(2, $failure->status()->succeeded);
        self::assertSame(1, $failure->status()->failed);
        $other = new QueueManager(['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]]);
        self::assertNull($other->compositionStatus($success->id));
        self::assertFalse($success->cancel());
        self::assertInstanceOf(CompositionStatus::class, $failure->status());
    }

    public function testPluginsAndFixedConnectionValidation(): void
    {
        \App\Queue\Queue::setResolver(fn (): QueueManager => $this->manager);
        try {
            $handle = Plugins\Queue::chain($this->jobs(), connection: 'sync');
            self::assertInstanceOf(Plugins\CompositionHandle::class, $handle);
            self::assertInstanceOf(Plugins\CompositionStatus::class,
                Plugins\Queue::composition($handle->id, 'sync'));
            self::assertFalse(Plugins\Queue::cancelComposition($handle->id, 'sync'));
        } finally {
            \App\Queue\Queue::setResolver(null);
        }
        $this->expectException(QueueException::class);
        $this->manager->chain($this->jobs(), connection: 'auto');
    }

    public function testDiagnosticsCountAcceptedCompositionsWithoutPayloads(): void
    {
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request('GET', '/composition'));
        $manager = new QueueManager(['default' => 'sync', 'connections' => [
            'sync' => ['driver' => 'sync'],
        ]], null, null, $diagnostics);
        $manager->chain($this->jobs());
        CompositionTestJob::$failures['B'] = 1;
        $manager->batch($this->jobs());
        $queue = $diagnostics->snapshot()['queue'];
        self::assertSame(2, $queue['dispatched']);
        self::assertSame(1, $queue['failed']);
        self::assertGreaterThanOrEqual(0.0, $queue['time_ms']);
        self::assertStringNotContainsString('SQUEHUB_COMPOSITION_SECRET_DO_NOT_LEAK',
            json_encode($diagnostics->snapshot(), JSON_THROW_ON_ERROR));
    }

    public function testMigrationDownLeavesEarlierQueueTablesUntouched(): void
    {
        $connection = $this->databases->connection();
        (new \CreateQueueCompositions())->down($connection->pdo(), $connection->schema());
        self::assertTrue($connection->schema()->hasTable('queue_jobs'));
        self::assertTrue($connection->schema()->hasTable('queue_failed_jobs'));
        self::assertFalse($connection->schema()->hasTable('queue_compositions'));
        self::assertFalse($connection->schema()->hasColumn('queue_jobs', 'composition_id'));
    }

    public function testMigrationDownRefusesActiveCompositionWithoutChangingSchema(): void
    {
        $handle = $this->manager->chain($this->jobs());
        $connection = $this->databases->connection();
        try {
            (new \CreateQueueCompositions())->down($connection->pdo(), $connection->schema());
            self::fail('An active chain must prevent schema rollback.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Cannot roll back Queue composition schema while work is active.',
                $exception->getMessage());
        }
        self::assertSame('active', $handle->status()->state);
        self::assertTrue($connection->schema()->hasColumn('queue_jobs', 'composition_id'));
        self::assertTrue($connection->schema()->hasTable('queue_composition_items'));
        self::assertSame(1, $this->jobCount());
    }

    public function testPruneRemovesOnlyOldTerminalMetadata(): void
    {
        $done = $this->manager->chain($this->jobs(), connection: 'database');
        $worker = new Worker($this->manager);
        self::assertTrue($worker->workOnce());
        self::assertTrue($worker->workOnce());
        self::assertTrue($worker->workOnce());
        self::assertSame('completed', $done->status()->state);
        $active = $this->manager->chain($this->jobs(), connection: 'database');
        $this->databases->connection()->raw('UPDATE `queue_compositions`'
            . ' SET `finished_at` = ? WHERE `id` = ?', ['2000-01-01 00:00:00', $done->id]);
        self::assertSame(1, $this->manager->pruneCompositions(1, 'database'));
        self::assertNull($this->manager->compositionStatus($done->id, 'database'));
        self::assertSame('active', $active->status()->state);
        self::assertSame(0, $this->manager->pruneCompositions(1, 'database'));
    }

    private function jobCount(): int
    {
        return (int) $this->databases->connection()->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn();
    }
}
