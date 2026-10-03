<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Queue\Drivers\RedisQueueDriver;
use App\Queue\QueueCodec;
use App\Queue\QueueException;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
use App\Queue\ReservedJob;
use App\Queue\Worker;
use App\Redis\RedisClient;
use App\Redis\RedisManager;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\FakeRedisQueueClient;

require_once dirname(__DIR__) . '/Fixtures/FakeRedisQueueClient.php';

/** A real QueueJob keeps codec and Worker paths in the driver contract tests. */
final class RedisQueueProbeJob implements QueueJob
{
    public static array $handled = [];

    public function __construct(private array $data) {}
    public function handle(): void
    {
        self::$handled[] = $this->data;
        if ($this->data['fail'] ?? false) throw new \RuntimeException('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK');
    }
    public function toQueuePayload(): array { return $this->data; }
    public static function fromQueuePayload(array $payload): static { return new static($payload); }
}

/**
 * The command double verifies integration and state transitions without a
 * Redis installation. RedisQueueLiveTest executes the actual Lua when opted in.
 */
final class RedisQueueTest extends TestCase
{
    private FakeRedisQueueClient $client;
    private RedisManager $redis;
    private QueueManager $queue;

    protected function setUp(): void
    {
        $this->client = new FakeRedisQueueClient();
        $this->redis = new RedisManager(['default' => 'main', 'client' => 'auto',
            'connections' => ['main' => ['host' => 'localhost', 'prefix' => 'test:']]],
            null, fn (): RedisClient => $this->client,
            static fn (string $class): bool => $class === \Redis::class);
        $this->queue = new QueueManager(['default' => 'redis', 'connections' => [
            'redis' => ['driver' => 'redis', 'redis_connection' => 'main',
                'namespace' => 'app-one', 'retry_after' => 5],
            'auto' => ['driver' => 'auto', 'redis_connection' => 'main'],
        ]], null, null, null, $this->redis);
        RedisQueueProbeJob::$handled = [];
    }

    private function driver(): RedisQueueDriver
    {
        $driver = $this->queue->driver('redis');
        self::assertInstanceOf(RedisQueueDriver::class, $driver);
        return $driver;
    }

    public function testDispatchDelayNamedQueuesAndStableOrder(): void
    {
        $this->queue->dispatch(new RedisQueueProbeJob(['n' => 1]), 'reports', 10);
        $this->queue->dispatch(new RedisQueueProbeJob(['n' => 2]), 'reports', 10);
        $this->queue->dispatch(new RedisQueueProbeJob(['n' => 3]), 'default');
        self::assertNull($this->driver()->reserve('reports'));
        $third = $this->driver()->reserve('default');
        self::assertNotNull($third);
        self::assertSame(['n' => 3], QueueCodec::decode($third->payload)->toQueuePayload());
        $this->driver()->acknowledge($third);
        $this->client->now += 10;
        $first = $this->driver()->reserve('reports');
        $second = $this->driver()->reserve('reports');
        self::assertSame(['n' => 1], QueueCodec::decode($first->payload)->toQueuePayload());
        self::assertSame(['n' => 2], QueueCodec::decode($second->payload)->toQueuePayload());
        self::assertSame(1, $first->attempts);
        self::assertNull($this->driver()->reserve('reports'));
        $this->driver()->acknowledge($first);
        $this->driver()->acknowledge($second);
        self::assertSame([], array_filter($this->client->hashes));
    }

    public function testStatusUsesAtomicRedisSnapshotAndCountsExpiredLeasesAsReady(): void
    {
        $driver = $this->driver();
        $this->queue->dispatch(new RedisQueueProbeJob(['kind' => 'ready']));
        $this->queue->dispatch(new RedisQueueProbeJob(['kind' => 'delayed']), delay: 20);
        $this->queue->dispatch(new RedisQueueProbeJob(['kind' => 'leased']));
        $leased = $driver->reserve('default');
        self::assertNotNull($leased);
        $status = $driver->status('default');
        self::assertSame([1, 1, 1, 0],
            [$status->ready, $status->delayed, $status->reserved, $status->failed]);
        $this->client->now += 5;
        $expired = $driver->status('default');
        self::assertSame([2, 1, 0, 0],
            [$expired->ready, $expired->delayed, $expired->reserved, $expired->failed]);
    }

    public function testReleaseStaleRecoveryAndReservationFencing(): void
    {
        $this->queue->dispatch(new RedisQueueProbeJob(['id' => 1]));
        $first = $this->driver()->reserve('default');
        if (!$first instanceof ReservedJob) throw new \LogicException('Expected first reservation.');
        $this->driver()->release($first, 3);
        $this->client->now += 3;
        $second = $this->driver()->reserve('default');
        if (!$second instanceof ReservedJob) throw new \LogicException('Expected second reservation.');
        self::assertSame(2, $second->attempts);
        $this->client->now += 5;
        $third = $this->driver()->reserve('default');
        if (!$third instanceof ReservedJob) throw new \LogicException('Expected third reservation.');
        self::assertSame(3, $third->attempts);
        try {
            $this->driver()->acknowledge($second);
            self::fail('A stale reservation was acknowledged.');
        } catch (QueueException $exception) {
            self::assertStringContainsString('no longer owned', $exception->getMessage());
        }
        $this->driver()->acknowledge($third);
    }

    public function testWorkerRetriesFailureOperationsAndPrivacy(): void
    {
        $this->queue->dispatch(new RedisQueueProbeJob([
            'fail' => true, 'secret' => 'SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK']));
        $worker = new Worker($this->queue);
        self::assertSame(1, $worker->run(tries: 2, backoff: 2, once: true));
        self::assertSame(0, $worker->run(tries: 2, backoff: 2, once: true));
        $this->client->now += 2;
        self::assertSame(1, $worker->run(tries: 2, once: true));
        $failed = $this->driver()->failed();
        self::assertCount(1, $failed);
        self::assertSame(2, $failed[0]['attempts']);
        self::assertStringNotContainsString('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK', json_encode($failed));
        self::assertTrue($this->driver()->retry($failed[0]['id']));
        self::assertSame([], $this->driver()->failed());
        $retry = $this->driver()->reserve('default');
        self::assertSame(1, $retry->attempts);
        $this->driver()->fail($retry, RedisQueueProbeJob::class, 'RuntimeException', 'Safe failure.');
        self::assertSame(0, $this->driver()->prune(1));
        $this->client->now += 3601;
        self::assertSame(1, $this->driver()->prune(1));
        self::assertSame([], $this->driver()->failed());
    }

    public function testDistributedRestartMarkerAndExplicitOutage(): void
    {
        $other = new RedisQueueDriver($this->redis->connection(), 'app-one');
        $one = $this->driver()->restartSignal();
        $two = $other->restartSignal();
        self::assertSame('', $one->current());
        $one->mark();
        self::assertSame($one->current(), $two->current());
        $isolated = (new RedisQueueDriver($this->redis->connection(), 'app-two'))->restartSignal();
        self::assertSame('', $isolated->current());
        $this->client->available = false;
        $this->expectException(QueueException::class);
        $this->queue->dispatch(new RedisQueueProbeJob(['id' => 1]));
    }

    public function testRedisOutageBeforeClaimDoesNotExecuteOrDiscardJob(): void
    {
        $this->queue->dispatch(new RedisQueueProbeJob(['id' => 7]));
        $this->client->available = false;
        try {
            (new Worker($this->queue))->workOnce();
            self::fail('Unavailable Redis allowed Queue execution.');
        } catch (QueueException $exception) {
            self::assertStringNotContainsString('fake endpoint with secret', $exception->getMessage());
            self::assertSame([], RedisQueueProbeJob::$handled);
        }
        $this->client->available = true;
        self::assertTrue((new Worker($this->queue))->workOnce());
        self::assertSame([['id' => 7]], RedisQueueProbeJob::$handled);
    }

    public function testQueueKeysDoNotTouchOtherRedisNamespaces(): void
    {
        $foreign = ['test:cache:opaque' => 'cache-value',
            'test:session:opaque' => 'session-value',
            'test:rate_limit:opaque' => 'rate-value'];
        $this->client->strings = $foreign;
        $this->queue->dispatch(new RedisQueueProbeJob(['id' => 8]));
        $reserved = $this->driver()->reserve('default');
        if (!$reserved instanceof ReservedJob) throw new \LogicException('Expected reservation.');
        $this->driver()->fail($reserved, RedisQueueProbeJob::class, 'RuntimeException', 'Safe.');
        $id = $this->driver()->failed()[0]['id'];
        self::assertTrue($this->driver()->forget($id));
        self::assertSame($foreign, $this->client->strings);
    }

    public function testAutoResolutionIsLazyAndPinnedToRedis(): void
    {
        $driver = $this->queue->driver('auto');
        self::assertInstanceOf(RedisQueueDriver::class, $driver);
        $selection = $this->queue->infrastructure('auto');
        if ($selection === null) throw new \LogicException('Expected Queue selection.');
        self::assertSame('redis', $selection->selected());
        $this->client->available = false;
        self::assertSame($driver, $this->queue->driver('auto'));
        $this->expectException(QueueException::class);
        $this->queue->dispatch(new RedisQueueProbeJob(['x' => 1]), connection: 'auto');
    }

    public function testNamedAutoDoesNotReuseDefaultSyncSelection(): void
    {
        $queue = new QueueManager(['default' => 'sync', 'connections' => [
            'sync' => ['driver' => 'sync'],
            'auto' => ['driver' => 'auto', 'redis_connection' => 'main'],
            'redis' => ['driver' => 'redis', 'namespace' => 'named-auto'],
            'database' => ['driver' => 'database'],
        ]], null, null, null, $this->redis);
        $queue->driver();
        self::assertSame('sync', $queue->infrastructure()->selected());
        $queue->dispatch(new RedisQueueProbeJob(['named' => true]), connection: 'auto');
        self::assertSame('redis', $queue->infrastructure('auto')->selected());
        self::assertSame([], RedisQueueProbeJob::$handled);
        $driver = $queue->driver('redis');
        self::assertInstanceOf(RedisQueueDriver::class, $driver);
        $job = $driver->reserve('default');
        if (!$job instanceof ReservedJob) throw new \LogicException('Expected named auto reservation.');
        self::assertSame(['named' => true], QueueCodec::decode($job->payload)->toQueuePayload());
        $driver->acknowledge($job);
    }

    public function testAutoWithoutRedisSelectsDatabaseAndNeverSync(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for Database Queue fallback.');
        }
        $database = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => [
                'driver' => 'sqlite', 'database' => ':memory:',
            ]],
        ]]));
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
        (new \CreateQueueTables())->up($database->connection()->pdo(), $database->connection()->schema());
        $unconfigured = new RedisManager(['default' => 'main', 'connections' => ['main' => []]]);
        $queue = new QueueManager(['default' => 'auto', 'connections' => [
            'auto' => ['driver' => 'auto'],
            'database' => ['driver' => 'database'],
            'sync' => ['driver' => 'sync'],
        ]], fn (?string $name) => $database->connection($name), null, null, $unconfigured);
        try {
            $queue->dispatch(new RedisQueueProbeJob(['fallback' => true]));
            $selection = $queue->infrastructure();
            if ($selection === null) throw new \LogicException('Expected Queue selection.');
            self::assertSame('database', $selection->selected());
            self::assertSame('not_configured', $selection->reason());
            self::assertSame([], RedisQueueProbeJob::$handled);
            self::assertSame(1, (int) $database->connection()->raw('SELECT COUNT(*) FROM `queue_jobs`')
                ->fetchColumn());
            self::assertTrue((new Worker($queue))->workOnce());
            self::assertSame([['fallback' => true]], RedisQueueProbeJob::$handled);
        } finally {
            $database->disconnect();
        }
    }

    public function testAfterCommitDefersRedisWriteAndRollbackDiscardsIt(): void
    {
        $database = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => [
                'driver' => 'sqlite', 'database' => ':memory:',
            ]],
        ]]));
        $connection = $database->connection();
        try {
            $queue = new QueueManager(['default' => 'auto', 'connections' => [
                'auto' => ['driver' => 'auto', 'redis_connection' => 'main'],
                'redis' => ['driver' => 'redis', 'namespace' => 'commit-test'],
                'database' => ['driver' => 'database'],
            ]], fn (?string $name) => $database->connection($name), null, null, $this->redis);
            $connection->begin();
            $queue->afterCommit(new RedisQueueProbeJob(['rollback' => true]));
            self::assertSame('redis', $queue->infrastructure()->selected());
            self::assertSame([], array_filter($this->client->hashes));
            $connection->rollback();
            self::assertSame([], array_filter($this->client->hashes));

            $connection->begin();
            $queue->afterCommit(new RedisQueueProbeJob(['commit' => true]));
            self::assertSame([], array_filter($this->client->hashes));
            $connection->commit();
            $driver = $queue->driver();
            if (!$driver instanceof RedisQueueDriver) throw new \LogicException('Expected Redis Queue.');
            $reserved = $driver->reserve('default');
            self::assertNotNull($reserved);
            if (!$reserved instanceof ReservedJob) throw new \LogicException('Expected reservation.');
            self::assertSame(['commit' => true], QueueCodec::decode($reserved->payload)->toQueuePayload());
            $driver->acknowledge($reserved);
        } finally {
            $database->disconnect();
        }
    }
}
