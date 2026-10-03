<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Container\Container;
use App\Database\DatabaseManager;
use App\Database\ModelClock;
use App\Diagnostics\Diagnostics;
use App\Foundation\Application;
use App\Http\Request;
use App\Plugins;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
use App\Scheduler\Schedule;
use App\Scheduler\Scheduler;
use App\Scheduler\SchedulerServiceProvider;
use App\Scheduler\Stores\DatabaseScheduleStore;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Mutable UTC time controls recurrence, expiry, and Queue delay without sleeps. */
final class SchedulerTestClock implements ModelClock
{
    private DateTimeImmutable $time;
    public function __construct() { $this->time = new DateTimeImmutable('2026-09-24 12:00:00', new DateTimeZone('UTC')); }
    public function now(): DateTimeImmutable { return $this->time; }
    public function advance(int $seconds): void { $this->time = $this->time->modify('+' . $seconds . ' seconds'); }
}

/** A durable job probe distinguishes dispatch from execution. */
final class SchedulerProbeJob implements QueueJob
{
    public static int $handled = 0;
    public function __construct(private string $marker = 'safe') {}
    public function handle(): void { ++self::$handled; }
    public function toQueuePayload(): array { return ['marker' => $this->marker]; }
    public static function fromQueuePayload(array $payload): static { return new static($payload['marker']); }
}

/** A synchronous Queue dispatch failure must stay within one schedule entry. */
final class SchedulerFailJob implements QueueJob
{
    public function handle(): void { throw new \RuntimeException('secret-in-queue-job'); }
    public function toQueuePayload(): array { return ['secret' => 'secret-in-queue-job']; }
    public static function fromQueuePayload(array $payload): static { return new static(); }
}

/** Container resolution for class-based synchronous schedules. */
final class SchedulerCallableProbe
{
    public function __construct(private SchedulerCallCounter $counter) {}
    public function run(): void { ++$this->counter->count; }
}

final class SchedulerCallCounter { public int $count = 0; }

/** Real SQLite claims, overlap, Queue dispatch, privacy, and provider boundaries. */
final class SchedulerDatabaseTest extends TestCase
{
    private TemporaryProject $project;
    private DatabaseManager $database;
    private SchedulerTestClock $clock;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Scheduler integration.');
        }
        $this->project = new TemporaryProject();
        $this->clock = new SchedulerTestClock();
        $this->database = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => [
                'driver' => 'sqlite', 'database' => $this->project->path('schedule.sqlite'),
            ]],
        ]]));
        $connection = $this->database->connection();
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_schedule_tables.php';
        (new \CreateScheduleTables())->up($connection->pdo(), $connection->schema());
        SchedulerProbeJob::$handled = 0;
    }

    protected function tearDown(): void
    {
        Schedule::setResolver(null);
        if (isset($this->database)) $this->database->disconnect();
        if (isset($this->project)) $this->project->remove();
    }

    private function scheduler(?Container $container = null, ?QueueManager $queue = null,
        ?Diagnostics $diagnostics = null): Scheduler
    {
        return new Scheduler($container ?? new Container(), [
            'store' => 'database', 'timezone' => 'UTC', 'prefix' => 'test',
            'database_connection' => 'test',
        ], fn (?string $name) => $this->database->connection($name),
            $queue === null ? null : fn () => $queue, $this->clock, $diagnostics);
    }

    public function testOneOccurrenceIsClaimedOnceAcrossIndependentSchedulers(): void
    {
        $first = $this->scheduler();
        $second = $this->scheduler();
        $count = 0;
        $first->call(function () use (&$count): void { ++$count; })->name('once')->everyMinute();
        $second->call(function () use (&$count): void { ++$count; })->name('once')->everyMinute();
        self::assertSame(1, $first->run()->executed);
        self::assertSame(1, $second->run()->skipped);
        self::assertSame(1, $count);
        $this->clock->advance(60);
        self::assertSame(1, $second->run()->executed);
        self::assertSame(2, $count);
        self::assertSame(2, (int) $this->database->raw('SELECT COUNT(*) FROM `schedule_runs`')->fetchColumn());
    }

    public function testOverlapExpiryTokenFencingAndUnrelatedTask(): void
    {
        $scheduler = $this->scheduler();
        $count = 0;
        $protected = $scheduler->call(function () use (&$count): void { ++$count; })
            ->name('protected')->everyMinute()->withoutOverlapping(30);
        $other = 0;
        $scheduler->call(function () use (&$other): void { ++$other; })->name('other')->everyMinute();
        $store = new DatabaseScheduleStore($this->database->connection());
        $key = $protected->taskKey('test');
        $old = $store->acquireOverlap($key, $this->clock->now(), 30);
        self::assertNotNull($old);
        $result = $scheduler->run();
        self::assertSame(1, $result->skipped);
        self::assertSame(1, $other);
        self::assertSame(0, $count);
        $this->clock->advance(30);
        $new = $store->acquireOverlap($key, $this->clock->now(), 30);
        self::assertNotNull($new);
        self::assertNotSame($old, $new);
        $store->releaseOverlap($key, $old);
        self::assertNull($store->acquireOverlap($key, $this->clock->now(), 30));
        $store->releaseOverlap($key, $new);
        self::assertSame(1, $scheduler->run()->executed);
        self::assertSame(1, $count);
        self::assertSame(2, $scheduler->run()->skipped);
        $this->clock->advance(60);
        self::assertSame(2, $scheduler->run()->executed);
        self::assertSame(2, $count);
    }

    public function testFailureIsPrivateAndDoesNotStopAnotherDueTask(): void
    {
        $scheduler = $this->scheduler();
        $ran = false;
        $scheduler->call(static function (): void { throw new \RuntimeException('secret-in-task-exception'); })
            ->name('private-failure')->everyMinute();
        $scheduler->call(function () use (&$ran): void { $ran = true; })->name('later')->everyMinute();
        $result = $scheduler->run();
        self::assertSame(1, $result->failed);
        self::assertSame(1, $result->executed);
        self::assertTrue($ran);
        self::assertFalse($result->successful());
        $rows = $this->database->raw('SELECT * FROM `schedule_runs`')->fetchAll();
        self::assertCount(2, $rows);
        $statuses = array_column($rows, 'status');
        sort($statuses);
        self::assertSame(['completed', 'failed'], $statuses);
        self::assertStringNotContainsString('secret-in-task-exception', json_encode($rows));
        self::assertStringNotContainsString('private-failure', json_encode($rows));
        self::assertSame(2, $scheduler->run()->skipped);
    }

    public function testDatabaseQueueDispatchIsDelayedAndNotExecutedByScheduler(): void
    {
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
        (new \CreateQueueTables())->up($this->database->connection()->pdo(), $this->database->connection()->schema());
        $queue = new QueueManager(['default' => 'sync', 'connections' => [
            'sync' => ['driver' => 'sync'],
            'database' => ['driver' => 'database', 'database_connection' => 'test'],
        ]], fn (?string $name) => $this->database->connection($name), $this->clock);
        $scheduler = $this->scheduler(queue: $queue);
        $scheduler->job(new SchedulerProbeJob('secret-in-job-payload'), connection: 'database',
            queue: 'reports', delay: 30)->name('report')->everyMinute();
        $result = $scheduler->run();
        self::assertSame(1, $result->queued);
        self::assertSame(0, SchedulerProbeJob::$handled);
        $row = $this->database->raw('SELECT `queue`,`available_at` FROM `queue_jobs`')->fetch();
        self::assertSame('reports', $row['queue']);
        self::assertSame('2026-09-24 12:00:30', $row['available_at']);
        self::assertSame(1, $scheduler->run()->skipped);
        $otherProcessDefinition = $this->scheduler(queue: $queue);
        $otherProcessDefinition->job(new SchedulerProbeJob('different-in-memory-instance'),
            connection: 'database', queue: 'reports', delay: 30)->name('report')->everyMinute();
        self::assertSame(1, $otherProcessDefinition->run()->skipped);
        self::assertSame(1, (int) $this->database->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
        $runMetadata = $this->database->raw('SELECT * FROM `schedule_runs`')->fetchAll();
        self::assertStringNotContainsString('secret-in-job-payload', json_encode($runMetadata));
    }

    public function testSynchronousQueueJobDelegatesToQueue(): void
    {
        $queue = new QueueManager(['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]]);
        $scheduler = $this->scheduler(queue: $queue);
        $scheduler->job(new SchedulerProbeJob(), connection: 'sync')->name('sync-job')->everyMinute();
        self::assertSame(1, $scheduler->run()->queued);
        self::assertSame(1, SchedulerProbeJob::$handled);
    }

    public function testScheduledQueueDispatchUpdatesBothAggregateSections(): void
    {
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request());
        $queue = new QueueManager(['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
            null, $this->clock, $diagnostics);
        $scheduler = $this->scheduler(queue: $queue, diagnostics: $diagnostics);
        $scheduler->job(new SchedulerProbeJob(), connection: 'sync')->name('diagnosed-job')->everyMinute();
        self::assertSame(1, $scheduler->run()->queued);
        $snapshot = $diagnostics->snapshot();
        self::assertSame(1, $snapshot['scheduler']['queued']);
        self::assertSame(1, $snapshot['queue']['dispatched']);
        self::assertSame(1, SchedulerProbeJob::$handled);
    }

    public function testMissingRunTableFailsSafelyWithoutExecutingTask(): void
    {
        $this->database->schema()->drop('schedule_runs');
        $scheduler = $this->scheduler();
        $called = false;
        $scheduler->call(function () use (&$called): void { $called = true; })->name('not-run')->everyMinute();
        $result = $scheduler->run();
        self::assertSame(1, $result->failed);
        self::assertFalse($called);
    }

    public function testFailedQueueDispatchMarksOccurrenceAndContinues(): void
    {
        $queue = new QueueManager(['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]]);
        $scheduler = $this->scheduler(queue: $queue);
        $called = false;
        $scheduler->job(new SchedulerFailJob(), connection: 'sync')->name('failed-dispatch')->everyMinute();
        $scheduler->call(function () use (&$called): void { $called = true; })->name('later-call')->everyMinute();
        $result = $scheduler->run();
        self::assertSame(1, $result->failed);
        self::assertSame(1, $result->executed);
        self::assertSame(0, $result->queued);
        self::assertTrue($called);
        self::assertStringNotContainsString('secret-in-queue-job',
            json_encode($this->database->raw('SELECT * FROM `schedule_runs`')->fetchAll()));
    }

    public function testAllDueChecksUseOneCapturedEvaluationInstant(): void
    {
        $calls = 0;
        $clock = new class implements ModelClock {
            public int $reads = 0;
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable(++$this->reads === 1
                    ? '2026-09-24 12:00:59' : '2026-09-24 12:01:00', new DateTimeZone('UTC'));
            }
        };
        $scheduler = new Scheduler(new Container(), ['store' => 'array'], null, null, $clock);
        $scheduler->call(function () use (&$calls): void { ++$calls; })->name('due')->dailyAt('12:00');
        $scheduler->call(function () use (&$calls): void { ++$calls; })->name('next-minute')->dailyAt('12:01');
        $result = $scheduler->run();
        self::assertSame(1, $result->due);
        self::assertSame(1, $result->executed);
        self::assertSame(1, $calls);
        self::assertGreaterThanOrEqual(2, $clock->reads);
    }

    public function testClassCallAndDiagnosticsStayApplicationLocal(): void
    {
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request());
        $container = new Container();
        $counter = new SchedulerCallCounter();
        $container->instance(SchedulerCallCounter::class, $counter);
        $scheduler = new Scheduler($container, ['store' => 'array'], null, null, $this->clock, $diagnostics);
        $scheduler->call([SchedulerCallableProbe::class, 'run'])->name('secret-task-name')->everyMinute();
        self::assertSame(1, $scheduler->run()->executed);
        self::assertSame(1, $counter->count);
        $snapshot = $diagnostics->snapshot()['scheduler'];
        self::assertSame(1, $snapshot['evaluated']);
        self::assertSame(1, $snapshot['executed']);
        self::assertStringNotContainsString('secret-task-name', json_encode($snapshot));
        $diagnostics->begin(new Request());
        self::assertSame(0, $diagnostics->snapshot()['scheduler']['evaluated']);
        $other = new Scheduler(new Container(), ['store' => 'array'], null, null, $this->clock);
        self::assertCount(0, $other->definitions());
    }

    public function testProviderBootDoesNotRunTasksOrOpenDatabase(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Scheduler.php', '<?php return ["store"=>"array","timezone"=>"UTC"];');
            $app = new Application($project->path());
            $app->register(SchedulerServiceProvider::class);
            $app->bootstrap();
            $service = $app->container()->make(Scheduler::class);
            self::assertSame($service, schedule());
            self::assertSame($service, Plugins\Schedule::manager());
            Plugins\Schedule::call(static fn () => null)->name('registered')->everyMinute();
            self::assertCount(1, $service->definitions());
            self::assertCount(0, $this->scheduler()->definitions());
        } finally {
            $project->remove();
        }
    }
}
