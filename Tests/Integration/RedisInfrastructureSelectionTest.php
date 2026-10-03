<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Cache\CacheServiceProvider;
use App\Cache\Cache;
use App\Cache\CacheStore;
use App\Foundation\Application;
use App\RateLimit\RateLimitServiceProvider;
use App\RateLimit\RateLimit;
use App\RateLimit\RateLimiter;
use App\Redis\RedisServiceProvider;
use App\Redis\RedisManager;
use App\Redis\RedisClient;
use App\Redis\Redis;
use App\Session\SessionManager;
use App\Session\SessionServiceProvider;
use App\Session\Session;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use SqueHub\Tests\Unit\InfraRedisClient;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__) . '/Unit/RedisInfrastructureTest.php';

/** Shared-hosting selection never needs a Redis client or socket at bootstrap. */
final class RedisInfrastructureSelectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::setResolver(null);
        Session::setResolver(null);
        RateLimit::setResolver(null);
        Redis::setResolver(null);
    }

    public function testAutoFallsBackToPersistentLocalDriversWhenRedisIsNotConfigured(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Redis.php', '<?php return ["default" => "main", "connections" => ["main" => []]];');
            $project->write('Config/Cache.php', '<?php return ["driver" => "auto"];');
            $project->write('Config/Session.php', '<?php return ["driver" => "auto"];');
            $project->write('Config/RateLimit.php', '<?php return ["driver" => "auto"];');
            $app = new Application($project->path());
            foreach ([CacheServiceProvider::class, SessionServiceProvider::class,
                RateLimitServiceProvider::class, RedisServiceProvider::class] as $provider) {
                $app->register($provider);
            }
            $app->bootstrap();
            self::assertDirectoryDoesNotExist($project->path('Storage/Cache'));
            self::assertDirectoryDoesNotExist($project->path('Storage/RateLimits'));
            $cache = $app->container()->make(CacheStore::class);
            $session = $app->container()->make(SessionManager::class);
            $limiter = $app->container()->make(RateLimiter::class);
            self::assertSame('file', $cache->infrastructure()?->selected());
            $session->store();
            self::assertSame('native', $session->infrastructure()?->selected());
            self::assertSame('file', $limiter->infrastructure()?->selected());
            self::assertSame('not_configured', $cache->infrastructure()?->reason());
            $cache->store('works', 'on-shared-hosting');
            self::assertSame('on-shared-hosting', $cache->read('works'));
        } finally {
            $project->remove();
        }
    }

    public function testExplicitRedisSelectionNeverSilentlyFallsBack(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Redis.php', '<?php return ["default" => "main", "connections" => ["main" => []]];');
            $project->write('Config/Cache.php', '<?php return ["driver" => "redis"];');
            $app = new Application($project->path());
            $app->register(CacheServiceProvider::class);
            $app->register(RedisServiceProvider::class);
            $app->bootstrap();
            $cache = $app->container()->make(CacheStore::class);
            self::assertSame('redis', $cache->infrastructure()?->selected());
            self::assertDirectoryDoesNotExist($project->path('Storage/Cache'));
            $this->expectException(\App\Cache\CacheException::class);
            $cache->store('key', 'value');
        } finally {
            $project->remove();
        }
    }

    public function testAutoSelectsNamedRedisConnectionOnceAndOutageDoesNotSwitchBackend(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Cache.php', '<?php return ["driver" => "auto", "redis_connection" => "shared"];');
            $project->write('Config/Session.php', '<?php return ["driver" => "auto", "redis_connection" => "shared"];');
            $project->write('Config/RateLimit.php', '<?php return ["driver" => "auto", "redis_connection" => "shared"];');
            $app = new Application($project->path());
            foreach ([CacheServiceProvider::class, SessionServiceProvider::class,
                RateLimitServiceProvider::class] as $provider) $app->register($provider);
            $app->bootstrap();
            $client = new InfraRedisClient();
            $manager = new RedisManager(['default' => 'main', 'client' => 'auto',
                'connections' => ['main' => ['host' => 'localhost', 'prefix' => 'main:'],
                    'shared' => ['host' => 'localhost', 'prefix' => 'shared:']]], null,
                static fn (): RedisClient => $client,
                static fn (string $class): bool => $class === \Redis::class);
            $app->container()->instance(RedisManager::class, $manager);
            self::assertSame([], $client->commands);
            $cache = $app->container()->make(CacheStore::class);
            $session = $app->container()->make(SessionManager::class);
            $session->store();
            $limiter = $app->container()->make(RateLimiter::class);
            self::assertSame('redis', $cache->infrastructure()?->selected());
            self::assertSame('redis', $session->infrastructure()?->selected());
            self::assertSame('redis', $limiter->infrastructure()?->selected());
            $cache->store('one', 'value');
            self::assertStringStartsWith('shared:cache:', array_key_first($client->data));
            $client->fail = true;
            try {
                $cache->read('one');
                self::fail('A selected Redis backend must not switch to file after an outage.');
            } catch (\App\Cache\CacheException) {
                self::assertSame('redis', $cache->infrastructure()?->selected());
            }
            self::assertDirectoryDoesNotExist($project->path('Storage/Cache'));
        } finally {
            $project->remove();
        }
    }
}
