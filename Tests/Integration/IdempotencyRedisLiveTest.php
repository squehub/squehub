<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Idempotency\ResponseSnapshot;
use App\Idempotency\Stores\RedisIdempotencyStore;
use App\Redis\RedisManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** @group redis-live */
final class IdempotencyRedisLiveTest extends TestCase
{
    public function testAtomicClaimCompletionAndReplayAcrossProcesses(): void
    {
        $url = getenv('SQUEHUB_TEST_REDIS_URL');
        if (!is_string($url) || $url === '' || (!class_exists(\Redis::class)
            && !class_exists('Predis\\Client'))) {
            self::markTestSkipped('Live Redis requires SQUEHUB_TEST_REDIS_URL and an installed client.');
        }
        $client = getenv('SQUEHUB_TEST_REDIS_CLIENT') ?: 'auto';
        $prefix = 'squehub-phase24-idempotency-' . bin2hex(random_bytes(12)) . ':';
        $redis = new RedisManager(['default' => 'main', 'client' => $client,
            'connections' => ['main' => ['url' => $url, 'prefix' => $prefix]]]);
        $connection = $redis->connection();
        $store = new RedisIdempotencyStore($connection, 'phase24-live');
        $scope = hash('sha256', 'one-user-one-route-one-key');
        $fingerprint = hash('sha256', 'body-one');
        $other = hash('sha256', 'body-two');
        $owner = bin2hex(random_bytes(32));
        try {
            self::assertSame('available', $redis->capability(probe: true)['status']);
            self::assertSame('claimed', $store->claim($scope, $fingerprint, $owner,
                time(), 30, 60)->state);
            self::assertSame('in_progress', $this->childClaim($url, $client, $prefix,
                $scope, $fingerprint));
            self::assertSame('conflict', $this->childClaim($url, $client, $prefix,
                $scope, $other));
            self::assertFalse($store->complete($scope, str_repeat('0', 64), time(), null));
            self::assertTrue($store->complete($scope, $owner, time(),
                new ResponseSnapshot(201, 'finished', 'text/plain')));
            self::assertSame('replay:finished', $this->childClaim($url, $client, $prefix,
                $scope, $fingerprint));
            self::assertSame('conflict', $this->childClaim($url, $client, $prefix,
                $scope, $other));
        } finally {
            foreach ($connection->scanPrefix('idempotency:') as $key) $connection->delete($key);
            self::assertSame([], iterator_to_array($connection->scanPrefix('idempotency:')));
        }
    }

    private function childClaim(string $url, string $client, string $prefix,
        string $scope, string $fingerprint): string
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$redis = new App\Redis\RedisManager(['default' => 'main',
    'client' => getenv('SQUEHUB_TEST_REDIS_CLIENT'),
    'connections' => ['main' => ['url' => getenv('SQUEHUB_TEST_REDIS_URL'),
        'prefix' => getenv('SQUEHUB_TEST_REDIS_PREFIX')]]]);
$store = new App\Idempotency\Stores\RedisIdempotencyStore($redis->connection(), 'phase24-live');
$result = $store->claim(getenv('SQUEHUB_TEST_SCOPE'), getenv('SQUEHUB_TEST_FINGERPRINT'),
    bin2hex(random_bytes(32)), time(), 30, 60);
echo $result->state;
if ($result->snapshot !== null) echo ':' . $result->snapshot->body;
PHP;
        $process = new Process([PHP_BINARY, '-r', $code], dirname(__DIR__, 2), [
            'SQUEHUB_TEST_REDIS_URL' => $url,
            'SQUEHUB_TEST_REDIS_CLIENT' => $client,
            'SQUEHUB_TEST_REDIS_PREFIX' => $prefix,
            'SQUEHUB_TEST_SCOPE' => $scope,
            'SQUEHUB_TEST_FINGERPRINT' => $fingerprint,
        ]);
        $process->run();
        self::assertTrue($process->isSuccessful(), 'Live Redis idempotency child failed.');
        return trim($process->getOutput());
    }
}
