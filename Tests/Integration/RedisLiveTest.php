<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Redis\RedisManager;
use PHPUnit\Framework\TestCase;

/** Live Redis is opt-in and uses one random prefix; it never flushes a database. */
final class RedisLiveTest extends TestCase
{
    public function testRealRedisCommandsWithDisposablePrefix(): void
    {
        $url = getenv('SQUEHUB_TEST_REDIS_URL');
        if (!is_string($url) || $url === '' || (!class_exists(\Redis::class)
            && !class_exists('Predis\\Client'))) {
            self::markTestSkipped('Live Redis requires SQUEHUB_TEST_REDIS_URL and an installed client.');
        }
        $prefix = 'squehub-test-' . bin2hex(random_bytes(12)) . ':';
        $manager = new RedisManager(['default' => 'main', 'client' => 'auto',
            'connections' => ['main' => ['url' => $url, 'prefix' => $prefix]]]);
        $connection = $manager->connection();
        self::assertSame('available', $manager->capability(probe: true)['status']);
        try {
            self::assertTrue($connection->set('binary', "a\0b", ttl: 60));
            self::assertSame("a\0b", $connection->get('binary'));
            self::assertTrue($connection->exists('binary'));
            self::assertGreaterThan(0, $connection->ttl('binary'));
            self::assertFalse($connection->set('binary', 'other', onlyIfMissing: true));
            self::assertSame(1, $connection->increment('counter'));
            self::assertSame(0, $connection->decrement('counter'));
        } finally {
            $connection->delete('binary');
            $connection->delete('counter');
        }
    }
}
