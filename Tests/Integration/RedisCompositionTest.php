<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Queue\Composition\CompositionStatus;
use App\Queue\Drivers\RedisQueueDriver;
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

/** A payload-safe job whose execution order can be observed without Redis. */
final class RedisCompositionProbeJob implements QueueJob
{
    public static array $handled = [];

    public function __construct(private int $step, private bool $fails = false,
        private string $marker = 'REDIS_COMPOSITION_SECRET_DO_NOT_LEAK') {}

    public function handle(): void
    {
        self::$handled[] = $this->step;
        if ($this->fails) throw new \RuntimeException('REDIS_COMPOSITION_SECRET');
    }

    public function toQueuePayload(): array
    {
        return ['step' => $this->step, 'fails' => $this->fails, 'marker' => $this->marker];
    }

    public static function fromQueuePayload(array $payload): static
    {
        return new static((int) $payload['step'], (bool) $payload['fails'],
            (string) $payload['marker']);
    }
}

/**
 * Fake Redis exercises the composition driver's Queue/Worker integration.
 * A guarded companion test runs the same scripts against a live Redis server.
 */
final class RedisCompositionTest extends TestCase
{
    private FakeRedisQueueClient $client;
    private RedisManager $redis;
    private QueueManager $queue;

    protected function setUp(): void
    {
        $this->client = new FakeRedisQueueClient();
        $this->redis = new RedisManager(['default' => 'main', 'client' => 'auto',
            'connections' => ['main' => ['host' => 'localhost', 'prefix' => 'composition-test:']]],
            null, fn (): RedisClient => $this->client,
            static fn (string $class): bool => $class === \Redis::class);
        $this->queue = $this->manager('composition-one');
        RedisCompositionProbeJob::$handled = [];
    }

    private function manager(string $namespace): QueueManager
    {
        return new QueueManager(['default' => 'redis', 'connections' => [
            'redis' => ['driver' => 'redis', 'redis_connection' => 'main',
                'namespace' => $namespace, 'retry_after' => 5],
        ]], null, null, null, $this->redis);
    }

    private function driver(): RedisQueueDriver
    {
        $driver = $this->queue->driver();
        self::assertInstanceOf(RedisQueueDriver::class, $driver);
        return $driver;
    }

    private function reserved(): ReservedJob
    {
        $job = $this->driver()->reserve('default');
        self::assertInstanceOf(ReservedJob::class, $job);
        return $job;
    }

    private function counts(CompositionStatus $status): array
    {
        return [$status->state, $status->succeeded, $status->failed,
            $status->cancelled, $status->pending(), $status->progress()];
    }

    /** Inspect private backend metadata; public status tests cannot detect retained payload copies. */
    private function metadata(string $id): array
    {
        foreach ($this->client->strings as $key => $value) {
            if (str_ends_with($key, ':composition:' . $id)) {
                return json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            }
        }
        self::fail('Composition metadata was not stored.');
    }

    public function testOnlyDormantChainStepsRetainPayloadAndTerminalMetadataHasNone(): void
    {
        $handle = $this->queue->chain([
            new RedisCompositionProbeJob(1), new RedisCompositionProbeJob(2),
            new RedisCompositionProbeJob(3),
        ]);
        $items = $this->metadata($handle->id)['items'];
        self::assertArrayNotHasKey('payload', $items[0]);
        self::assertArrayHasKey('payload', $items[1]);
        self::assertArrayHasKey('payload', $items[2]);

        $worker = new Worker($this->queue);
        self::assertTrue($worker->workOnce());
        $items = $this->metadata($handle->id)['items'];
        self::assertArrayNotHasKey('payload', $items[0]);
        self::assertArrayNotHasKey('payload', $items[1]);
        self::assertArrayHasKey('payload', $items[2]);

        self::assertTrue($worker->workOnce());
        self::assertTrue($worker->workOnce());
        $metadata = $this->metadata($handle->id);
        self::assertSame('completed', $metadata['state']);
        foreach ($metadata['items'] as $item) {
            self::assertArrayNotHasKey('payload', $item);
        }
        self::assertStringNotContainsString('REDIS_COMPOSITION_SECRET_DO_NOT_LEAK',
            json_encode($metadata, JSON_THROW_ON_ERROR));
    }

    public function testRedisAcceptsConfiguredOneMiBCombinedPayloadLimit(): void
    {
        $job = new RedisCompositionProbeJob(1, false, str_repeat('x', 54000));
        $handle = $this->queue->chain(array_fill(0, 19, $job));
        self::assertSame(19, $handle->status()->total);
    }

    public function testChainPublishesOnlyNextStepOnOwnedAcknowledgement(): void
    {
        $handle = $this->queue->chain([
            new RedisCompositionProbeJob(1), new RedisCompositionProbeJob(2),
            new RedisCompositionProbeJob(3),
        ]);
        self::assertSame(['active', 0, 0, 0, 3, 0], $this->counts($handle->status()));
        $first = $this->reserved();
        self::assertSame(0, $first->compositionPosition);
        self::assertNull($this->driver()->reserve('default'));
        self::assertTrue($this->driver()->shouldRunComposition($first));
        $this->driver()->acknowledge($first);
        self::assertSame(['active', 1, 0, 0, 2, 33], $this->counts($handle->status()));
        try {
            $this->driver()->acknowledge($first);
            self::fail('Replayed acknowledgement advanced a chain.');
        } catch (QueueException $exception) {
            self::assertStringContainsString('no longer owned', $exception->getMessage());
        }
        $second = $this->reserved();
        self::assertSame(1, $second->compositionPosition);
        $this->driver()->release($second, 2);
        $this->client->now += 2;
        $retry = $this->reserved();
        self::assertSame(2, $retry->attempts);
        $this->driver()->acknowledge($retry);
        $third = $this->reserved();
        self::assertSame(2, $third->compositionPosition);
        $this->driver()->acknowledge($third);
        self::assertSame(['completed', 3, 0, 0, 0, 100], $this->counts($handle->status()));
        self::assertNull($this->driver()->reserve('default'));
        $this->expectException(QueueException::class);
        $this->driver()->acknowledge($second);
    }

    public function testBatchContinuesAfterFailureAndFailedRetryIsStandalone(): void
    {
        $handle = $this->queue->batch([
            new RedisCompositionProbeJob(1),
            new RedisCompositionProbeJob(2, true),
            new RedisCompositionProbeJob(3),
        ]);
        $worker = new Worker($this->queue);
        self::assertTrue($worker->workOnce(tries: 1));
        self::assertTrue($worker->workOnce(tries: 1));
        self::assertTrue($worker->workOnce(tries: 1));
        self::assertSame([1, 2, 3], RedisCompositionProbeJob::$handled);
        self::assertSame(['completed_with_failures', 2, 1, 0, 0, 100],
            $this->counts($handle->status()));
        $failure = $this->driver()->failed();
        self::assertCount(1, $failure);
        self::assertTrue($this->driver()->retry($failure[0]['id']));
        $retried = $this->reserved();
        self::assertNull($retried->compositionId);
        self::assertNull($retried->compositionPosition);
        $this->driver()->acknowledge($retried);
        self::assertSame(['completed_with_failures', 2, 1, 0, 0, 100],
            $this->counts($handle->status()));
    }

    public function testCancellationSkipsReservedItemAndRemovesUnreservedBatchWork(): void
    {
        $handle = $this->queue->batch([
            new RedisCompositionProbeJob(1), new RedisCompositionProbeJob(2),
            new RedisCompositionProbeJob(3),
        ]);
        $reserved = $this->reserved();
        self::assertTrue($handle->cancel());
        self::assertSame(['cancelling', 0, 0, 2, 1, 66], $this->counts($handle->status()));
        self::assertFalse($this->driver()->shouldRunComposition($reserved));
        $this->driver()->skipComposition($reserved);
        self::assertSame(['cancelled', 0, 0, 3, 0, 100], $this->counts($handle->status()));
        self::assertFalse($handle->cancel());
        self::assertNull($this->driver()->reserve('default'));
        self::assertSame([], RedisCompositionProbeJob::$handled);
        foreach ($this->metadata($handle->id)['items'] as $item) {
            self::assertArrayNotHasKey('payload', $item);
        }
    }

    public function testSuccessfulHandlerCanSettleAfterCancellationWithoutChainAdvance(): void
    {
        $handle = $this->queue->chain([
            new RedisCompositionProbeJob(1), new RedisCompositionProbeJob(2),
        ]);
        $first = $this->reserved();
        self::assertTrue($this->driver()->shouldRunComposition($first));
        self::assertTrue($handle->cancel());
        $this->driver()->acknowledge($first);
        self::assertSame(['cancelled', 1, 0, 1, 0, 100], $this->counts($handle->status()));
        self::assertNull($this->driver()->reserve('default'));
    }

    public function testPermanentChainFailureCancelsRemainingSteps(): void
    {
        $handle = $this->queue->chain([
            new RedisCompositionProbeJob(1, true), new RedisCompositionProbeJob(2),
            new RedisCompositionProbeJob(3),
        ]);
        self::assertTrue((new Worker($this->queue))->workOnce(tries: 1));
        self::assertSame(['failed', 0, 1, 2, 0, 100], $this->counts($handle->status()));
        self::assertNull($this->driver()->reserve('default'));
        self::assertSame([1], RedisCompositionProbeJob::$handled);
        foreach ($this->metadata($handle->id)['items'] as $item) {
            self::assertArrayNotHasKey('payload', $item);
        }
    }

    public function testMetadataIsNamespaceIsolatedAndExpiresOnlyAfterTerminalState(): void
    {
        $handle = $this->queue->chain([new RedisCompositionProbeJob(1)]);
        $other = $this->manager('composition-two');
        self::assertNull($other->compositionStatus($handle->id, 'redis'));
        $this->client->now += 168 * 3600 + 1;
        self::assertSame('active', $handle->status()->state);
        self::assertSame(0, $this->queue->pruneCompositions(1, 'redis'));
        $this->driver()->acknowledge($this->reserved());
        self::assertSame('completed', $handle->status()->state);
        $this->client->now += 168 * 3600 + 1;
        self::assertNull($this->queue->compositionStatus($handle->id, 'redis'));
        self::assertSame(1, $this->queue->pruneCompositions(1, 'redis'));
    }

    public function testReleaseAfterCancellationIsSkippedOnNextClaimAndStatusHidesPayload(): void
    {
        $handle = $this->queue->batch([
            new RedisCompositionProbeJob(1), new RedisCompositionProbeJob(2),
        ]);
        $reserved = $this->reserved();
        self::assertTrue($handle->cancel());
        $this->driver()->release($reserved, 10);
        self::assertSame(['cancelling', 0, 0, 1, 1, 50], $this->counts($handle->status()));
        self::assertNull($this->driver()->reserve('default'));
        $this->client->now += 10;
        self::assertTrue((new Worker($this->queue))->workOnce());
        self::assertSame(['cancelled', 0, 0, 2, 0, 100], $this->counts($handle->status()));
        self::assertNull($this->driver()->reserve('default'));
        self::assertStringNotContainsString('payload',
            json_encode($handle->status(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('RedisCompositionProbeJob',
            json_encode($handle->status(), JSON_THROW_ON_ERROR));
    }
}
