<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Queue\Drivers\RedisQueueDriver;
use App\Queue\QueueException;
use App\Queue\QueueManager;
use App\Redis\RedisManager;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\RedisQueueLiveJob;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/RedisQueueLiveJob.php';

/**
 * Opt-in Redis execution checks actual Lua and two independent PHP workers.
 * The random Redis connection prefix confines cleanup to this test's keys.
 */
final class RedisQueueLiveTest extends TestCase
{
    /** @dataProvider clients */
    public function testAtomicClaimStaleRecoveryAndProcessBoundary(string $client): void
    {
        $url = getenv('SQUEHUB_TEST_REDIS_URL');
        if (!is_string($url) || $url === '' || ($client === 'phpredis'
            ? !class_exists(\Redis::class) : !class_exists('Predis\\Client'))) {
            self::markTestSkipped('Live Redis requires SQUEHUB_TEST_REDIS_URL and the selected client.');
        }
        $prefix = 'squehub-11c-' . bin2hex(random_bytes(12)) . ':';
        $namespace = 'live-11c';
        $manager = new RedisManager(['default' => 'main', 'client' => $client,
            'connections' => ['main' => ['url' => $url, 'prefix' => $prefix]]]);
        $connection = $manager->connection();
        $queue = new QueueManager(['default' => 'redis', 'connections' => [
            'redis' => ['driver' => 'redis', 'namespace' => $namespace, 'retry_after' => 1],
        ]], null, null, null, $manager);
        $driver = $queue->driver();
        self::assertInstanceOf(RedisQueueDriver::class, $driver);
        $path = tempnam(sys_get_temp_dir(), 'squehub-redis-queue-');
        self::assertIsString($path);
        $first = null;
        $second = null;
        $workerCode = <<<'PHP'
require 'vendor/autoload.php';
require 'Tests/Fixtures/RedisQueueLiveJob.php';
$redis = new App\Redis\RedisManager(['default' => 'main',
    'client' => getenv('SQUEHUB_TEST_REDIS_CLIENT'),
    'connections' => ['main' => ['url' => getenv('SQUEHUB_TEST_REDIS_URL'),
        'prefix' => getenv('SQUEHUB_TEST_REDIS_PREFIX')]]]);
$queue = new App\Queue\QueueManager(['default' => 'redis', 'connections' => [
    'redis' => ['driver' => 'redis', 'namespace' => 'live-11c', 'retry_after' => 1],
]], null, null, null, $redis);
file_put_contents(getenv('SQUEHUB_TEST_READY'), 'ready');
$deadline = microtime(true) + 10;
while (!is_file(getenv('SQUEHUB_TEST_GO'))) {
    if (microtime(true) >= $deadline) exit(2);
    usleep(10000);
}
echo (new App\Queue\Worker($queue))->workOnce() ? '1' : '0';
PHP;
        try {
            self::assertSame('available', $manager->capability(probe: true)['status']);
            $queue->dispatch(new RedisQueueLiveJob($path));
            $readyA = $path . '.ready-a';
            $readyB = $path . '.ready-b';
            $go = $path . '.go';
            $env = ['SQUEHUB_TEST_REDIS_URL' => $url,
                'SQUEHUB_TEST_REDIS_CLIENT' => $client,
                'SQUEHUB_TEST_REDIS_PREFIX' => $prefix,
                'SQUEHUB_TEST_GO' => $go];
            $first = new Process([PHP_BINARY, '-r', $workerCode], dirname(__DIR__, 2),
                $env + ['SQUEHUB_TEST_READY' => $readyA]);
            $second = new Process([PHP_BINARY, '-r', $workerCode], dirname(__DIR__, 2),
                $env + ['SQUEHUB_TEST_READY' => $readyB]);
            $first->start();
            $second->start();
            // Both child processes announce readiness before the parent opens
            // the gate, so the claim test does not rely on launch timing.
            $deadline = microtime(true) + 10;
            while (!is_file($readyA) || !is_file($readyB)) {
                if (microtime(true) >= $deadline || !$first->isRunning() || !$second->isRunning()) {
                    self::fail('Redis Queue claim workers did not reach the synchronization gate.');
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
            self::assertSame("handled\n", file_get_contents($path));

            $queue->dispatch(new RedisQueueLiveJob($path));
            $stale = $driver->reserve('default');
            self::assertNotNull($stale);
            usleep(1_200_000);
            $reclaimed = $driver->reserve('default');
            self::assertNotNull($reclaimed);
            self::assertSame(2, $reclaimed->attempts);
            try {
                $driver->acknowledge($stale);
                self::fail('Expired reservation settled a reclaimed job.');
            } catch (QueueException) {}
            $driver->acknowledge($reclaimed);
        } finally {
            if ($first?->isRunning()) $first->stop(0);
            if ($second?->isRunning()) $second->stop(0);
            foreach ($connection->scanPrefix('queue:') as $key) $connection->delete($key);
            @unlink($path);
            @unlink($path . '.ready-a');
            @unlink($path . '.ready-b');
            @unlink($path . '.go');
        }
    }

    /** @return array<string,array{string}> */
    public static function clients(): array
    {
        return ['PhpRedis' => ['phpredis'], 'Predis' => ['predis']];
    }
}
