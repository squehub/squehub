<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Plugins\Redis as PluginRedis;
use App\Redis\Redis as RedisGateway;
use App\Redis\RedisManager;
use App\Redis\RedisServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Bootstrap and Plugins must remain usable on hosts with no Redis client. */
final class RedisApplicationTest extends TestCase
{
    public function testOptionalProviderBootsWithoutClientOrNetworkAndSharesManager(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Redis.php', '<?php return ["default" => "main", "client" => "phpredis", "connections" => ["main" => ["host" => null]]];');
            $app = new Application($project->path());
            $app->register(RedisServiceProvider::class);
            $app->bootstrap();
            $manager = $app->container()->make(RedisManager::class);
            self::assertSame($manager, redis());
            self::assertSame($manager, PluginRedis::manager());
            self::assertSame($manager->connection(), PluginRedis::connection());
            self::assertSame('not_configured', PluginRedis::capability()['status']);
            self::assertFalse(PluginRedis::available());
            self::assertSame([], glob($project->path('Storage/*')) ?: []);
        } finally {
            RedisGateway::setResolver(null);
            $project->remove();
        }
    }

    public function testConfiguredButUnreachableEndpointDoesNotAffectBoot(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Redis.php', '<?php return ["default" => "main", "client" => "auto", "connections" => ["main" => ["host" => "127.0.0.1", "port" => 1, "connect_timeout" => 0.1]]];');
            $app = new Application($project->path());
            $app->register(RedisServiceProvider::class);
            $app->bootstrap();
            self::assertTrue($app->isBooted());
            self::assertSame($app->container()->make(RedisManager::class), PluginRedis::manager());
            self::assertContains(PluginRedis::capability()['status'], ['not_probed', 'client_unavailable']);
            self::assertSame([], glob($project->path('Storage/*')) ?: []);
        } finally {
            RedisGateway::setResolver(null);
            $project->remove();
        }
    }
}
