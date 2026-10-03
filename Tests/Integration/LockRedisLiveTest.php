<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Database\SystemModelClock;
use App\Locks\LockManager;
use App\Locks\LockOwnershipException;
use App\Locks\Stores\RedisLockStore;
use App\Redis\RedisManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** @group redis-live */
final class LockRedisLiveTest extends TestCase
{
    public function testAtomicRedisOwnershipAcrossPhpProcessesWithExactPrefixCleanup(): void
    {
        $url = getenv('SQUEHUB_TEST_REDIS_URL');
        if (!is_string($url) || $url === '' || (!class_exists(\Redis::class)
            && !class_exists('Predis\\Client'))) {
            self::markTestSkipped('Live Redis requires SQUEHUB_TEST_REDIS_URL and an installed client.');
        }
        $client = getenv('SQUEHUB_TEST_REDIS_CLIENT') ?: 'auto';
        $prefix = 'squehub-phase24-lock-' . bin2hex(random_bytes(12)) . ':';
        $redis = new RedisManager(['default' => 'main', 'client' => $client,
            'connections' => ['main' => ['url' => $url, 'prefix' => $prefix]]]);
        $connection = $redis->connection();
        $manager = new LockManager(new RedisLockStore($connection), 'phase24-live',
            new SystemModelClock(), 'redis', true);
        try {
            self::assertSame('available', $redis->capability(probe: true)['status']);
            $held = $manager->acquire('shared', 30);
            self::assertTrue($held->acquired());
            self::assertSame('busy', $this->childAttempt($url, $client, $prefix));
            $hash = hash('sha256', "squehub-lock-v1\0phase24-live\0shared");
            try {
                (new RedisLockStore($connection))->release($hash, str_repeat('0', 64), time());
                self::fail('Wrong owner release must be rejected.');
            } catch (LockOwnershipException) {
                self::assertFalse($manager->acquire('shared')->acquired());
            }
            self::assertTrue($held->release());
            self::assertSame('acquired', $this->childAttempt($url, $client, $prefix));
        } finally {
            // The connection prefix is random and owned by this test. SCAN is
            // incremental; no server-wide FLUSH command is permitted.
            foreach ($connection->scanPrefix('locks:') as $key) $connection->delete($key);
            self::assertSame([], iterator_to_array($connection->scanPrefix('locks:')));
        }
    }

    private function childAttempt(string $url, string $client, string $prefix): string
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$redis = new App\Redis\RedisManager(['default' => 'main',
    'client' => getenv('SQUEHUB_TEST_REDIS_CLIENT'),
    'connections' => ['main' => ['url' => getenv('SQUEHUB_TEST_REDIS_URL'),
        'prefix' => getenv('SQUEHUB_TEST_REDIS_PREFIX')]]]);
$manager = new App\Locks\LockManager(new App\Locks\Stores\RedisLockStore($redis->connection()),
    'phase24-live', new App\Database\SystemModelClock(), 'redis', true);
$handle = $manager->acquire('shared', 30);
echo $handle->acquired() ? 'acquired' : 'busy';
if ($handle->acquired()) $handle->release();
PHP;
        $process = new Process([PHP_BINARY, '-r', $code], dirname(__DIR__, 2), [
            'SQUEHUB_TEST_REDIS_URL' => $url,
            'SQUEHUB_TEST_REDIS_CLIENT' => $client,
            'SQUEHUB_TEST_REDIS_PREFIX' => $prefix,
        ]);
        $process->run();
        self::assertTrue($process->isSuccessful(), 'Live Redis lock child failed.');
        return trim($process->getOutput());
    }
}
