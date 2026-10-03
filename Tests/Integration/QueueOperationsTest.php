<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Auth\Auth;
use App\Auth\AuthException;
use App\Auth\AuthManager;
use App\Auth\PasswordHasher;
use App\Database\DatabaseManager;
use App\Diagnostics\Diagnostics;
use App\Foundation\Application;
use App\Http\Request;
use App\Queue\Drivers\DatabaseQueueDriver;
use App\Queue\QueueException;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
use App\Queue\Queue;
use App\Queue\RestartSignal;
use App\Queue\Worker;
use App\Support\RuntimeContext;
use App\Session\SessionManager;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Small job with explicit JSON state for transaction and retry tests. */
final class OperationalJob implements QueueJob
{
    public static array $handled = [];
    public static ?\Closure $afterHandle = null;
    public function __construct(private string $value, private bool $fail = false,
        private int $sleepMs = 0) {}
    public function handle(): void
    {
        if ($this->sleepMs > 0) usleep($this->sleepMs * 1000);
        self::$handled[] = $this->value;
        if (self::$afterHandle !== null) (self::$afterHandle)();
        if ($this->fail) throw new \RuntimeException('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK');
    }
    public function toQueuePayload(): array
    {
        return ['value' => $this->value, 'fail' => $this->fail, 'sleep' => $this->sleepMs];
    }
    public static function fromQueuePayload(array $payload): static
    {
        return new static((string) $payload['value'], (bool) $payload['fail'], (int) ($payload['sleep'] ?? 0));
    }
}

/** Real SQLite transaction, failed-job, and worker-control integration. */
final class QueueOperationsTest extends TestCase
{
    private TemporaryProject $project;
    private DatabaseManager $databases;
    private QueueManager $queue;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->project = new TemporaryProject();
        $this->databases = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => [
                'test' => ['driver' => 'sqlite', 'database' => $this->project->path('test.sqlite')],
                'other' => ['driver' => 'sqlite', 'database' => $this->project->path('other.sqlite')],
            ],
        ]]));
        $db = $this->databases->connection();
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_25_add_failed_queue_payload.php';
        (new \CreateQueueTables())->up($db->pdo(), $db->schema());
        (new \AddFailedQueuePayload())->up($db->pdo(), $db->schema());
        $this->queue = new QueueManager(['default' => 'database', 'connections' => [
            'database' => ['driver' => 'database', 'database_connection' => 'test', 'retry_after' => 2],
            'sync' => ['driver' => 'sync'],
        ]], fn (?string $name) => $this->databases->connection($name));
        OperationalJob::$handled = [];
        OperationalJob::$afterHandle = null;
    }

    protected function tearDown(): void
    {
        if (isset($this->databases)) $this->databases->disconnect();
        if (isset($this->project)) $this->project->remove();
    }

    private function countJobs(): int
    {
        return (int) $this->databases->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn();
    }

    public function testAfterCommitImmediateCommitRollbackAndNestedSavepoints(): void
    {
        $db = $this->databases->connection();
        $this->queue->afterCommit(new OperationalJob('outside'));
        self::assertSame(1, $this->countJobs());
        $db->begin();
        $this->queue->afterCommit(new OperationalJob('outer'), connection: 'sync');
        $db->begin();
        $this->queue->afterCommit(new OperationalJob('inner'), connection: 'sync');
        $db->commit();
        self::assertSame([], OperationalJob::$handled);
        $db->rollback();
        self::assertSame([], OperationalJob::$handled);
        $db->transaction(function () use ($db): void {
            $this->queue->afterCommit(new OperationalJob('A'), connection: 'sync');
            $db->transaction(function (): void {
                $this->queue->afterCommit(new OperationalJob('B'), connection: 'sync');
            });
            $this->queue->afterCommit(new OperationalJob('C'), connection: 'sync');
        });
        self::assertSame(['A', 'B', 'C'], OperationalJob::$handled);
    }

    public function testInnerRollbackDiscardsOnlyItsCallbacksAndConnectionScopesRemainIndependent(): void
    {
        $test = $this->databases->connection();
        $other = $this->databases->connection('other');
        $test->begin();
        $other->begin();
        $this->queue->afterCommit(new OperationalJob('test'), connection: 'sync');
        $this->queue->afterCommit(new OperationalJob('other'), connection: 'sync',
            transactionConnection: 'other');
        $test->begin();
        $this->queue->afterCommit(new OperationalJob('discard'), connection: 'sync');
        $test->rollback();
        $other->commit();
        self::assertSame(['other'], OperationalJob::$handled);
        $test->commit();
        self::assertSame(['other', 'test'], OperationalJob::$handled);
    }

    public function testPostCommitFailureCannotUndoCommitAndLaterCallbacksRun(): void
    {
        $db = $this->databases->connection();
        $db->raw('CREATE TABLE business (`id` INTEGER PRIMARY KEY)');
        try {
            $db->transaction(function () use ($db): void {
                $db->raw('INSERT INTO business (`id`) VALUES (1)');
                $db->afterCommit(static function (): void { throw new QueueException('safe failure'); });
                $this->queue->afterCommit(new OperationalJob('later'), connection: 'sync');
            });
            self::fail('A failed post-commit callback should be reported.');
        } catch (QueueException $exception) {
            self::assertSame('safe failure', $exception->getMessage());
        }
        self::assertSame(1, (int) $db->raw('SELECT COUNT(*) FROM business')->fetchColumn());
        self::assertSame(['later'], OperationalJob::$handled);
    }

    public function testQueuePersistenceFailureAfterCommitReportsWithoutUndoingBusinessWrite(): void
    {
        $db = $this->databases->connection();
        $db->raw('CREATE TABLE business (`id` INTEGER PRIMARY KEY)');
        try {
            $db->transaction(function () use ($db): void {
                $db->raw('INSERT INTO business (`id`) VALUES (1)');
                $this->queue->afterCommit(new OperationalJob('cannot-queue'));
                $db->raw('DROP TABLE `queue_jobs`');
            });
            self::fail('Post-commit persistence failure was not reported.');
        } catch (QueueException $exception) {
            self::assertSame('Queue dispatch failed after database commit.', $exception->getMessage());
        }
        self::assertSame(1, (int) $db->raw('SELECT COUNT(*) FROM business')->fetchColumn());
    }

    public function testFailedJobRetryForgetPruneAndPrivacy(): void
    {
        $driver = $this->queue->driver();
        self::assertInstanceOf(DatabaseQueueDriver::class, $driver);
        $this->queue->dispatch(new OperationalJob('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK', true));
        (new Worker($this->queue))->workOnce(tries: 1);
        self::assertSame(0, $this->countJobs());
        $rows = $driver->failed();
        self::assertCount(1, $rows);
        self::assertStringNotContainsString('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK', json_encode($rows));
        $id = $rows[0]['id'];
        self::assertTrue($driver->retry($id));
        self::assertFalse($driver->retry($id));
        self::assertSame(1, $this->countJobs());
        (new Worker($this->queue))->workOnce(tries: 1);
        self::assertSame(0, $driver->prune(1));
        self::assertCount(1, $driver->failed());
        self::assertTrue($driver->forget($driver->failed()[0]['id']));
        self::assertFalse($driver->forget($id));
    }

    public function testWorkerMaxJobsMemoryAndRestartSignal(): void
    {
        $signal = new RestartSignal($this->project->path('Storage/Queue'));
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request('GET', '/queue-test'));
        $worker = new Worker($this->queue, $diagnostics, null, $signal);
        $this->queue->dispatch(new OperationalJob('one'));
        $this->queue->dispatch(new OperationalJob('two'));
        self::assertSame(1, $worker->run(maxJobs: 1));
        self::assertSame('max_jobs', $worker->exitReason());
        self::assertSame(1, $diagnostics->snapshot()['queue']['worker_starts']);
        self::assertSame(1, $diagnostics->snapshot()['queue']['worker_stops']);
        $memoryWorker = new Worker($this->queue);
        self::assertSame(0, $memoryWorker->run(once: true, memory: 1));
        self::assertSame('memory', $memoryWorker->exitReason());
        $signal->mark();
        self::assertSame(32, strlen($signal->current()));
        self::assertSame(1, (new Worker($this->queue, null, null, $signal))->run(once: true));
        self::assertSame(['one', 'two'], OperationalJob::$handled);
    }

    public function testStopWhenEmptyProcessesAvailableWorkWithoutSleepingForFutureJobs(): void
    {
        $this->queue->dispatch(new OperationalJob('ready'));
        $this->queue->dispatch(new OperationalJob('later'), delay: 60);
        $worker = new Worker($this->queue);
        self::assertSame(1, $worker->run(stopWhenEmpty: true, sleep: 10));
        self::assertSame('empty', $worker->exitReason());
        self::assertSame(['ready'], OperationalJob::$handled);
        self::assertSame(0, $worker->run(stopWhenEmpty: true));
        self::assertSame('empty', $worker->exitReason());
    }

    public function testDatabaseStatusSeparatesReadyDelayedLeasedAndFailedRecords(): void
    {
        $driver = $this->queue->driver();
        self::assertInstanceOf(DatabaseQueueDriver::class, $driver);
        $this->queue->dispatch(new OperationalJob('ready'));
        $this->queue->dispatch(new OperationalJob('delayed'), delay: 60);
        $this->queue->dispatch(new OperationalJob('leased'));
        $leased = $driver->reserve('default');
        self::assertNotNull($leased);
        $this->queue->dispatch(new OperationalJob('failure', true), queue: 'other');
        self::assertTrue((new Worker($this->queue))->workOnce('other', tries: 1));
        $status = $driver->status('default');
        self::assertSame([1, 1, 1, 1],
            [$status->ready, $status->delayed, $status->reserved, $status->failed]);
        self::assertSame(0, $driver->status('other')->ready);
        $driver->acknowledge($leased);
        self::assertSame(0, $driver->status('default')->reserved);
    }

    public function testWorkerSelectsItsOwnApplicationForEveryJobAttempt(): void
    {
        $otherProject = new TemporaryProject();
        try {
            $workerApp = new Application($this->project->path());
            $otherApp = new Application($otherProject->path());
            $workerApp->container()->instance(QueueManager::class, $this->queue);
            $authConfig = new Repository(['auth' => ['default' => null, 'guards' => [], 'identities' => []]]);
            $auth = new AuthManager($authConfig, new SessionManager($authConfig),
                new PasswordHasher($authConfig));
            $workerApp->container()->instance(AuthManager::class, $auth);
            $auth->beginRequest(new Request('GET', '/stale-before-worker'));
            RuntimeContext::select($otherApp);
            $selected = [];
            OperationalJob::$afterHandle = static function () use ($workerApp, $auth, &$selected): void {
                $requestCleared = false;
                try { $auth->selectGuardForRequest('token'); }
                catch (AuthException $exception) {
                    $requestCleared = $exception->getMessage()
                        === 'A request guard cannot be selected outside HTTP handling.';
                }
                $selected[] = [
                    Queue::manager() === $workerApp->container()->make(QueueManager::class),
                    Auth::manager() === $auth,
                    $requestCleared,
                ];
                // Simulate application job code binding request state. The
                // worker must clear it before another reservation is handled.
                $auth->beginRequest(new Request('GET', '/stale-from-job'));
            };
            $this->queue->dispatch(new OperationalJob('first'));
            $this->queue->dispatch(new OperationalJob('second'));
            $worker = new Worker($this->queue, container: $workerApp->container());
            self::assertSame(1, $worker->run(once: true));
            RuntimeContext::select($otherApp);
            self::assertSame(1, $worker->run(once: true));
            self::assertSame(['first', 'second'], OperationalJob::$handled);
            self::assertSame([[true, true, true], [true, true, true]], $selected);
            try {
                $auth->selectGuardForRequest('token');
                self::fail('Worker retained authentication request state.');
            } catch (AuthException $exception) {
                self::assertSame('A request guard cannot be selected outside HTTP handling.',
                    $exception->getMessage());
            }
            $queueOnly = new Application($otherProject->path());
            $queueOnly->container()->instance(QueueManager::class, $this->queue);
            self::assertFalse($queueOnly->container()->has(AuthManager::class));
            self::assertFalse((new Worker($this->queue, container: $queueOnly->container()))->workOnce());
            self::assertFalse($queueOnly->container()->has(AuthManager::class));
        } finally {
            OperationalJob::$afterHandle = null;
            $otherProject->remove();
        }
    }

    public function testRetryCorruptionAndPersistenceFailurePreserveFailedRecord(): void
    {
        $driver = $this->queue->driver();
        self::assertInstanceOf(DatabaseQueueDriver::class, $driver);
        $this->queue->dispatch(new OperationalJob('fail', true));
        (new Worker($this->queue))->workOnce(tries: 1);
        $id = $driver->failed()[0]['id'];
        $db = $this->databases->connection();
        $original = (string) $db->raw('SELECT `payload` FROM `queue_failed_jobs` WHERE `id` = ?', [$id])
            ->fetchColumn();
        $db->raw('UPDATE `queue_failed_jobs` SET `payload` = ? WHERE `id` = ?', ['not-json', $id]);
        try { $driver->retry($id); self::fail('Corrupt payload accepted.'); }
        catch (QueueException) { self::assertCount(1, $driver->failed()); }
        $db->raw('UPDATE `queue_failed_jobs` SET `payload` = ? WHERE `id` = ?', [$original, $id]);
        $db->raw('DROP TABLE `queue_jobs`');
        try { $driver->retry($id); self::fail('Missing Queue table accepted.'); }
        catch (\App\Database\Exception\QueryException) { self::assertCount(1, $driver->failed()); }
        self::assertSame(0, $driver->prune(168));
        $db->raw('UPDATE `queue_failed_jobs` SET `failed_at` = ? WHERE `id` = ?', ['2000-01-01 00:00:00', $id]);
        self::assertSame(1, $driver->prune(168));
        self::assertSame([], $driver->failed());
    }

    public function testRestartStopsBeforeTheNextClaimAndMaxTimeStopsIdleWorker(): void
    {
        $signal = new RestartSignal($this->project->path('Storage/Queue'));
        OperationalJob::$afterHandle = static function () use ($signal): void { $signal->mark(); };
        $this->queue->dispatch(new OperationalJob('first'));
        $this->queue->dispatch(new OperationalJob('second'));
        $worker = new Worker($this->queue, null, null, $signal);
        self::assertSame(1, $worker->run(maxJobs: 3));
        self::assertSame('restart', $worker->exitReason());
        self::assertSame(1, $this->countJobs());
        OperationalJob::$afterHandle = null;
        $idle = new Worker($this->queue);
        self::assertSame(1, $idle->run(maxTime: 1));
        self::assertSame('max_time', $idle->exitReason());
    }

    public function testSoftOrHardTimeoutFollowsOrdinaryFailedAttemptPolicy(): void
    {
        $this->queue->dispatch(new OperationalJob('slow', sleepMs: 1200));
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request('GET', '/queue-test'));
        $worker = new Worker($this->queue, $diagnostics);
        self::assertTrue($worker->workOnce(tries: 1, timeout: 1));
        self::assertSame(0, $this->countJobs());
        $driver = $this->queue->driver();
        self::assertInstanceOf(DatabaseQueueDriver::class, $driver);
        self::assertCount(1, $driver->failed());
        self::assertSame(1, $diagnostics->snapshot()['queue']['timeouts']);
    }

    public function testFatalWorkerInfrastructureFailureSetsSafeExitReason(): void
    {
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request('GET', '/queue-test'));
        $worker = new Worker($this->queue, $diagnostics);
        try {
            $worker->run(connection: 'sync', once: true);
            self::fail('Sync Queue unexpectedly supported worker polling.');
        } catch (QueueException) {
            self::assertSame('error', $worker->exitReason());
            self::assertSame(1, $diagnostics->snapshot()['queue']['worker_stops']);
        }
    }
}
