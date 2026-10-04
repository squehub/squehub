<?php

declare(strict_types=1);

namespace App\Redis;

use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;

/** Validate Redis syntax at boot without selecting a client or opening a socket. */
final class RedisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->container()->singleton(RedisManager::class,
            static function (Container $container) use ($app): RedisManager {
                $settings = $app->config()->get('redis', []);
                if (!is_array($settings)) {
                    throw new RedisConfigurationException('Redis configuration must be a map.');
                }
                $diagnostics = $container->has(Diagnostics::class)
                    ? $container->make(Diagnostics::class) : null;
                return new RedisManager($settings, $diagnostics);
            });
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $container->make(RedisManager::class);
        Redis::setResolver(static fn (): RedisManager => $container->make(RedisManager::class));
    }
}
