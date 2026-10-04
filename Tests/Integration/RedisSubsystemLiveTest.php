<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Cache\CacheStore;
use App\Cache\Drivers\RedisCacheDriver;
use App\Database\SystemModelClock;
use App\RateLimit\RateLimiter;
use App\RateLimit\Stores\RedisRateLimitStore;
use App\Redis\RedisManager;
use App\Session\Drivers\RedisSessionHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Live, opt-in state sharing; every record uses a unique test-owned prefix. */
final class RedisSubsystemLiveTest extends TestCase
{
    /** @dataProvider clients */
    public function testCacheSessionAndRateLimitAcrossProcesses(string $client): void
    {
        $url = getenv('SQUEHUB_TEST_REDIS_URL');
        if (!is_string($url) || $url === '' || ($client === 'phpredis'
            ? !class_exists(\Redis::class) : !class_exists('Predis\\Client'))) {
            self::markTestSkipped('Live Redis requires SQUEHUB_TEST_REDIS_URL and the selected client.');
        }
        $prefix = 'squehub-11b-' . bin2hex(random_bytes(12)) . ':';
        $manager = new RedisManager(['default' => 'main', 'client' => $client,
            'connections' => ['main' => ['url' => $url, 'prefix' => $prefix]]]);
        $connection = $manager->connection();
        $clock = new SystemModelClock();
        $namespace = 'live-11b';
        $cache = new CacheStore(new RedisCacheDriver($connection, $namespace, $clock), $namespace, $clock);
        $session = new RedisSessionHandler($connection, $namespace, 120);
        $limiter = new RateLimiter(new RedisRateLimitStore($connection, $namespace), $namespace);
        $sessionId = bin2hex(random_bytes(16));
        try {
            self::assertSame('available', $manager->capability(probe: true)['status']);
            $cache->store('shared', "binary\0value", 120);
            self::assertTrue($session->write($sessionId, 'private|s:5:"state";'));
            self::assertTrue($limiter->consume('distributed', 'subject@example.test', 1, 120)->allowed());
            $code = <<<'PHP'
require 'vendor/autoload.php';
$manager = new App\Redis\RedisManager(['default' => 'main',
    'client' => getenv('SQUEHUB_TEST_REDIS_CLIENT'),
    'connections' => ['main' => ['url' => getenv('SQUEHUB_TEST_REDIS_URL'),
        'prefix' => getenv('SQUEHUB_TEST_REDIS_PREFIX')]]]);
$connection = $manager->connection();
$clock = new App\Database\SystemModelClock();
$namespace = 'live-11b';
$cache = new App\Cache\CacheStore(new App\Cache\Drivers\RedisCacheDriver($connection, $namespace, $clock), $namespace, $clock);
$session = new App\Session\Drivers\RedisSessionHandler($connection, $namespace, 120);
$limiter = new App\RateLimit\RateLimiter(new App\RateLimit\Stores\RedisRateLimitStore($connection, $namespace), $namespace);
$sessionValue = $session->read(getenv('SQUEHUB_TEST_SESSION_ID'));
$session->close();
echo json_encode([$cache->read('shared'), $sessionValue,
    $limiter->consume('distributed', 'subject@example.test', 1, 120)->denied()]);
PHP;
            $process = new Process([PHP_BINARY, '-r', $code], dirname(__DIR__, 2), [
                'SQUEHUB_TEST_REDIS_URL' => $url,
                'SQUEHUB_TEST_REDIS_CLIENT' => $client,
                'SQUEHUB_TEST_REDIS_PREFIX' => $prefix,
                'SQUEHUB_TEST_SESSION_ID' => $sessionId,
            ]);
            $process->run();
            self::assertTrue($process->isSuccessful(), 'Live Redis subprocess failed.');
            self::assertSame(["binary\0value", 'private|s:5:"state";', true],
                json_decode($process->getOutput(), true));
            $cache->clear();
            self::assertTrue($session->validateId($sessionId));
            self::assertTrue($limiter->consume('distributed', 'subject@example.test', 1, 120)->denied());
        } finally {
            $cache->clear();
            $session->destroy($sessionId);
            $limiter->clear('distributed', 'subject@example.test');
        }
    }

    /** @return array<string,array{string}> */
    public static function clients(): array
    {
        return ['PhpRedis' => ['phpredis'], 'Predis' => ['predis']];
    }
}
