<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Events\EventDispatcher;
use App\Events\EventException;
use App\Events\EventServiceProvider;
use App\Events\QueueableEvent;
use App\Foundation\Application;
use App\Http\Request;
use App\Queue\QueueServiceProvider;
use App\Queue\Worker;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real SQLite Queue and managed transaction coverage for deferred listeners. */
final class QueuedEventListenerIntegrationTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private DatabaseManager $database;
    private EventDispatcher $events;
    private Worker $worker;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for queued listener integration.');
        }
        $this->project = new TemporaryProject();
        $this->project->write('Config/Database.php', '<?php return ["default"=>"test",'
            . '"connections"=>["test"=>["driver"=>"sqlite","database"=>'
            . var_export($this->project->path('events.sqlite'), true) . ']]];');
        $this->project->write('Config/Queue.php', '<?php return ["default"=>"database",'
            . '"connections"=>["sync"=>["driver"=>"sync"],'
            . '"database"=>["driver"=>"database","database_connection"=>"test",'
            . '"retry_after"=>30]]];');
        $this->app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, EventServiceProvider::class,
            DatabaseServiceProvider::class, QueueServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $this->database = $this->app->container()->make(DatabaseManager::class);
        $this->events = $this->app->container()->make(EventDispatcher::class);
        $this->worker = $this->app->container()->make(Worker::class);
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
        (new \CreateQueueTables())->up($this->database->connection()->pdo(),
            $this->database->connection()->schema());
        QueuedIntegrationListener::$values = [];
        QueuedFlakyListener::$attempts = 0;
    }

    protected function tearDown(): void
    {
        \App\Events\Events::setResolver(null);
        \App\Queue\Queue::setResolver(null);
        \App\Database\Database::setResolver(null);
        if (isset($this->database)) $this->database->disconnect();
        if (isset($this->project)) $this->project->remove();
    }

    private function pending(): int
    {
        return (int) $this->database->connection()->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn();
    }

    public function testPersistentWorkerReconstructsOnlyQueuedListener(): void
    {
        $sync = 0;
        $this->events->listen(QueuedIntegrationSignal::class,
            static function () use (&$sync): void { ++$sync; });
        $this->events->listenQueued(QueuedIntegrationSignal::class,
            QueuedIntegrationListener::class);
        $this->events->emit(new QueuedIntegrationSignal('worker'));
        self::assertSame(1, $sync);
        self::assertSame([], QueuedIntegrationListener::$values);
        self::assertSame(1, $this->pending());
        self::assertTrue($this->worker->workOnce());
        self::assertSame(['worker'], QueuedIntegrationListener::$values);
        self::assertSame(1, $sync, 'Worker must not re-emit the event.');
        self::assertSame(0, $this->pending());
    }

    public function testDefaultAfterCommitWaitsForOutermostCommitAndDiscardsRollback(): void
    {
        $this->events->listenQueued(QueuedIntegrationSignal::class,
            QueuedIntegrationListener::class);
        $connection = $this->database->connection();
        $connection->begin();
        $this->events->emit(new QueuedIntegrationSignal('outer'));
        self::assertSame(0, $this->pending());
        $connection->begin();
        $this->events->emit(new QueuedIntegrationSignal('discarded'));
        $connection->rollback();
        $connection->begin();
        $this->events->emit(new QueuedIntegrationSignal('inner'));
        $connection->commit();
        self::assertSame(0, $this->pending());
        $connection->commit();
        self::assertSame(2, $this->pending());
        $this->worker->workOnce();
        $this->worker->workOnce();
        self::assertSame(['outer', 'inner'], QueuedIntegrationListener::$values);

        $connection->begin();
        $this->events->emit(new QueuedIntegrationSignal('rolled-back'));
        $connection->rollback();
        self::assertSame(0, $this->pending());
        self::assertSame(['outer', 'inner'], QueuedIntegrationListener::$values);
    }

    public function testQueuedFailureUsesQueueRetryAndSafeFailedMetadata(): void
    {
        $this->events->listenQueued(QueuedIntegrationSignal::class,
            QueuedFlakyListener::class, afterCommit: false);
        $this->events->emit(new QueuedIntegrationSignal('retry'));
        self::assertTrue($this->worker->workOnce(backoff: 0, tries: 2));
        self::assertSame(1, QueuedFlakyListener::$attempts);
        self::assertSame(1, $this->pending());
        self::assertTrue($this->worker->workOnce(backoff: 0, tries: 2));
        self::assertSame(2, QueuedFlakyListener::$attempts);
        self::assertSame(0, $this->pending());

        $this->events->listenQueued(QueuedIntegrationSignal::class,
            QueuedAlwaysFailListener::class, afterCommit: false);
        $secret = 'SQUEHUB_QUEUED_EVENT_SECRET_DO_NOT_LEAK';
        $this->events->emit(new QueuedIntegrationSignal($secret));
        // The first registered flaky listener is also scheduled.
        $this->worker->workOnce(tries: 1);
        $this->worker->workOnce(tries: 1);
        $rows = $this->database->connection()->raw('SELECT * FROM `queue_failed_jobs`')->fetchAll();
        self::assertCount(1, $rows);
        self::assertStringNotContainsString($secret, json_encode($rows));
    }

    public function testDiagnosticsNeverRetainQueuedEventPayload(): void
    {
        $diagnostics = $this->app->container()->make(Diagnostics::class);
        $diagnostics->begin(new Request());
        $this->events->listenQueued(QueuedIntegrationSignal::class,
            QueuedIntegrationListener::class);
        $secret = 'SQUEHUB_QUEUED_EVENT_SECRET_DO_NOT_LEAK';
        $this->events->emit(new QueuedIntegrationSignal($secret));
        self::assertSame(1, $this->pending());
        self::assertSame(0, $diagnostics->snapshot()['events']['listener_invocations']);
        self::assertSame(1, $diagnostics->snapshot()['queue']['dispatched']);
        self::assertStringNotContainsString($secret, json_encode($diagnostics->snapshot()));
    }

    public function testConfiguredQueuedListenerUsesPluginsGatewayWithoutDatabaseProvider(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Queue.php', '<?php return ["default"=>"sync",'
                . '"connections"=>["sync"=>["driver"=>"sync"]]];');
            $project->write('Config/Events.php', '<?php return ["listeners"=>['
                . var_export(QueuedIntegrationSignal::class, true) . '=>[['
                . '"listener"=>' . var_export(QueuedIntegrationListener::class, true)
                . ',"queued"=>true,"priority"=>5]]]];');
            $app = new Application($project->path());
            $app->register(EventServiceProvider::class);
            $app->register(QueueServiceProvider::class);
            $app->bootstrap();
            self::assertFalse($app->container()->has(DatabaseManager::class));
            $this->events->listenQueued(QueuedIntegrationSignal::class,
                QueuedIntegrationListener::class);
            $this->events->emit(new QueuedIntegrationSignal('first-application'));
            self::assertSame(1, $this->pending());
            self::assertSame([], QueuedIntegrationListener::$values);
            \App\Plugins\Event::emit(new QueuedIntegrationSignal('no-database'));
            self::assertSame(['no-database'], QueuedIntegrationListener::$values);
            $this->worker->workOnce();
            self::assertSame(['no-database', 'first-application'],
                QueuedIntegrationListener::$values);
        } finally {
            $project->remove();
        }
    }

    public function testConfiguredQueuedClosureIsRejectedDuringBoot(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Queue.php', '<?php return ["default"=>"sync",'
                . '"connections"=>["sync"=>["driver"=>"sync"]]];');
            $project->write('Config/Events.php', '<?php return ["listeners"=>['
                . var_export(QueuedIntegrationSignal::class, true) . '=>[['
                . '"listener"=>static function(): void {},"queued"=>true]]]];');
            $app = new Application($project->path());
            $app->register(EventServiceProvider::class);
            $app->register(QueueServiceProvider::class);
            $this->expectException(EventException::class);
            $app->bootstrap();
        } finally {
            $project->remove();
        }
    }
}

/** Event snapshots contain only the application-chosen scalar data. */
final class QueuedIntegrationSignal implements QueueableEvent
{
    public function __construct(public string $value) {}
    public function toQueuePayload(): array { return ['value' => $this->value]; }
    public static function fromQueuePayload(array $payload): static
    {
        return new static((string) ($payload['value'] ?? ''));
    }
}

/** Records successful delivery without a separate worker subsystem. */
final class QueuedIntegrationListener
{
    public static array $values = [];
    public function handle(QueuedIntegrationSignal $event): void
    {
        self::$values[] = $event->value;
    }
}

/** Fails once to exercise ordinary Queue backoff and retry. */
final class QueuedFlakyListener
{
    public static int $attempts = 0;
    public function handle(QueuedIntegrationSignal $event): void
    {
        if (++self::$attempts === 1) throw new \RuntimeException('transient listener error');
    }
}

/** Queue failure storage must omit application exception and event text. */
final class QueuedAlwaysFailListener
{
    public function handle(QueuedIntegrationSignal $event): void
    {
        throw new \RuntimeException($event->value);
    }
}
