<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Queue\Drivers\RedisQueueDriver;
use App\Queue\QueueManager;
use App\Redis\RedisManager;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\RedisQueueLiveJob;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/RedisQueueLiveJob.php';

/**
 * Guarded live Redis qualification of composition Lua and process persistence.
 * Its random Redis prefix confines both worker state and cleanup to this run.
 */
final class RedisCompositionLiveTest extends TestCase
{
    /** @dataProvider clients */
    public function testChainAdvancesAcrossProcessesAndCancellationSettles(string $client): void
    {
        $url = getenv('SQUEHUB_TEST_REDIS_URL');
        if (!is_string($url) || $url === '' || ($client === 'phpredis'
            ? !class_exists(\Redis::class) : !class_exists('Predis\\Client'))) {
            self::markTestSkipped('Live Redis requires SQUEHUB_TEST_REDIS_URL and the selected client.');
        }
        $prefix = 'squehub-composition-' . bin2hex(random_bytes(12)) . ':';
        $namespace = 'composition-live';
        $redis = new RedisManager(['default' => 'main', 'client' => $client,
            'connections' => ['main' => ['url' => $url, 'prefix' => $prefix]]]);
        $connection = $redis->connection();
        $queue = new QueueManager(['default' => 'redis', 'connections' => [
            'redis' => ['driver' => 'redis', 'namespace' => $namespace,
                'retry_after' => 5],
        ]], null, null, null, $redis);
        $driver = $queue->driver();
        self::assertInstanceOf(RedisQueueDriver::class, $driver);
        $path = tempnam(sys_get_temp_dir(), 'squehub-composition-');
        self::assertIsString($path);
        $first = null;
        $second = null;
        $readyA = $path . '.ready-a';
        $readyB = $path . '.ready-b';
        $go = $path . '.go';
        $worker = <<<'PHP'
require 'vendor/autoload.php';
require 'Tests/Fixtures/RedisQueueLiveJob.php';
$redis = new App\Redis\RedisManager(['default' => 'main',
    'client' => getenv('SQUEHUB_TEST_REDIS_CLIENT'),
    'connections' => ['main' => ['url' => getenv('SQUEHUB_TEST_REDIS_URL'),
        'prefix' => getenv('SQUEHUB_TEST_REDIS_PREFIX')]]]);
$queue = new App\Queue\QueueManager(['default' => 'redis', 'connections' => [
    'redis' => ['driver' => 'redis', 'namespace' => 'composition-live',
        'retry_after' => 5],
]], null, null, null, $redis);
$ready = getenv('SQUEHUB_TEST_READY');
$go = getenv('SQUEHUB_TEST_GO');
if (is_string($ready) && $ready !== '' && is_string($go) && $go !== '') {
    file_put_contents($ready, 'ready');
    $deadline = microtime(true) + 10;
    while (!is_file($go)) {
        if (microtime(true) >= $deadline) exit(2);
        usleep(10000);
    }
}
echo (new App\Queue\Worker($queue))->workOnce() ? '1' : '0';
PHP;
        try {
            self::assertSame('available', $redis->capability(probe: true)['status']);
            $chain = $queue->chain([new RedisQueueLiveJob($path),
                new RedisQueueLiveJob($path)]);
            self::assertSame('active', $chain->status()->state);
            foreach ([1, 2] as $step) {
                $process = new Process([PHP_BINARY, '-r', $worker], dirname(__DIR__, 2), [
                    'SQUEHUB_TEST_REDIS_URL' => $url,
                    'SQUEHUB_TEST_REDIS_CLIENT' => $client,
                    'SQUEHUB_TEST_REDIS_PREFIX' => $prefix,
                ]);
                $process->mustRun();
                self::assertSame('1', trim($process->getOutput()), 'Worker did not claim step ' . $step);
                self::assertSame($step, substr_count((string) file_get_contents($path), "handled\n"));
            }
            self::assertSame('completed', $chain->status()->state);
            self::assertSame(2, $chain->status()->succeeded);

            $batch = $queue->batch([new RedisQueueLiveJob($path),
                new RedisQueueLiveJob($path)]);
            $reserved = $driver->reserve('default');
            self::assertNotNull($reserved);
            self::assertTrue($batch->cancel());
            self::assertFalse($driver->shouldRunComposition($reserved));
            $driver->skipComposition($reserved);
            self::assertSame('cancelled', $batch->status()->state);
            self::assertSame(2, $batch->status()->cancelled);
            self::assertSame(2, substr_count((string) file_get_contents($path), "handled\n"));

            $failedChain = $queue->chain([new RedisQueueLiveJob($path),
                new RedisQueueLiveJob($path)]);
            $failedReservation = $driver->reserve('default');
            self::assertNotNull($failedReservation);
            $driver->fail($failedReservation, RedisQueueLiveJob::class,
                'RuntimeException', 'Safe test failure.');
            self::assertSame('failed', $failedChain->status()->state);
            self::assertSame(1, $failedChain->status()->failed);
            self::assertSame(1, $failedChain->status()->cancelled);
            $failed = $driver->failed();
            self::assertCount(1, $failed);
            self::assertTrue($driver->retry($failed[0]['id']));
            $standalone = $driver->reserve('default');
            self::assertNotNull($standalone);
            self::assertNull($standalone->compositionId);
            $driver->acknowledge($standalone);
            self::assertSame('failed', $failedChain->status()->state);

            // A synchronized pair of independent workers contests one batch
            // item. Redis must let exactly one worker own and settle it.
            $race = $queue->batch([new RedisQueueLiveJob($path)]);
            $env = ['SQUEHUB_TEST_REDIS_URL' => $url,
                'SQUEHUB_TEST_REDIS_CLIENT' => $client,
                'SQUEHUB_TEST_REDIS_PREFIX' => $prefix,
                'SQUEHUB_TEST_GO' => $go];
            $first = new Process([PHP_BINARY, '-r', $worker], dirname(__DIR__, 2),
                $env + ['SQUEHUB_TEST_READY' => $readyA]);
            $second = new Process([PHP_BINARY, '-r', $worker], dirname(__DIR__, 2),
                $env + ['SQUEHUB_TEST_READY' => $readyB]);
            $first->setTimeout(15);
            $second->setTimeout(15);
            $first->start();
            $second->start();
            $deadline = microtime(true) + 10;
            while (!is_file($readyA) || !is_file($readyB)) {
                if (microtime(true) >= $deadline || !$first->isRunning() || !$second->isRunning()) {
                    self::fail('Redis composition workers did not reach the synchronization gate.');
                }
                usleep(10000);
            }
            file_put_contents($go, 'go');
            $first->wait();
            $second->wait();
            self::assertTrue($first->isSuccessful() && $second->isSuccessful());
            $outcomes = [$first->getOutput(), $second->getOutput()];
            sort($outcomes);
            self::assertSame(['0', '1'], $outcomes);
            self::assertSame('completed', $race->status()->state);
            self::assertSame(1, $race->status()->succeeded);
            self::assertSame(3, substr_count((string) file_get_contents($path), "handled\n"));
        } finally {
            if ($first?->isRunning()) $first->stop(0);
            if ($second?->isRunning()) $second->stop(0);
            // Only the random connection prefix is enumerated. Other Redis
            // applications and tests retain their keys.
            foreach ($connection->scanPrefix('queue:') as $key) {
                $connection->delete($key);
            }
            @unlink($path);
            @unlink($readyA);
            @unlink($readyB);
            @unlink($go);
        }
    }

    public static function clients(): array
    {
        return [['phpredis'], ['predis']];
    }
}
