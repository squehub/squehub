<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Broadcasting\Adapters\ArrayBroadcastAdapter;
use App\Broadcasting\Broadcast;
use App\Broadcasting\BroadcastAdapter;
use App\Broadcasting\BroadcastEvent;
use App\Broadcasting\BroadcastException;
use App\Broadcasting\BroadcastManager;
use App\Broadcasting\BroadcastMessage;
use App\Broadcasting\BroadcastServiceProvider;
use App\Broadcasting\Channel;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Foundation\Application;
use App\Queue\QueueManager;
use App\Queue\QueueServiceProvider;
use App\Queue\Worker;
use App\Support\RuntimeContext;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real SQLite Queue delivery, retries, commit timing, and Application isolation. */
final class BroadcastIntegrationTest extends TestCase
{
    private TemporaryProject $project;
    private array $applications = [];

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for broadcast Queue integration.');
        }
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', '<?php return ["env"=>"production","debug"=>false];');
        $this->project->write('Config/Database.php', '<?php return ["default"=>"test","connections"=>'
            . '["test"=>["driver"=>"sqlite","database"=>'
            . var_export($this->project->path('broadcast.sqlite'), true) . ']]];');
        $this->project->write('Config/Queue.php', '<?php return ["default"=>"database","connections"=>'
            . '["database"=>["driver"=>"database","database_connection"=>"test","retry_after"=>30],'
            . '"sync"=>["driver"=>"sync"]]];');
        $this->project->write('Config/Broadcasting.php', '<?php return ["enabled"=>true,"driver"=>"array"];');
    }

    protected function tearDown(): void
    {
        Broadcast::setResolver(null);
        \App\Queue\Queue::setResolver(null);
        foreach ($this->applications as $app) {
            $app->container()->make(DatabaseManager::class)->disconnect();
        }
        if (isset($this->project)) $this->project->remove();
    }

    private function application(bool $migrate = false, string $driver = 'array'): Application
    {
        $this->project->write('Config/Broadcasting.php', '<?php return ["enabled"=>true,"driver"=>'
            . var_export($driver, true) . '];');
        $app = new Application($this->project->path());
        foreach ([DatabaseServiceProvider::class, QueueServiceProvider::class,
            BroadcastServiceProvider::class] as $provider) $app->register($provider);
        $app->bootstrap();
        $this->applications[] = $app;
        if ($migrate) {
            require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
            $connection = $app->container()->make(DatabaseManager::class)->connection();
            (new \CreateQueueTables())->up($connection->pdo(), $connection->schema());
        }
        return $app;
    }

    private function rowCount(Application $app, string $table): int
    {
        return (int) $app->container()->make(DatabaseManager::class)->connection()
            ->raw('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    private function event(array $payload = ['order_id' => 42]): BroadcastQueueFixture
    {
        return new BroadcastQueueFixture('order.updated', [Channel::public('orders')], $payload);
    }

    public function testDatabaseQueueSurvivesApplicationRestartBeforeWorkerDelivery(): void
    {
        $producer = $this->application(true);
        $producerManager = $producer->container()->make(BroadcastManager::class);
        RuntimeContext::select($producer);
        \App\Plugins\Broadcast::queue($this->event(), queue: 'realtime', connection: 'database');
        self::assertSame(1, $this->rowCount($producer, 'queue_jobs'));
        self::assertSame([], $producerManager->adapter()->messages());

        $consumer = $this->application();
        self::assertTrue($consumer->container()->make(Worker::class)
            ->workOnce('realtime', 'database'));
        self::assertSame(0, $this->rowCount($consumer, 'queue_jobs'));
        $outbox = $consumer->container()->make(BroadcastManager::class)->adapter();
        self::assertInstanceOf(ArrayBroadcastAdapter::class, $outbox);
        self::assertCount(1, $outbox->messages());
        self::assertSame(['order_id' => 42], $outbox->messages()[0]->payload());
        self::assertSame([], $producerManager->adapter()->messages());
    }

    public function testQueueRetryAndFailureMetadataDoNotExposeAdapterSecret(): void
    {
        $app = $this->application(true, 'flaky');
        $manager = $app->container()->make(BroadcastManager::class);
        $adapter = new FlakyBroadcastAdapter();
        $manager->registerAdapter('flaky', $adapter);
        $manager->queue($this->event(), queue: 'retry');
        $worker = $app->container()->make(Worker::class);
        self::assertTrue($worker->workOnce('retry', tries: 2, backoff: 0));
        self::assertSame(1, $adapter->attempts);
        self::assertSame(1, $this->rowCount($app, 'queue_jobs'));
        self::assertTrue($worker->workOnce('retry', tries: 2, backoff: 0));
        self::assertSame(2, $adapter->attempts);
        self::assertCount(1, $adapter->messages);
        self::assertSame(0, $this->rowCount($app, 'queue_jobs'));

        $failing = $this->application(driver: 'failing');
        $failingManager = $failing->container()->make(BroadcastManager::class);
        $failingManager->registerAdapter('failing', new AlwaysFailBroadcastAdapter());
        $failingManager->queue($this->event(), queue: 'fail');
        self::assertTrue($failing->container()->make(Worker::class)->workOnce('fail', tries: 1));
        $row = $failing->container()->make(DatabaseManager::class)->connection()
            ->raw('SELECT job_class, error_type, error_message FROM queue_failed_jobs')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('Job failed after maximum attempts.', $row['error_message']);
        self::assertStringNotContainsString('SQUEHUB_BROADCAST_SECRET_DO_NOT_LEAK', json_encode($row));
    }

    public function testAfterCommitWaitsForCommitAndRollbackDropsPublication(): void
    {
        $app = $this->application(true);
        $database = $app->container()->make(DatabaseManager::class)->connection();
        $manager = $app->container()->make(BroadcastManager::class);
        $database->begin();
        $manager->queue($this->event(), afterCommit: true);
        self::assertSame(0, $this->rowCount($app, 'queue_jobs'));
        $database->rollback();
        self::assertSame(0, $this->rowCount($app, 'queue_jobs'));
        $database->begin();
        $manager->queue($this->event(), afterCommit: true);
        $database->commit();
        self::assertSame(1, $this->rowCount($app, 'queue_jobs'));
        self::assertSame([], $manager->adapter()->messages());
        self::assertTrue($app->container()->make(Worker::class)->workOnce());
        self::assertCount(1, $manager->adapter()->messages());
    }

    public function testTwoApplicationsHaveIndependentArrayOutboxesAndPluginSelection(): void
    {
        $first = $this->application(true);
        $second = $this->application();
        RuntimeContext::select($first);
        \App\Plugins\Broadcast::send($this->event(['owner' => 'first']));
        RuntimeContext::select($second);
        \App\Plugins\Broadcast::send($this->event(['owner' => 'second']));
        $firstOutbox = $first->container()->make(BroadcastManager::class)->adapter();
        $secondOutbox = $second->container()->make(BroadcastManager::class)->adapter();
        self::assertNotSame($firstOutbox, $secondOutbox);
        self::assertSame('first', $firstOutbox->messages()[0]->payload()['owner']);
        self::assertSame('second', $secondOutbox->messages()[0]->payload()['owner']);
    }

    public function testQueueRequiresQueueServiceButPublicSyncDoesNot(): void
    {
        $app = new Application($this->project->path());
        $app->register(BroadcastServiceProvider::class);
        $app->bootstrap();
        $manager = $app->container()->make(BroadcastManager::class);
        $manager->send($this->event());
        self::assertCount(1, $manager->adapter()->messages());
        $this->expectException(BroadcastException::class);
        $manager->queue($this->event());
    }
}

/** A JSON-safe application event; the object itself is never queued. */
final class BroadcastQueueFixture implements BroadcastEvent
{
    public function __construct(private string $name, private array $channels, private array $payload) {}
    public function broadcastName(): string { return $this->name; }
    public function broadcastChannels(): array { return $this->channels; }
    public function broadcastPayload(): array { return $this->payload; }
}

/** First attempt fails so Queue's normal retry path can be observed. */
final class FlakyBroadcastAdapter implements BroadcastAdapter
{
    public int $attempts = 0;
    public array $messages = [];
    public function publish(BroadcastMessage $message): void
    {
        ++$this->attempts;
        if ($this->attempts === 1) throw new RuntimeException('SQUEHUB_BROADCAST_SECRET_DO_NOT_LEAK');
        $this->messages[] = $message;
    }
}

/** Exhausted retry metadata must remain generic even when an adapter leaks. */
final class AlwaysFailBroadcastAdapter implements BroadcastAdapter
{
    public function publish(BroadcastMessage $message): void
    {
        throw new RuntimeException('SQUEHUB_BROADCAST_SECRET_DO_NOT_LEAK');
    }
}
